<?php declare(strict_types=1);
require_once __DIR__.'/pending_revision.php';
// Tests same-language inheritance and index updates on an isolated Shopware copy.
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

$candidates=$db->fetchAllAssociative("SELECT LOWER(HEX(child.id)) child_id,child.product_number number,LOWER(HEX(parent.id)) parent_id,de.keywords de_keywords,en.keywords en_keywords,cd.keywords child_de_keywords,ce.keywords child_en_keywords FROM product child JOIN product parent ON parent.id=child.parent_id AND parent.version_id=child.parent_version_id JOIN product_visibility v ON v.product_id=parent.id AND v.product_version_id=parent.version_id JOIN product_translation de ON de.product_id=parent.id AND de.product_version_id=parent.version_id AND de.language_id=UNHEX(:de) JOIN product_translation en ON en.product_id=parent.id AND en.product_version_id=parent.version_id AND en.language_id=UNHEX(:en) JOIN product_translation cd ON cd.product_id=child.id AND cd.product_version_id=child.version_id AND cd.language_id=UNHEX(:de2) JOIN product_translation ce ON ce.product_id=child.id AND ce.product_version_id=child.version_id AND ce.language_id=UNHEX(:en2) WHERE child.version_id=UNHEX('0fa91ce3e96a4bc2be4bd9ce752c3425') AND child.active=1 AND v.sales_channel_id=UNHEX(:channel) AND v.visibility>=20 AND (child.is_closeout=0 OR child.stock>=child.min_purchase) LIMIT 200",['de'=>$domains['de-DE']['language'],'en'=>$domains['en-GB']['language'],'de2'=>$domains['de-DE']['language'],'en2'=>$domains['en-GB']['language'],'channel'=>$channel]);
$product=null;foreach($candidates as $candidate){if($search('de-DE',$candidate['number'],$candidate['child_id'])&&$search('en-GB',$candidate['number'],$candidate['child_id'])){$product=$candidate;break;}}
if($product===null)throw new RuntimeException('A visible inheriting German/English variant is required.');
$deToken='zq'.strtr(bin2hex(random_bytes(12)),'0123456789abcdef','abcdefghijklmnop');$enToken='yx'.strtr(bin2hex(random_bytes(12)),'0123456789abcdef','abcdefghijklmnop');
$state=new DirtyState($db);$indexer=new Indexer($engine,$container->get('sales_channel.product.repository'),new DocumentBuilder(),new ParentTranslations($container->get('product.repository')),$config,$root.'/var/shopware-meilisearch');
$command=new IndexCommand($indexer,$container->get(CachedSalesChannelContextFactory::class),$db,$config);
$handler=new ReindexTaskHandler($container->get('scheduled_task.repository'),new NullLogger(),$command,$state,$root.'/var/shopware-meilisearch');
$repository=$container->get('product_translation.repository');$context=Context::createDefaultContext();$changed=false;
try {
 $before=(int)$db->fetchOne('SELECT revision FROM shopware_meilisearch_state WHERE id=1');
 $changed=true;$repository->update([
  ['productId'=>$product['parent_id'],'languageId'=>$domains['de-DE']['language'],'keywords'=>$deToken],
  ['productId'=>$product['parent_id'],'languageId'=>$domains['en-GB']['language'],'keywords'=>$enToken],
  ['productId'=>$product['child_id'],'languageId'=>$domains['de-DE']['language'],'keywords'=>null],
  ['productId'=>$product['child_id'],'languageId'=>$domains['en-GB']['language'],'keywords'=>null],
 ],$context);
 $after=(int)$db->fetchOne('SELECT revision FROM shopware_meilisearch_state WHERE id=1');
 if($after<=$before||searchPendingRevisionForTest($db)!==$after)throw new RuntimeException('Parent translation updates did not mark the indexes dirty.');
 $state->requestRebuild();$handler->run();
 foreach($domains as $locale=>$domain){
  $index=Engine::indexName($channel,$domain['language']);$doc=$engine->request('GET','/indexes/'.$index.'/documents/'.$product['child_id'],allowMissing:true);
  if($locale==='de-DE'||$locale==='en-GB'){
   $token=$locale==='de-DE'?$deToken:$enToken;
   if($doc===null||($doc['keywords']??null)!==$token||!$search($locale,$token,$product['child_id']))throw new RuntimeException('The variant did not inherit the updated keyword in '.$locale);
  }
  if(($doc['keywords']??null)===$deToken&&$locale!=='de-DE'||($doc['keywords']??null)===$enToken&&$locale!=='en-GB')throw new RuntimeException('The variant inherited keywords from a different language.');
  if($locale!=='de-DE'&&$search($locale,$deToken,$product['child_id'])||$locale!=='en-GB'&&$search($locale,$enToken,$product['child_id']))throw new RuntimeException('Variant search crossed a language boundary.');
 }
 echo "Parent translation updates refreshed inheriting variants and remained isolated in all five languages.\n";
} finally {
 if($changed){
  $repository->update([
   ['productId'=>$product['parent_id'],'languageId'=>$domains['de-DE']['language'],'keywords'=>$product['de_keywords']],
   ['productId'=>$product['parent_id'],'languageId'=>$domains['en-GB']['language'],'keywords'=>$product['en_keywords']],
   ['productId'=>$product['child_id'],'languageId'=>$domains['de-DE']['language'],'keywords'=>$product['child_de_keywords']],
   ['productId'=>$product['child_id'],'languageId'=>$domains['en-GB']['language'],'keywords'=>$product['child_en_keywords']],
  ],$context);
  if(searchPendingRevisionForTest($db)===null)$state->markDirty();$state->requestRebuild();$state->requestRebuild();$handler->run();
  foreach($domains as $locale=>$domain)if($search($locale,$deToken,$product['child_id'])||$search($locale,$enToken,$product['child_id']))throw new RuntimeException('The original parent translation was not restored in '.$locale);
  echo "Original parent translations and published indexes restored.\n";
 }
 $kernel->shutdown();
}
