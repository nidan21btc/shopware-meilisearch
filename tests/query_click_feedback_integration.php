<?php declare(strict_types=1);
// Uses a synthetic query signal and restores only an isolated shop's configuration.
use Doctrine\DBAL\Connection;
use ShopwareMeilisearch\Command\ImportSignalsCommand;
use ShopwareMeilisearch\Search\Engine;
use ShopwareMeilisearch\Search\QueryClickFeedback;
use Shopware\Core\Framework\Adapter\Kernel\KernelFactory;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpClient\HttpClient;

$dbUrl=parse_url(getenv('DATABASE_URL')?:'');$engineUrl=parse_url(getenv('SHOPWARE_MEILISEARCH_URL')?:'');$shopUrl=parse_url(getenv('EL_SEARCH_TEST_URL')?:'');$host=getenv('EL_SEARCH_TEST_HOST')?:'';
if(!is_array($dbUrl)||!preg_match('/^\/el_search_test_[a-f0-9]+$/D',$dbUrl['path']??'')||!in_array($dbUrl['host']??'', ['localhost','127.0.0.1'],true)
 ||!is_array($engineUrl)||($engineUrl['scheme']??'')!=='http'||($engineUrl['host']??'')!=='127.0.0.1'
 ||!is_array($shopUrl)||($shopUrl['scheme']??'')!=='http'||($shopUrl['host']??'')!=='127.0.0.1'||!preg_match('/^[a-zA-Z0-9.-]+$/D',$host))throw new RuntimeException('Isolated database and loopback test services required.');
