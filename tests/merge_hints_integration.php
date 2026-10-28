<?php declare(strict_types=1);
// Temporarily changes only a disposable test shop's search configuration.
use Doctrine\DBAL\Connection;
use ShopwareMeilisearch\Command\ImportSignalsCommand;
use ShopwareMeilisearch\Search\FeedbackConfig;
use Shopware\Core\Framework\Adapter\Kernel\KernelFactory;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\Console\Tester\CommandTester;

$url=parse_url(getenv('DATABASE_URL')?:'');
if (!is_array($url) || !preg_match('/^\/el_search_test_[a-f0-9]+$/D',$url['path']??'')) throw new RuntimeException('Isolated database required.');
foreach (['PROJECT_ROOT','DATABASE_URL','APP_URL','SHOPWARE_MEILISEARCH_URL','SHOPWARE_MEILISEARCH_KEY'] as $name) if (getenv($name)!==false) $_SERVER[$name]=$_ENV[$name]=getenv($name);
$root=getenv('PROJECT_ROOT')?:'';
if(!is_file($root.'/vendor/autoload.php')) throw new RuntimeException('Isolated project root required.');
$loader=require $root.'/vendor/autoload.php';$kernel=KernelFactory::create('dev',true,$loader);$kernel->boot();$container=$kernel->getContainer();$db=$container->get(Connection::class);$config=$container->get(SystemConfigService::class);
if ((int)$db->fetchOne('SELECT COUNT(*) FROM app WHERE active=1') || (int)$db->fetchOne('SELECT COUNT(*) FROM webhook WHERE active=1')) throw new RuntimeException('Apps and webhooks must be disabled.');
$channel=$db->fetchOne('SELECT LOWER(HEX(sales_channel_id)) FROM sales_channel_domain LIMIT 1');
if(!is_string($channel)) throw new RuntimeException('Test sales channel required.');
$key='ShopwareMeilisearch.config.queryHints';
$readRecord=static function(?string $channel) use($db,$key): ?string {
 $row=$channel===null ? $db->fetchAssociative('SELECT configuration_value FROM system_config WHERE configuration_key=:key AND sales_channel_id IS NULL',['key'=>$key]) : $db->fetchAssociative('SELECT configuration_value FROM system_config WHERE configuration_key=:key AND sales_channel_id=UNHEX(:channel)',['key'=>$key,'channel'=>$channel]);
 if($row===false)return null;$value=json_decode($row['configuration_value'],true,512,JSON_THROW_ON_ERROR)['_value']??null;
 if(!is_string($value))throw new RuntimeException('Unexpected existing configuration.');return $value;
};
$restore=static function(?string $value,?string $channel) use($config,$key):void {if($value===null)$config->delete($key,$channel);else $config->set($key,$value,$channel);};
$global=$readRecord(null);$specific=$readRecord($channel);$a=str_repeat('a',32);$b=str_repeat('b',32);$file=tempnam(sys_get_temp_dir(),'el-hints-');
if($file===false)throw new RuntimeException('Temporary file unavailable.');
try {
 $config->set($key,json_encode(['de-DE'=>[$a=>['one','two']], 'en-GB'=>[$a=>['english']]],JSON_THROW_ON_ERROR));
 $config->delete($key,$channel);
 file_put_contents($file,json_encode(['de-DE'=>[$a=>['two','three']], 'fr-FR'=>[$b=>['francais']]],JSON_THROW_ON_ERROR));
 $tester=new CommandTester(new ImportSignalsCommand($config));
 $status=$tester->execute(['--query-hints'=>$file,'--sales-channel'=>$channel,'--merge-query-hints'=>true]);
 if($status!==0)throw new RuntimeException('Merge command failed.');
 $merged=FeedbackConfig::parse($config->getString($key,$channel),'hints');
 if(($merged['de-DE'][$a]??null)!==['one','two','three'] || ($merged['en-GB'][$a]??null)!==['english'] || ($merged['fr-FR'][$b]??null)!==['francais'])throw new RuntimeException('Merged hints were lost or crossed language boundaries.');
 $globalAfter=FeedbackConfig::parse($config->getString($key),'hints');
 if(($globalAfter['de-DE'][$a]??null)!==['one','two'] || isset($globalAfter['fr-FR']))throw new RuntimeException('Global hints were modified by a channel merge.');
 $before=$config->getString($key,$channel);
 file_put_contents($file,json_encode(['de-DE'=>[$a=>array_map(static fn(int $n):string=>'extra'.$n,range(1,50))]],JSON_THROW_ON_ERROR));
 try {$tester->execute(['--query-hints'=>$file,'--sales-channel'=>$channel,'--merge-query-hints'=>true]);throw new RuntimeException('Oversized merge accepted.');} catch(InvalidArgumentException) {}
 if($config->getString($key,$channel)!==$before)throw new RuntimeException('Failed merge changed the configuration.');
 echo "Merged hints preserved language and product scope, inherited configuration, and existing values on failure.\n";
} finally {
 $restore($specific,$channel);$restore($global,null);@unlink($file);$kernel->shutdown();
}
