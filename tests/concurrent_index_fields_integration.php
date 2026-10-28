<?php declare(strict_types=1);
// Changes configured search fields while a scheduler worker is rebuilding indexes.
use Doctrine\DBAL\Connection;
use ShopwareMeilisearch\Command\IndexCommand;
use ShopwareMeilisearch\ScheduledTask\ReindexTaskHandler;
use ShopwareMeilisearch\Search\DirtyState;
use ShopwareMeilisearch\Search\DocumentBuilder;
use ShopwareMeilisearch\Search\Engine;
use ShopwareMeilisearch\Search\Indexer;
use ShopwareMeilisearch\Search\ParentTranslations;
use Psr\Log\NullLogger;
use Shopware\Core\Framework\Adapter\Kernel\KernelFactory;
use Shopware\Core\Framework\Context;
use Shopware\Core\System\SalesChannel\Context\CachedSalesChannelContextFactory;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\HttpClient\HttpClient;

$database=parse_url(getenv('DATABASE_URL')?:'');$engineUrl=parse_url(getenv('SHOPWARE_MEILISEARCH_URL')?:'');$shop=parse_url(getenv('EL_SEARCH_TEST_URL')?:'');$host=getenv('EL_SEARCH_TEST_HOST')?:'';
if(!is_array($database)||!preg_match('/^\/el_search_test_[a-f0-9]+$/D',$database['path']??'')||!in_array($database['host']??'', ['localhost','127.0.0.1'],true)
 ||!is_array($engineUrl)||($engineUrl['scheme']??'')!=='http'||($engineUrl['host']??'')!=='127.0.0.1'
 ||!is_array($shop)||($shop['scheme']??'')!=='http'||($shop['host']??'')!=='127.0.0.1'||!preg_match('/^[a-zA-Z0-9.-]+$/D',$host))throw new RuntimeException('Isolated database and loopback test services required.');
foreach(['PROJECT_ROOT','DATABASE_URL','APP_URL','SHOPWARE_MEILISEARCH_URL','SHOPWARE_MEILISEARCH_KEY'] as $name)if(getenv($name)!==false)$_SERVER[$name]=$_ENV[$name]=getenv($name);
$root=getenv('PROJECT_ROOT')?:'';if(!is_file($root.'/vendor/autoload.php'))throw new RuntimeException('Test project root required.');
$loader=require $root.'/vendor/autoload.php';$kernel=KernelFactory::create('dev',true,$loader);$kernel->boot();$container=$kernel->getContainer();$db=$container->get(Connection::class);$config=$container->get(SystemConfigService::class);
if((int)$db->fetchOne('SELECT COUNT(*) FROM app WHERE active=1')||(int)$db->fetchOne('SELECT COUNT(*) FROM webhook WHERE active=1'))throw new RuntimeException('Apps and webhooks must be disabled.');
$rows=$db->fetchAllAssociative("SELECT DISTINCT LOWER(HEX(d.sales_channel_id)) channel,LOWER(HEX(d.language_id)) language,o.code locale FROM sales_channel_domain d JOIN language l ON l.id=d.language_id JOIN locale o ON o.id=l.locale_id JOIN sales_channel s ON s.id=d.sales_channel_id WHERE s.active=1 AND o.code IN ('de-DE','en-GB','es-ES','fr-FR','it-IT')");
$domains=[];foreach($rows as $row)$domains[$row['locale']]=$row;
if(count($domains)!==5||count(array_unique(array_column($domains,'channel')))!==1)throw new RuntimeException('Five languages in one active test channel are required.');
$channel=$domains['de-DE']['channel'];if(!$config->getBool('ShopwareMeilisearch.config.enabled',$channel))throw new RuntimeException('Enable local search in the test channel first.');
$engine=new Engine(HttpClient::create(),getenv('SHOPWARE_MEILISEARCH_URL')?:'',getenv('SHOPWARE_MEILISEARCH_KEY')?:'');