foreach(['PROJECT_ROOT','DATABASE_URL','APP_URL','SHOPWARE_MEILISEARCH_URL','SHOPWARE_MEILISEARCH_KEY'] as $name)if(getenv($name)!==false)$_SERVER[$name]=$_ENV[$name]=getenv($name);
$root=getenv('PROJECT_ROOT')?:'';if(!is_file($root.'/vendor/autoload.php'))throw new RuntimeException('Isolated project root required.');
$loader=require $root.'/vendor/autoload.php';$kernel=KernelFactory::create('dev',true,$loader);$kernel->boot();$container=$kernel->getContainer();$db=$container->get(Connection::class);$config=$container->get(SystemConfigService::class);
if((int)$db->fetchOne('SELECT COUNT(*) FROM app WHERE active=1')||(int)$db->fetchOne('SELECT COUNT(*) FROM webhook WHERE active=1'))throw new RuntimeException('Apps and webhooks must be disabled.');
$domain=$db->fetchAssociative("SELECT LOWER(HEX(d.sales_channel_id)) channel,LOWER(HEX(d.language_id)) language FROM sales_channel_domain d JOIN language l ON l.id=d.language_id JOIN locale o ON o.id=l.locale_id JOIN sales_channel s ON s.id=d.sales_channel_id WHERE s.active=1 AND o.code='de-DE' LIMIT 1");
if($domain===false)throw new RuntimeException('German test domain required.');
$key='ShopwareMeilisearch.config.queryClickFeedback';
if((int)$db->fetchOne('SELECT COUNT(*) FROM system_config WHERE configuration_key=:key AND sales_channel_id IS NOT NULL',['key'=>$key]))throw new RuntimeException('No channel-specific feedback overrides permitted in the test.');
$row=$db->fetchAssociative('SELECT configuration_value FROM system_config WHERE configuration_key=:key AND sales_channel_id IS NULL',['key'=>$key]);$saved=$row===false ? null : json_decode($row['configuration_value'],true,512,JSON_THROW_ON_ERROR)['_value'];
if($saved!==null&&!is_string($saved))throw new RuntimeException('Unexpected original feedback configuration.');
$engine=new Engine(HttpClient::create(),getenv('SHOPWARE_MEILISEARCH_URL')?:'',getenv('SHOPWARE_MEILISEARCH_KEY')?:'');$index=Engine::indexName($domain['channel'],$domain['language']);
$fetch=static function(string $locale,array $params) use($host):array {
 $context=stream_context_create(['http'=>['header'=>"Host: $host\r\nX-Requested-With: XMLHttpRequest\r\n",'timeout'=>45,'follow_location'=>0,'max_redirects'=>0]]);
 $html=file_get_contents(rtrim(getenv('EL_SEARCH_TEST_URL'),'/').'/'.$locale.'/shopware-meilisearch?'.http_build_query($params),false,$context);
 if($html===false)throw new RuntimeException('Local storefront unavailable.');$dom=new DOMDocument();@$dom->loadHTML($html);$x=new DOMXPath($dom);
 if(!$x->query('//*[@data-el-result-status]')->length)throw new RuntimeException('Unexpected storefront response.');$ids=[];foreach($x->query('//article[@data-product-id]') as $card)$ids[]=$card->getAttribute('data-product-id');return $ids;
};
$file=tempnam(sys_get_temp_dir(),'el-query-feedback-');if($file===false)throw new RuntimeException('Temporary file unavailable.');
try {
 $config->delete($key);
 $params=['q'=>'bach'];$first=$fetch('de',$params);$second=$fetch('de',$params+['page'=>2]);$english=$fetch('en',$params);$price=$fetch('de',$params+['sort'=>'price-asc']);
 if(count($first)<10||count($second)<5)throw new RuntimeException('Test query needs at least two result pages.');
 $target=$second[4];if(in_array($target,$first,true))throw new RuntimeException('Test product already on the first page.');
 $signalKey=getenv('SHOPWARE_MEILISEARCH_KEY')?:'';$digest=QueryClickFeedback::fingerprint('de-DE','bach',$signalKey);
 $json=json_encode(['version'=>1,'keyCheck'=>QueryClickFeedback::keyCheck($signalKey),'locales'=>['de-DE'=>[$digest=>[$target=>5]]]],JSON_THROW_ON_ERROR);file_put_contents($file,$json);
 $beforeRevision=$db->fetchOne('SELECT revision FROM shopware_meilisearch_state WHERE id=1');$beforeIndex=$engine->request('GET','/indexes/'.$index)['updatedAt'];
 try {(new CommandTester(new ImportSignalsCommand($config,'wrong-key')))->execute(['--query-clicks'=>$file]);throw new RuntimeException('Different search key was accepted.');} catch(InvalidArgumentException) {}
 if($config->getString($key)!=='')throw new RuntimeException('Wrong-key import changed the configuration.');
 $tester=new CommandTester(new ImportSignalsCommand($config,$signalKey));if($tester->execute(['--query-clicks'=>$file])!==0)throw new RuntimeException('Signal import failed.');
 if($db->fetchOne('SELECT revision FROM shopware_meilisearch_state WHERE id=1')!==$beforeRevision||$engine->request('GET','/indexes/'.$index)['updatedAt']!==$beforeIndex)throw new RuntimeException('Runtime feedback unnecessarily invalidated the index.');
 $after=$fetch('de',$params);
 if(array_slice($after,0,3)!==array_slice($first,0,3)||($after[3]??null)!==$target)throw new RuntimeException('Feedback did not preserve the first three and promote the eligible product.');
 if($fetch('en',$params)!==$english)throw new RuntimeException('Feedback crossed the language boundary.');
 if($fetch('de',$params+['sort'=>'price-asc'])!==$price)throw new RuntimeException('Feedback changed price sorting.');
 if($fetch('de',$params+['maxPrice'=>'0'])!==[])throw new RuntimeException('Feedback bypassed the active price filter.');
 if($fetch('de',['q'=>' BACH '])!==$after)throw new RuntimeException('Equivalent query normalization changed the feedback.');
 $englishDigest=QueryClickFeedback::fingerprint('en-GB','synthetic future query',$signalKey);
 $additional=json_encode(['version'=>1,'keyCheck'=>QueryClickFeedback::keyCheck($signalKey),'locales'=>['en-GB'=>[$englishDigest=>[$target=>2]]]],JSON_THROW_ON_ERROR);
 file_put_contents($file,$additional);
 if($tester->execute(['--query-clicks'=>$file,'--merge-query-clicks'=>true])!==0)throw new RuntimeException('Signal merge failed.');
 $merged=QueryClickFeedback::parse($config->getString($key),$signalKey);
 if(($merged['de-DE'][$digest][$target]??null)!==5||($merged['en-GB'][$englishDigest][$target]??null)!==2)throw new RuntimeException('Merging lost existing language-specific feedback.');
 $beforeFailure=$config->getString($key);
 $extra=[];foreach(range(0,5) as $n){$id=str_repeat(dechex($n),32);if($id!==$target)$extra[$id]=2;if(count($extra)===5)break;}
 file_put_contents($file,json_encode(['version'=>1,'keyCheck'=>QueryClickFeedback::keyCheck($signalKey),'locales'=>['de-DE'=>[$digest=>$extra]]],JSON_THROW_ON_ERROR));
 try {$tester->execute(['--query-clicks'=>$file,'--merge-query-clicks'=>true]);throw new RuntimeException('Overfull merge accepted.');} catch(InvalidArgumentException) {}
 if($config->getString($key)!==$beforeFailure)throw new RuntimeException('Failed merge altered existing feedback.');
 $config->set($key,'{"invalid":true}');
 if($fetch('de',$params)!==$first)throw new RuntimeException('Invalid optional feedback should leave normal search usable.');
 echo "Local keyed feedback preserves scope, availability, sorting and the first three results.\n";
} finally {
 if($saved===null)$config->delete($key);else $config->set($key,$saved);
 @unlink($file);$kernel->shutdown();
}
