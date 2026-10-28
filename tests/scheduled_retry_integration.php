<?php declare(strict_types=1);
// Exercises Shopware's actual scheduled-task failure/retry in a disposable copy.
use Doctrine\DBAL\Connection;
use ShopwareMeilisearch\Command\IndexCommand;
use ShopwareMeilisearch\ScheduledTask\ReindexTask;
use ShopwareMeilisearch\ScheduledTask\ReindexTaskHandler;
use ShopwareMeilisearch\Search\DirtyState;
use ShopwareMeilisearch\Search\DocumentBuilder;
use ShopwareMeilisearch\Search\Engine;
use ShopwareMeilisearch\Search\Indexer;
use ShopwareMeilisearch\Search\ParentTranslations;
use Psr\Log\NullLogger;
use Shopware\Core\Framework\Adapter\Kernel\KernelFactory;
use Shopware\Core\System\SalesChannel\Context\CachedSalesChannelContextFactory;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\HttpClient\HttpClient;

$database=parse_url(getenv('DATABASE_URL')?:'');$engineUrl=parse_url(getenv('SHOPWARE_MEILISEARCH_URL')?:'');
if(!is_array($database)||!preg_match('/^\/el_search_test_[a-f0-9]+$/D',$database['path']??'')||!in_array($database['host']??'', ['localhost','127.0.0.1'],true)
 ||!is_array($engineUrl)||($engineUrl['scheme']??'')!=='http'||($engineUrl['host']??'')!=='127.0.0.1')throw new RuntimeException('Isolated database and loopback search service required.');
foreach(['PROJECT_ROOT','DATABASE_URL','APP_URL','SHOPWARE_MEILISEARCH_URL','SHOPWARE_MEILISEARCH_KEY'] as $name)if(getenv($name)!==false)$_SERVER[$name]=$_ENV[$name]=getenv($name);
$root=getenv('PROJECT_ROOT')?:'';if(!is_file($root.'/vendor/autoload.php'))throw new RuntimeException('Isolated project root required.');
$loader=require $root.'/vendor/autoload.php';$kernel=KernelFactory::create('dev',true,$loader);$kernel->boot();$container=$kernel->getContainer();$db=$container->get(Connection::class);$config=$container->get(SystemConfigService::class);
if((int)$db->fetchOne('SELECT COUNT(*) FROM app WHERE active=1')||(int)$db->fetchOne('SELECT COUNT(*) FROM webhook WHERE active=1'))throw new RuntimeException('Apps and webhooks must be disabled.');
$domain=$db->fetchAssociative("SELECT LOWER(HEX(d.sales_channel_id)) channel, LOWER(HEX(d.language_id)) language FROM sales_channel_domain d JOIN language l ON l.id=d.language_id JOIN locale o ON o.id=l.locale_id JOIN sales_channel s ON s.id=d.sales_channel_id WHERE s.active=1 AND o.code='de-DE' LIMIT 1");
if($domain===false||!$config->getBool('ShopwareMeilisearch.config.enabled',$domain['channel']))throw new RuntimeException('Enabled German test channel required.');
$taskRow=$db->fetchAssociative("SELECT LOWER(HEX(id)) id, status, last_execution_time, next_execution_time, updated_at FROM scheduled_task WHERE name='shopware.meilisearch.reindex'");
if($taskRow===false||$taskRow['status']!=='scheduled')throw new RuntimeException('An idle scheduled test task is required.');
$state=new DirtyState($db);$http=HttpClient::create();$apiKey=getenv('SHOPWARE_MEILISEARCH_KEY')?:'';$goodEngine=new Engine($http,getenv('SHOPWARE_MEILISEARCH_URL')?:'',$apiKey);
$index=Engine::indexName($domain['channel'],$domain['language']);
if($goodEngine->request('GET','/indexes/'.$index.'_building',allowMissing:true)!==null)throw new RuntimeException('An existing build must not be disturbed.');
$snapshot=static function() use($goodEngine,$index):array {$meta=$goodEngine->request('GET','/indexes/'.$index);$stats=$goodEngine->request('GET','/indexes/'.$index.'/stats');$results=$goodEngine->request('POST','/indexes/'.$index.'/search',['q'=>'bach','limit'=>20,'attributesToRetrieve'=>['id']]);return ['updatedAt'=>$meta['updatedAt'],'count'=>$stats['numberOfDocuments'],'ids'=>array_column($results['hits'],'id')];};
$make=static function(Engine $engine) use($container,$db,$config,$root,$state):ReindexTaskHandler {
 $indexer=new Indexer($engine,$container->get('sales_channel.product.repository'),new DocumentBuilder(),new ParentTranslations($container->get('product.repository')),$config,$root.'/var/shopware-meilisearch');
 $command=new IndexCommand($indexer,$container->get(CachedSalesChannelContextFactory::class),$db,$config);
 return new ReindexTaskHandler($container->get('scheduled_task.repository'),new NullLogger(),$command,$state);
};
$queue=static function() use($container,$taskRow):void {$container->get('scheduled_task.repository')->update([['id'=>$taskRow['id'],'status'=>'queued']],Shopware\Core\Framework\Context::createCLIContext());};
$task=new ReindexTask();$task->setTaskId($taskRow['id']);$before=$snapshot();
try {
 $state->markDirty();$pending=$state->revisionToBuild();$queue();
 $badEngine=new Engine($http,'http://127.0.0.1:1',$apiKey);($make($badEngine))($task);
 $status=$db->fetchOne('SELECT status FROM scheduled_task WHERE id=UNHEX(:id)',['id'=>$taskRow['id']]);
 if($status!=='scheduled'||$state->revisionToBuild()!==$pending||$snapshot()!==$before)throw new RuntimeException('Failed task did not preserve its schedule, pending state or published index.');
 echo "Unavailable service: task rescheduled and published index preserved.\n";
 $queue();($make($goodEngine))($task);
 $status=$db->fetchOne('SELECT status FROM scheduled_task WHERE id=UNHEX(:id)',['id'=>$taskRow['id']]);$after=$snapshot();
 if($status!=='scheduled'||$state->revisionToBuild()!==null||$after['count']!==$before['count']||$after['ids']!==$before['ids'])throw new RuntimeException('Retry did not complete and preserve expected search results.');
 echo "Retry completed and the task was rescheduled.\n";
} finally {
 $db->executeStatement('UPDATE scheduled_task SET status=:status,last_execution_time=:last,next_execution_time=:next,updated_at=:updated WHERE id=UNHEX(:id)',
  ['status'=>$taskRow['status'],'last'=>$taskRow['last_execution_time'],'next'=>$taskRow['next_execution_time'],'updated'=>$taskRow['updated_at'],'id'=>$taskRow['id']]);
 $kernel->shutdown();
}