$keys=['ShopwareMeilisearch.config.indexFields','ShopwareMeilisearch.config.indexFieldsByLanguage'];$original=[];
foreach($keys as $key){
 if((int)$db->fetchOne('SELECT COUNT(*) FROM system_config WHERE configuration_key=:key AND sales_channel_id IS NOT NULL',['key'=>$key]))throw new RuntimeException('No channel-specific field overrides allowed.');
 $row=$db->fetchAssociative('SELECT configuration_value FROM system_config WHERE configuration_key=:key AND sales_channel_id IS NULL',['key'=>$key]);$original[$key]=$row===false?null:json_decode($row['configuration_value'],true,512,JSON_THROW_ON_ERROR)['_value'];
 if($original[$key]!==null&&!is_string($original[$key]))throw new RuntimeException('Unexpected field configuration type.');
}
$state=new DirtyState($db);$indexer=new Indexer($engine,$container->get('sales_channel.product.repository'),new DocumentBuilder(),new ParentTranslations($container->get('product.repository')),$config,$root.'/var/shopware-meilisearch');
$command=new IndexCommand($indexer,$container->get(CachedSalesChannelContextFactory::class),$db,$config);$handler=new ReindexTaskHandler($container->get('scheduled_task.repository'),new NullLogger(),$command,$state);
$oldFields=['name','productNumber'];$newFields=['productNumber','name','description'];$oldJson=json_encode($oldFields,JSON_THROW_ON_ERROR);$newJson=json_encode($newFields,JSON_THROW_ON_ERROR);
$published=static function(string $locale)use($engine,$channel,$domains):array{
 $index=Engine::indexName($channel,$domains[$locale]['language']);$fields=$engine->request('GET','/indexes/'.$index.'/settings/searchable-attributes');
 return array_values(array_filter($fields,static fn($field)=>!in_array($field,['nameHyphenAliases','queryHints'],true)));
};
$queue='el_search_fields_probe_'.bin2hex(random_bytes(8));$taskRows=$db->fetchAllAssociative('SELECT LOWER(HEX(id)) id,status,next_execution_time,last_execution_time,updated_at FROM scheduled_task');
if((int)$db->fetchOne("SELECT COUNT(*) FROM scheduled_task WHERE name='shopware.meilisearch.reindex'")!==1)throw new RuntimeException('One registered search task is required.');
if((int)$db->fetchOne('SELECT COUNT(*) FROM messenger_messages WHERE queue_name=:queue',['queue'=>$queue])!==0)throw new RuntimeException('Probe queue must be empty.');
$changed=false;
try{
 $db->executeStatement("UPDATE scheduled_task SET status='inactive' WHERE name<>'shopware.meilisearch.reindex'");
 $changed=true;$config->set($keys[0],$oldJson);$config->delete($keys[1]);
 $initialRevision=$state->revisionToBuild();if($initialRevision===null)throw new RuntimeException('Field changes did not mark an index revision.');
 $db->executeStatement("UPDATE scheduled_task SET status='scheduled',next_execution_time='2000-01-01 00:00:00.000' WHERE name='shopware.meilisearch.reindex'");
 $environment=['MESSENGER_TRANSPORT_DSN'=>'doctrine://default?auto_setup=false&queue_name='.$queue];
 $scheduler=new Symfony\Component\Process\Process([PHP_BINARY,'bin/console','scheduled-task:run','--no-wait'],$root,$environment);$scheduler->setTimeout(300);if($scheduler->run()!==0)throw new RuntimeException('Scheduler command failed.');
 $queued=$db->fetchOne('SELECT COUNT(*) FROM messenger_messages WHERE queue_name=:queue AND (body LIKE :class OR headers LIKE :class)',['queue'=>$queue,'class'=>'%ShopwareMeilisearch%ReindexTask%']);
 if((int)$queued!==1||(int)$db->fetchOne('SELECT COUNT(*) FROM messenger_messages WHERE queue_name=:queue',['queue'=>$queue])!==1)throw new RuntimeException('Expected one search task in the dedicated queue.');
 $deIndex=Engine::indexName($channel,$domains['de-DE']['language']);$updated=$engine->request('GET','/indexes/'.$deIndex)['updatedAt'];
 $worker=new Symfony\Component\Process\Process([PHP_BINARY,'bin/console','messenger:consume','async','--limit=1','--time-limit=180'],$root,$environment);$worker->setTimeout(300);$worker->start();
 try{
  $seen=false;$deadline=microtime(true)+120;
  while($worker->isRunning()&&microtime(true)<$deadline){if($engine->request('GET','/indexes/'.$deIndex)['updatedAt']!==$updated){$seen=true;break;}usleep(200000);}
  if(!$seen||!$worker->isRunning())throw new RuntimeException('Could not observe German index publication during the worker run.');
  if($published('de-DE')!==$oldFields)throw new RuntimeException('The first published index did not use the initial field selection.');
  $config->set($keys[0],$newJson);
 }finally{$worker->wait();}
 if(!$worker->isSuccessful()||(int)$db->fetchOne('SELECT COUNT(*) FROM messenger_messages WHERE queue_name=:queue',['queue'=>$queue])!==0)throw new RuntimeException('Search worker failed or did not consume its task.');
 $pending=$state->revisionToBuild();if($pending===null||$pending<=$initialRevision)throw new RuntimeException('The later field change was not left pending.');
 $handler->run();if($state->revisionToBuild()!==null)throw new RuntimeException('Follow-up field rebuild remains pending.');
 foreach($domains as $locale=>$domain)if($published($locale)!==$newFields)throw new RuntimeException('The follow-up field selection was not published in '.$locale);
 echo "A field change during the worker rebuild remained pending and was published in all five language indexes.\n";
}finally{
 try{
  if($changed){foreach($original as $key=>$value){if($value===null)$config->delete($key);else $config->set($key,$value);}if($state->revisionToBuild()===null)$state->markDirty();$handler->run();
   foreach($domains as $locale=>$domain){$expected=ShopwareMeilisearch\Search\IndexFields::forLocale($original[$keys[0]]??'',$original[$keys[1]]??'',$locale);if($published($locale)!==$expected)throw new RuntimeException('The original published fields were not restored in '.$locale);}
   echo "Original search field configuration and indexes restored.\n";}
 }finally{
  foreach($taskRows as $row)$db->executeStatement('UPDATE scheduled_task SET status=:status,next_execution_time=:next,last_execution_time=:last,updated_at=:updated WHERE id=UNHEX(:id)',['status'=>$row['status'],'next'=>$row['next_execution_time'],'last'=>$row['last_execution_time'],'updated'=>$row['updated_at'],'id'=>$row['id']]);
  $db->executeStatement('DELETE FROM messenger_messages WHERE queue_name=:queue',['queue'=>$queue]);$kernel->shutdown();echo "Scheduled task states restored; dedicated probe queue cleared.\n";
 }
}
