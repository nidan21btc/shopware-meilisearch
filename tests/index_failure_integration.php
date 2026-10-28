<?php declare(strict_types=1);
// Forces a failed build only in a disposable Shopware/Meilisearch test copy.
use Doctrine\DBAL\Connection;
use ShopwareMeilisearch\Search\Engine;
use Shopware\Core\Framework\Adapter\Kernel\KernelFactory;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\Process\Process;

$database=parse_url(getenv('DATABASE_URL')?:'');$engineUrl=parse_url(getenv('SHOPWARE_MEILISEARCH_URL')?:'');
if(!is_array($database)||!preg_match('/^\/el_search_test_[a-f0-9]+$/D',$database['path']??'')||!in_array($database['host']??'', ['localhost','127.0.0.1'],true)
 ||!is_array($engineUrl)||($engineUrl['scheme']??'')!=='http'||($engineUrl['host']??'')!=='127.0.0.1')throw new RuntimeException('Isolated database and loopback search service required.');
foreach(['PROJECT_ROOT','DATABASE_URL','APP_URL','SHOPWARE_MEILISEARCH_URL','SHOPWARE_MEILISEARCH_KEY'] as $name)if(getenv($name)!==false)$_SERVER[$name]=$_ENV[$name]=getenv($name);
$root=getenv('PROJECT_ROOT')?:'';if(!is_file($root.'/vendor/autoload.php'))throw new RuntimeException('Isolated project root required.');
$loader=require $root.'/vendor/autoload.php';$kernel=KernelFactory::create('dev',true,$loader);$kernel->boot();$container=$kernel->getContainer();$db=$container->get(Connection::class);$config=$container->get(SystemConfigService::class);
if((int)$db->fetchOne('SELECT COUNT(*) FROM app WHERE active=1')||(int)$db->fetchOne('SELECT COUNT(*) FROM webhook WHERE active=1'))throw new RuntimeException('Apps and webhooks must be disabled.');
$domain=$db->fetchAssociative("SELECT LOWER(HEX(d.sales_channel_id)) channel,LOWER(HEX(d.language_id)) language FROM sales_channel_domain d JOIN language l ON l.id=d.language_id JOIN locale o ON o.id=l.locale_id JOIN sales_channel s ON s.id=d.sales_channel_id WHERE s.active=1 AND o.code='de-DE' LIMIT 1");
if($domain===false)throw new RuntimeException('German test domain required.');
$index=Engine::indexName($domain['channel'],$domain['language']);$temporary=$index.'_building';
$engine=new Engine(HttpClient::create(),getenv('SHOPWARE_MEILISEARCH_URL')?:'',getenv('SHOPWARE_MEILISEARCH_KEY')?:'');
if($engine->request('GET','/indexes/'.$temporary,allowMissing:true)!==null)throw new RuntimeException('An existing build must not be disturbed.');
$key='ShopwareMeilisearch.config.synonyms';
if((int)$db->fetchOne('SELECT COUNT(*) FROM system_config WHERE configuration_key=:key AND sales_channel_id IS NOT NULL',['key'=>$key]))throw new RuntimeException('Channel-specific synonym overrides must be absent.');
$row=$db->fetchAssociative('SELECT configuration_value FROM system_config WHERE configuration_key=:key AND sales_channel_id IS NULL',['key'=>$key]);
$original=$row===false ? null : json_decode($row['configuration_value'],true,512,JSON_THROW_ON_ERROR)['_value'];
if($original!==null&&!is_string($original))throw new RuntimeException('Unexpected synonym configuration.');
$snapshot=static function() use($engine,$index):array {
 $meta=$engine->request('GET','/indexes/'.$index);$stats=$engine->request('GET','/indexes/'.$index.'/stats');
 $hits=$engine->request('POST','/indexes/'.$index.'/search',['q'=>'bach','limit'=>20,'attributesToRetrieve'=>['id']]);
 return ['updatedAt'=>$meta['updatedAt'],'count'=>$stats['numberOfDocuments'],'ids'=>array_column($hits['hits'],'id')];
};
$run=static function() use($root,$domain):Process {$p=new Process([PHP_BINARY,'bin/console','shopware-meilisearch:search:index','--sales-channel='.$domain['channel'],'--language='.$domain['language']],$root);$p->setTimeout(300);$p->run();return $p;};
$before=$snapshot();$stateBefore=$db->fetchAssociative('SELECT revision,indexed_revision FROM shopware_meilisearch_state WHERE id=1');$started=false;
try {
 // Structurally accepted by the plugin but rejected by Meilisearch, after
 // the temporary index has been created and before the atomic swap.
 $config->set($key,'{"de-DE":{"probe":{"unexpected":"value"}}}');$started=true;
 $dirty=$db->fetchAssociative('SELECT revision,indexed_revision FROM shopware_meilisearch_state WHERE id=1');
 if((int)$dirty['revision']<=(int)$stateBefore['revision'])throw new RuntimeException('Configuration change did not mark the index dirty.');
 $failed=$run();
 if($failed->isSuccessful())throw new RuntimeException('Invalid build unexpectedly succeeded.');
 if($snapshot()!==$before)throw new RuntimeException('Failed build changed the published index.');
 $after=$db->fetchAssociative('SELECT revision,indexed_revision FROM shopware_meilisearch_state WHERE id=1');
 if((int)$after['revision']!==(int)$dirty['revision']||(int)$after['indexed_revision']!==(int)$stateBefore['indexed_revision'])throw new RuntimeException('Failed build changed the indexed revision.');
 if($engine->request('GET','/indexes/'.$temporary,allowMissing:true)===null)throw new RuntimeException('Expected incomplete temporary index.');
 echo "Failed staged build left the published index and pending revision intact.\n";
} finally {
 if($started){
  if($original===null)$config->delete($key);else $config->set($key,$original);
  $repair=$run();if(!$repair->isSuccessful())throw new RuntimeException('Could not restore the test index after the failed build.');
  if($engine->request('GET','/indexes/'.$temporary,allowMissing:true)!==null)throw new RuntimeException('Retry did not clean the temporary index.');
  $restored=$snapshot();if($restored['count']!==$before['count']||$restored['ids']!==$before['ids'])throw new RuntimeException('Restored index differs from the original test results.');
 }
 $kernel->shutdown();
}
echo "A valid retry cleaned the temporary index and restored the expected results.\n";
