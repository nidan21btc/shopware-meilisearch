<?php declare(strict_types=1);
// Verify reference-unit labels use the current language or a neutral mapped unit.
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
$unitId='00000000000000000000000000100031';
if($config->getString('ShopwareMeilisearch.config.quantityUnits',$channel)!=='')throw new RuntimeException('The default test unit mapping is required.');
$original=$db->fetchAssociative('SELECT name FROM unit_translation WHERE unit_id=UNHEX(:unit) AND language_id=UNHEX(:language)',['unit'=>$unitId,'language'=>$domains['de-DE']['language']]);
if($original===false)throw new RuntimeException('German test unit translation is required.');
foreach($domains as $locale=>$domain)if($locale!=='de-DE'&&(int)$db->fetchOne('SELECT COUNT(*) FROM unit_translation WHERE unit_id=UNHEX(:unit) AND language_id=UNHEX(:language)',['unit'=>$unitId,'language'=>$domain['language']]))throw new RuntimeException('The other test languages must lack the unit translation.');
$fetch=static function(string $locale,string $query,string $productId)use($host):?string{
 $ctx=stream_context_create(['http'=>['header'=>"Host: $host\r\nX-Requested-With: XMLHttpRequest\r\n",'timeout'=>60,'follow_location'=>0,'max_redirects'=>0]]);
 $html=file_get_contents(rtrim(getenv('EL_SEARCH_TEST_URL'),'/').'/'.substr($locale,0,2).'/shopware-meilisearch?'.http_build_query(['q'=>$query]),false,$ctx);
 if($html===false||!str_contains($html,'data-el-result-status'))throw new RuntimeException('Local storefront unavailable.');
 $dom=new DOMDocument();@$dom->loadHTML('<?xml encoding="UTF-8">'.$html);$xpath=new DOMXPath($dom);
 $node=$xpath->query('//article[@data-product-id="'.$productId.'"]//*[contains(concat(" ",normalize-space(@class)," ")," el-search__price ")]/small')->item(0);
 return $node?trim($node->textContent):null;
};
$candidates=$db->fetchAllAssociative("SELECT LOWER(HEX(p.id)) id,p.product_number number FROM product p JOIN product_visibility v ON v.product_id=p.id AND v.product_version_id=p.version_id WHERE p.version_id=UNHEX('0fa91ce3e96a4bc2be4bd9ce752c3425') AND p.active=1 AND p.parent_id IS NULL AND p.unit_id=UNHEX(:unit) AND p.purchase_unit>0 AND p.reference_unit>0 AND p.purchase_unit<>p.reference_unit AND v.sales_channel_id=UNHEX(:channel) AND v.visibility>=20 AND (p.is_closeout=0 OR p.stock>=p.min_purchase) AND NOT EXISTS(SELECT 1 FROM product c WHERE c.parent_id=p.id AND c.parent_version_id=p.version_id) LIMIT 100",['unit'=>$unitId,'channel'=>$channel]);
$product=null;foreach($candidates as $candidate){$valid=true;foreach($domains as $locale=>$domain)if($fetch($locale,$candidate['number'],$candidate['id'])===null){$valid=false;break;}if($valid){$product=$candidate;break;}}
if($product===null)throw new RuntimeException('A visible product with a reference price in all languages is required.');
$state=new DirtyState($db);$indexer=new Indexer($engine,$container->get('sales_channel.product.repository'),new DocumentBuilder(),new ParentTranslations($container->get('product.repository')),$config,$root.'/var/shopware-meilisearch');
$command=new IndexCommand($indexer,$container->get(CachedSalesChannelContextFactory::class),$db,$config);$handler=new ReindexTaskHandler($container->get('scheduled_task.repository'),new NullLogger(),$command,$state);
$repository=$container->get('unit_translation.repository');$context=Context::createDefaultContext();$changed=false;$token='Pruefeinheit'.bin2hex(random_bytes(4));
try{
 $changed=true;$repository->update([['unitId'=>$unitId,'languageId'=>$domains['de-DE']['language'],'name'=>$token]],$context);
 foreach($domains as $locale=>$domain){
  $label=$fetch($locale,$product['number'],$product['id']);if($label===null)throw new RuntimeException('Reference price disappeared in '.$locale);
  if($locale==='de-DE'&&!str_contains($label,$token))throw new RuntimeException('Current-language unit translation missing.');
  if($locale!=='de-DE'&&(str_contains($label,$token)||!str_contains($label,'ml')))throw new RuntimeException('Foreign-language unit leaked or neutral conversion missing in '.$locale);
 }
 echo "Reference unit uses its local translation or a neutral converted unit in all five languages.\n";
}finally{
 if($changed){
  $repository->update([['unitId'=>$unitId,'languageId'=>$domains['de-DE']['language'],'name'=>$original['name']]],$context);
  if($state->revisionToBuild()!==null)$handler->run();
  foreach($domains as $locale=>$domain){$label=$fetch($locale,$product['number'],$product['id']);if($label===null||str_contains($label,$token))throw new RuntimeException('Original reference unit was not restored in '.$locale);}
  echo "Original unit translation and indexes restored.\n";
 }
 $kernel->shutdown();
}
