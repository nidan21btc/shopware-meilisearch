<?php declare(strict_types=1);
require_once __DIR__.'/pending_revision.php';
// Exercises same-language manufacturer translation, indexing and storefront facets.
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
$it = $domains['it-IT']['language'];
$index = Engine::indexName($channel, $it);
if (!in_array('manufacturer', $engine->request('GET', '/indexes/'.$index.'/settings/searchable-attributes'), true)) throw new RuntimeException('Manufacturer search must be enabled in the Italian test index.');
$search = static function(string $locale, string $query) use ($host): DOMXPath {
 $context=stream_context_create(['http'=>['header'=>"Host: $host\r\nX-Requested-With: XMLHttpRequest\r\n",'timeout'=>60,'follow_location'=>0,'max_redirects'=>0]]);
 $html=file_get_contents(rtrim(getenv('EL_SEARCH_TEST_URL'),'/').'/'.substr($locale,0,2).'/shopware-meilisearch?'.http_build_query(['q'=>$query]),false,$context);
 if($html===false||!str_contains($html,'data-el-result-status'))throw new RuntimeException('Local storefront unavailable.');
 $dom=new DOMDocument();@$dom->loadHTML($html);return new DOMXPath($dom);
};
$rows=$db->fetchAllAssociative("SELECT LOWER(HEX(p.id)) id,p.product_number number,LOWER(HEX(p.product_manufacturer_id)) manufacturer FROM product p JOIN product_visibility v ON v.product_id=p.id AND v.product_version_id=p.version_id JOIN product_translation pt ON pt.product_id=p.id AND pt.product_version_id=p.version_id AND pt.language_id=UNHEX(:it1) LEFT JOIN product_manufacturer_translation mt ON mt.product_manufacturer_id=p.product_manufacturer_id AND mt.language_id=UNHEX(:it2) WHERE p.version_id=UNHEX('0fa91ce3e96a4bc2be4bd9ce752c3425') AND p.active=1 AND p.parent_id IS NULL AND pt.name<>'' AND v.sales_channel_id=UNHEX(:channel) AND v.visibility>=20 AND mt.name IS NULL AND NOT EXISTS(SELECT 1 FROM product c WHERE c.parent_id=p.id AND c.parent_version_id=p.version_id) AND (SELECT COUNT(*) FROM product pp JOIN product_visibility vv ON vv.product_id=pp.id AND vv.product_version_id=pp.version_id WHERE pp.version_id=p.version_id AND pp.active=1 AND pp.product_manufacturer_id=p.product_manufacturer_id AND vv.sales_channel_id=UNHEX(:channel2) AND vv.visibility>=20)<=20 LIMIT 100",['it1'=>$it,'it2'=>$it,'channel'=>$channel,'channel2'=>$channel]);
$product=null;foreach($rows as $row){if($engine->request('GET','/indexes/'.$index.'/documents/'.$row['id'],allowMissing:true)!==null&&$search('it-IT',$row['number'])->query('//article[@data-product-id="'.$row['id'].'"]')->length){$product=$row;break;}}
if($product===null)throw new RuntimeException('A visible Italian test product with an untranslated manufacturer is required.');
if((int)$db->fetchOne('SELECT COUNT(*) FROM product_manufacturer_translation WHERE product_manufacturer_id=UNHEX(:manufacturer) AND language_id=UNHEX(:language)',['manufacturer'=>$product['manufacturer'],'language'=>$it]))throw new RuntimeException('The Italian test translation must not already exist.');
$token='zq'.strtr(bin2hex(random_bytes(12)),'0123456789abcdef','abcdefghijklmnop');
foreach($domains as $locale=>$_)if($search($locale,$token)->query('//article[@data-product-id="'.$product['id'].'"]')->length)throw new RuntimeException('Synthetic term was already present.');
$state=new DirtyState($db);$indexer=new Indexer($engine,$container->get('sales_channel.product.repository'),new DocumentBuilder(),new ParentTranslations($container->get('product.repository')),$config,$root.'/var/shopware-meilisearch');
$command=new IndexCommand($indexer,$container->get(CachedSalesChannelContextFactory::class),$db,$config);
$handler=new ReindexTaskHandler($container->get('scheduled_task.repository'),new NullLogger(),$command,$state,$root.'/var/shopware-meilisearch');
$repository=$container->get('product_manufacturer_translation.repository');$context=Context::createDefaultContext();$changed=false;
try {
 $before=(int)$db->fetchOne('SELECT revision FROM shopware_meilisearch_state WHERE id=1');
 $changed=true;$repository->upsert([['productManufacturerId'=>$product['manufacturer'],'languageId'=>$it,'name'=>$token]],$context);
 if((int)$db->fetchOne('SELECT revision FROM shopware_meilisearch_state WHERE id=1')<=$before||searchPendingRevisionForTest($db)===null)throw new RuntimeException('Manufacturer translation write did not mark the index dirty.');
 $state->requestRebuild();$handler->run();
 if(searchPendingRevisionForTest($db)!==null)throw new RuntimeException('Manufacturer index revision remains pending.');
 $result=$search('it-IT',$token);
 if(!$result->query('//article[@data-product-id="'.$product['id'].'"]')->length)throw new RuntimeException('Italian manufacturer search did not find the product.');
 if(!$result->query('//input[@name="manufacturer[]" and @value="'.$product['manufacturer'].'"]/parent::label[contains(.,"'.$token.'")]')->length)throw new RuntimeException('Italian manufacturer facet was not shown.');
 foreach($domains as $locale=>$_)if($locale!=='it-IT'&&$search($locale,$token)->query('//article[@data-product-id="'.$product['id'].'"]')->length)throw new RuntimeException('Manufacturer name crossed a language boundary.');
 echo "Italian manufacturer translation became searchable and filterable only in Italian.\n";
}finally{
 if($changed){
  if((int)$db->fetchOne('SELECT COUNT(*) FROM product_manufacturer_translation WHERE product_manufacturer_id=UNHEX(:manufacturer) AND language_id=UNHEX(:language)',['manufacturer'=>$product['manufacturer'],'language'=>$it]))$repository->delete([['productManufacturerId'=>$product['manufacturer'],'languageId'=>$it]],$context);
  if(searchPendingRevisionForTest($db)===null)$state->markDirty();$state->requestRebuild();
  $state->requestRebuild();$handler->run();
  if($search('it-IT',$token)->query('//article[@data-product-id="'.$product['id'].'"]')->length)throw new RuntimeException('Test manufacturer remained searchable after restoration.');
  if((int)$db->fetchOne('SELECT COUNT(*) FROM product_manufacturer_translation WHERE product_manufacturer_id=UNHEX(:manufacturer) AND language_id=UNHEX(:language)',['manufacturer'=>$product['manufacturer'],'language'=>$it]))throw new RuntimeException('Test translation was not removed.');
  echo "Original manufacturer translation state and indexes restored.\n";
 }
 $kernel->shutdown();
}
