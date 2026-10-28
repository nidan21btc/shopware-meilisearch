<?php declare(strict_types=1);
require_once __DIR__.'/pending_revision.php';
// Changes a translated keyword while the scheduler worker is rebuilding indexes.
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
foreach(['de-DE','en-GB'] as $locale){$index=Engine::indexName($channel,$domains[$locale]['language']);$fields=$engine->request('GET','/indexes/'.$index.'/settings/searchable-attributes');if(!in_array('keywords',$fields,true))throw new RuntimeException('The published test indexes must search translated keywords.');}
$search=static function(string $locale,string $query,string $productId)use($host):bool{
 $context=stream_context_create(['http'=>['header'=>"Host: $host\r\nX-Requested-With: XMLHttpRequest\r\n",'timeout'=>60,'follow_location'=>0,'max_redirects'=>0]]);
 $html=file_get_contents(rtrim(getenv('EL_SEARCH_TEST_URL'),'/').'/'.substr($locale,0,2).'/shopware-meilisearch?'.http_build_query(['q'=>$query]),false,$context);
 if($html===false||!str_contains($html,'data-el-result-status'))throw new RuntimeException('Local storefront unavailable.');
 return str_contains($html,'data-product-id="'.$productId.'"');
};
$candidates=$db->fetchAllAssociative("SELECT LOWER(HEX(p.id)) id,p.product_number number,de.keywords de_keywords,en.keywords en_keywords FROM product p JOIN product_visibility v ON v.product_id=p.id AND v.product_version_id=p.version_id JOIN product_translation de ON de.product_id=p.id AND de.product_version_id=p.version_id AND de.language_id=UNHEX(:de) JOIN product_translation en ON en.product_id=p.id AND en.product_version_id=p.version_id AND en.language_id=UNHEX(:en) WHERE p.version_id=UNHEX('0fa91ce3e96a4bc2be4bd9ce752c3425') AND p.active=1 AND p.parent_id IS NULL AND de.name<>'' AND en.name<>'' AND v.sales_channel_id=UNHEX(:channel) AND v.visibility>=20 AND (p.is_closeout=0 OR p.stock>=p.min_purchase) AND NOT EXISTS(SELECT 1 FROM product c WHERE c.parent_id=p.id AND c.parent_version_id=p.version_id) LIMIT 100",['de'=>$domains['de-DE']['language'],'en'=>$domains['en-GB']['language'],'channel'=>$channel]);
$product=null;foreach($candidates as $row){if($search('de-DE',$row['number'],$row['id'])&&$search('en-GB',$row['number'],$row['id'])){$product=$row;break;}}
if($product===null)throw new RuntimeException('A visible German/English test product is required.');
$deToken='zq'.strtr(bin2hex(random_bytes(12)),'0123456789abcdef','abcdefghijklmnop');$enToken='yx'.strtr(bin2hex(random_bytes(12)),'0123456789abcdef','abcdefghijklmnop');
$lateToken='uv'.strtr(bin2hex(random_bytes(12)),'0123456789abcdef','abcdefghijklmnop');
if($search('de-DE',$deToken,$product['id'])||$search('de-DE',$lateToken,$product['id'])||$search('en-GB',$enToken,$product['id']))throw new RuntimeException('Synthetic terms must be absent before the write.');
$state=new DirtyState($db);$indexer=new Indexer($engine,$container->get('sales_channel.product.repository'),new DocumentBuilder(),new ParentTranslations($container->get('product.repository')),$config,$root.'/var/shopware-meilisearch');
$command=new IndexCommand($indexer,$container->get(CachedSalesChannelContextFactory::class),$db,$config);
$handler=new ReindexTaskHandler($container->get('scheduled_task.repository'),new NullLogger(),$command,$state,$root.'/var/shopware-meilisearch');
$repository=$container->get('product_translation.repository');$context=Context::createDefaultContext();$changed=false;
$queue = 'el_search_probe_' . bin2hex(random_bytes(8));
$taskRows = $db->fetchAllAssociative('SELECT LOWER(HEX(id)) id,status,next_execution_time,last_execution_time,updated_at FROM scheduled_task');
if ((int)$db->fetchOne("SELECT COUNT(*) FROM scheduled_task WHERE name='shopware.meilisearch.reindex'") !== 1) throw new RuntimeException('One registered search task is required.');
if ((int)$db->fetchOne('SELECT COUNT(*) FROM messenger_messages WHERE queue_name=:queue', ['queue'=>$queue]) !== 0) throw new RuntimeException('Probe queue is not empty.');
$runScheduler = static function () use ($db, $root, $queue, $engine, $channel, $domains, $repository, $product, $context, $lateToken): void {
 $db->executeStatement("UPDATE scheduled_task SET status='scheduled',next_execution_time='2000-01-01 00:00:00.000' WHERE name='shopware.meilisearch.reindex'");
 $environment = ['MESSENGER_TRANSPORT_DSN'=>'doctrine://default?auto_setup=false&queue_name='.$queue];
 $run = static function (array $args) use ($root, $environment): void {
  $process = new Symfony\Component\Process\Process($args, $root, $environment);
  $process->setTimeout(300);
  if ($process->run() !== 0) throw new RuntimeException('Scheduler command failed.');
 };
 $run([PHP_BINARY,'bin/console','scheduled-task:run','--no-wait']);
 $queued = $db->fetchOne('SELECT COUNT(*) FROM messenger_messages WHERE queue_name=:queue AND (body LIKE :class OR headers LIKE :class)', ['queue'=>$queue,'class'=>'%ShopwareMeilisearch%ReindexTask%']);
 if ((int)$queued !== 1 || (int)$db->fetchOne('SELECT COUNT(*) FROM messenger_messages WHERE queue_name=:queue', ['queue'=>$queue]) !== 1) throw new RuntimeException('Expected exactly one search task in the dedicated queue.');
 $index = Engine::indexName($channel, $domains['de-DE']['language']);
 $originalUpdatedAt = $engine->request('GET','/indexes/'.$index)['updatedAt'];
 $worker = new Symfony\Component\Process\Process([PHP_BINARY,'bin/console','messenger:consume','async','--limit=1','--time-limit=180'], $root, $environment);
 $worker->setTimeout(300); $worker->start();
 try {
  $published = false; $deadline = microtime(true) + 120;
  while ($worker->isRunning() && microtime(true) < $deadline) {
   $info = $engine->request('GET','/indexes/'.$index);
   if ($info['updatedAt'] !== $originalUpdatedAt) { $published = true; break; }
   usleep(200000);
  }
  if (!$published || !$worker->isRunning()) throw new RuntimeException('Could not observe the German index publication during the worker run.');
  $repository->update([['productId'=>$product['id'],'languageId'=>$domains['de-DE']['language'],'keywords'=>$lateToken]],$context);
 } finally {
  $worker->wait();
 }
 if (!$worker->isSuccessful()) throw new RuntimeException('Search worker failed.');
 if ((int)$db->fetchOne('SELECT COUNT(*) FROM messenger_messages WHERE queue_name=:queue', ['queue'=>$queue]) !== 0) throw new RuntimeException('Search task was not consumed.');
};
try {
 $db->executeStatement("UPDATE scheduled_task SET status='inactive' WHERE name<>'shopware.meilisearch.reindex'");
 try {
 $before=(int)$db->fetchOne('SELECT revision FROM shopware_meilisearch_state WHERE id=1');
 $changed=true;$repository->update([
  ['productId'=>$product['id'],'languageId'=>$domains['de-DE']['language'],'keywords'=>$deToken],
  ['productId'=>$product['id'],'languageId'=>$domains['en-GB']['language'],'keywords'=>$enToken],
 ],$context);
 $after=(int)$db->fetchOne('SELECT revision FROM shopware_meilisearch_state WHERE id=1');
 if($after<=$before||searchPendingRevisionForTest($db)!==$after)throw new RuntimeException('Shopware translation write did not mark the index dirty.');
 if($search('de-DE',$deToken,$product['id'])||$search('en-GB',$enToken,$product['id']))throw new RuntimeException('Unpublished terms unexpectedly appeared before the rebuild.');
 $runScheduler();
 if(searchPendingRevisionForTest($db)===null)throw new RuntimeException('Change during indexing was lost.');
 if(!$search('de-DE',$deToken,$product['id'])||$search('de-DE',$lateToken,$product['id']))throw new RuntimeException('Expected the first published German index to precede the later edit.');
 $state->requestRebuild();$handler->run();
 if(searchPendingRevisionForTest($db)!==null)throw new RuntimeException('Follow-up build did not complete the pending revision.');
 if(!$search('de-DE',$lateToken,$product['id'])||!$search('en-GB',$enToken,$product['id']))throw new RuntimeException('Follow-up build did not publish language-local changes.');
 foreach($domains as $locale=>$_){
  if($locale!=='de-DE'&&$search($locale,$lateToken,$product['id']))throw new RuntimeException('German test keyword crossed a language boundary.');
  if($locale!=='en-GB'&&$search($locale,$enToken,$product['id']))throw new RuntimeException('English test keyword crossed a language boundary.');
 }
 echo "A change during the worker rebuild remained pending and was published by the follow-up task in its own language.\n";
} finally {
 if($changed){
  $repository->update([
   ['productId'=>$product['id'],'languageId'=>$domains['de-DE']['language'],'keywords'=>$product['de_keywords']],
   ['productId'=>$product['id'],'languageId'=>$domains['en-GB']['language'],'keywords'=>$product['en_keywords']],
  ],$context);
  if(searchPendingRevisionForTest($db)===null)$state->markDirty();$state->requestRebuild();
  $state->requestRebuild();$handler->run();
  if($search('de-DE',$deToken,$product['id'])||$search('de-DE',$lateToken,$product['id'])||$search('en-GB',$enToken,$product['id']))throw new RuntimeException('Restored test keywords were not reflected in the indexes.');
  echo "Original translated keywords and published indexes restored.\n";
 }
 }
} finally {
 foreach ($taskRows as $row) {
  $db->executeStatement('UPDATE scheduled_task SET status=:status,next_execution_time=:next,last_execution_time=:last,updated_at=:updated WHERE id=UNHEX(:id)', ['status'=>$row['status'],'next'=>$row['next_execution_time'],'last'=>$row['last_execution_time'],'updated'=>$row['updated_at'],'id'=>$row['id']]);
 }
 $db->executeStatement('DELETE FROM messenger_messages WHERE queue_name=:queue', ['queue'=>$queue]);
 $kernel->shutdown();
 echo "Scheduled task states restored; dedicated probe queue cleared.\n";
}
