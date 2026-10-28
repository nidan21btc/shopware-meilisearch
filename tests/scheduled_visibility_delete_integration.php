<?php declare(strict_types=1);
require_once __DIR__.'/pending_revision.php';
// Deletes and restores a visibility assignment in an isolated Shopware copy.
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
$search=static function(string $locale,string $query,string $productId)use($host):bool{
 $context=stream_context_create(['http'=>['header'=>"Host: $host\r\nX-Requested-With: XMLHttpRequest\r\n",'timeout'=>60,'follow_location'=>0,'max_redirects'=>0]]);
 $html=file_get_contents(rtrim(getenv('EL_SEARCH_TEST_URL'),'/').'/'.substr($locale,0,2).'/shopware-meilisearch?'.http_build_query(['q'=>$query]),false,$context);
 if($html===false||!str_contains($html,'data-el-result-status'))throw new RuntimeException('Local storefront unavailable.');
 return str_contains($html,'data-product-id="'.$productId.'"');
};

$candidates=$db->fetchAllAssociative("SELECT LOWER(HEX(p.id)) id,p.product_number number,LOWER(HEX(v.id)) visibility_id,v.visibility FROM product p JOIN product_visibility v ON v.product_id=p.id AND v.product_version_id=p.version_id WHERE p.version_id=UNHEX('0fa91ce3e96a4bc2be4bd9ce752c3425') AND p.active=1 AND p.parent_id IS NULL AND v.sales_channel_id=UNHEX(:channel) AND v.visibility>=20 AND (p.is_closeout=0 OR p.stock>=p.min_purchase) AND NOT EXISTS(SELECT 1 FROM product c WHERE c.parent_id=p.id AND c.parent_version_id=p.version_id) LIMIT 100",['channel'=>$channel]);
$product=null;
foreach($candidates as $candidate){
 $visible=true;
 foreach($domains as $locale=>$domain){
  $index=Engine::indexName($channel,$domain['language']);
  if($engine->request('GET','/indexes/'.$index.'/documents/'.$candidate['id'],allowMissing:true)===null||!$search($locale,$candidate['number'],$candidate['id'])){$visible=false;break;}
 }
 if($visible){$product=$candidate;break;}
}
if($product===null)throw new RuntimeException('A product visible in all five test languages is required.');
$state=new DirtyState($db);$indexer=new Indexer($engine,$container->get('sales_channel.product.repository'),new DocumentBuilder(),new ParentTranslations($container->get('product.repository')),$config,$root.'/var/shopware-meilisearch');
$command=new IndexCommand($indexer,$container->get(CachedSalesChannelContextFactory::class),$db,$config);
$handler=new ReindexTaskHandler($container->get('scheduled_task.repository'),new NullLogger(),$command,$state,$root.'/var/shopware-meilisearch');
$repository=$container->get('product_visibility.repository');$context=Context::createDefaultContext();$deleted=false;
try {
 $before=(int)$db->fetchOne('SELECT revision FROM shopware_meilisearch_state WHERE id=1');
 $deleted=true;$repository->delete([['id'=>$product['visibility_id']]],$context);
 $after=(int)$db->fetchOne('SELECT revision FROM shopware_meilisearch_state WHERE id=1');
 if($after<=$before||searchPendingRevisionForTest($db)!==$after)throw new RuntimeException('Deleting product visibility did not mark the index dirty.');
 foreach($domains as $locale=>$domain){
  $index=Engine::indexName($channel,$domain['language']);
  if($engine->request('GET','/indexes/'.$index.'/documents/'.$product['id'],allowMissing:true)===null)throw new RuntimeException('Test requires a still-published stale index entry.');
  if($search($locale,$product['number'],$product['id']))throw new RuntimeException('The storefront showed a product after visibility was removed.');
 }
 $state->requestRebuild();$handler->run();
 if(searchPendingRevisionForTest($db)!==null)throw new RuntimeException('The successful task did not complete its revision.');
 foreach($domains as $locale=>$domain){
  $index=Engine::indexName($channel,$domain['language']);
  if($engine->request('GET','/indexes/'.$index.'/documents/'.$product['id'],allowMissing:true)!==null)throw new RuntimeException('Deleted visibility remained indexed in '.$locale);
 }
 echo "Visibility deletion invalidated the index, immediately hid the product, and was removed in all five published indexes.\n";
} finally {
 if($deleted){
  $exists=(int)$db->fetchOne('SELECT COUNT(*) FROM product_visibility WHERE id=UNHEX(:id)',['id'=>$product['visibility_id']]);
  if(!$exists)$repository->create([['id'=>$product['visibility_id'],'productId'=>$product['id'],'salesChannelId'=>$channel,'visibility'=>(int)$product['visibility']]],$context);
  if(searchPendingRevisionForTest($db)===null)$state->markDirty();$state->requestRebuild();
  $state->requestRebuild();$handler->run();
  foreach($domains as $locale=>$domain){
   $index=Engine::indexName($channel,$domain['language']);
   if($engine->request('GET','/indexes/'.$index.'/documents/'.$product['id'],allowMissing:true)===null||!$search($locale,$product['number'],$product['id']))throw new RuntimeException('Restored visibility did not return in '.$locale);
  }
  echo "Original product visibility and all published indexes restored.\n";
 }
 $kernel->shutdown();
}
