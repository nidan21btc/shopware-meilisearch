<?php declare(strict_types=1);
// Changes localized variant-option labels and verifies display and scheduled indexing.
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

foreach(['de-DE','en-GB'] as $locale){$index=Engine::indexName($channel,$domains[$locale]['language']);$fields=$engine->request('GET','/indexes/'.$index.'/settings/searchable-attributes');if(!in_array('properties',$fields,true))throw new RuntimeException('The published test indexes must search option properties.');}
$fetch=static function(string $locale,string $query,string $productId)use($host):array{
 $context=stream_context_create(['http'=>['header'=>"Host: $host\r\nX-Requested-With: XMLHttpRequest\r\n",'timeout'=>60,'follow_location'=>0,'max_redirects'=>0]]);
 $html=file_get_contents(rtrim(getenv('EL_SEARCH_TEST_URL'),'/').'/'.substr($locale,0,2).'/shopware-meilisearch?'.http_build_query(['q'=>$query]),false,$context);
 if($html===false||!str_contains($html,'data-el-result-status'))throw new RuntimeException('Local storefront unavailable.');
 $dom=new DOMDocument();@$dom->loadHTML('<?xml encoding="UTF-8">'.$html);$xpath=new DOMXPath($dom);$card=$xpath->query('//article[@data-product-id="'.$productId.'"]')->item(0);if(!$card)return ['visible'=>false,'options'=>''];
 $node=$xpath->query('.//*[contains(concat(" ",normalize-space(@class)," ")," el-search__variant-options ")]',$card)->item(0);
 return ['visible'=>true,'options'=>$node?trim($node->textContent):''];
};
$candidates=$db->fetchAllAssociative("SELECT LOWER(HEX(p.id)) product_id,p.product_number number,LOWER(HEX(o.property_group_option_id)) option_id,de.name de_option,en.name en_option FROM product p JOIN product_option o ON o.product_id=p.id AND o.product_version_id=p.version_id JOIN property_group_option_translation de ON de.property_group_option_id=o.property_group_option_id AND de.language_id=UNHEX(:de) JOIN property_group_option_translation en ON en.property_group_option_id=o.property_group_option_id AND en.language_id=UNHEX(:en) WHERE p.version_id=UNHEX('0fa91ce3e96a4bc2be4bd9ce752c3425') AND p.parent_id IS NOT NULL AND p.active=1 AND de.name<>'' AND en.name<>'' LIMIT 300",['de'=>$domains['de-DE']['language'],'en'=>$domains['en-GB']['language']]);
$product=null;foreach($candidates as $row)if($fetch('de-DE',$row['number'],$row['product_id'])['visible']&&$fetch('en-GB',$row['number'],$row['product_id'])['visible']){$product=$row;break;}
if($product===null)throw new RuntimeException('A visible variant with German and English option translations is required.');
$deToken='zq'.strtr(bin2hex(random_bytes(12)),'0123456789abcdef','abcdefghijklmnop');$enToken='yx'.strtr(bin2hex(random_bytes(12)),'0123456789abcdef','abcdefghijklmnop');
$state=new DirtyState($db);$indexer=new Indexer($engine,$container->get('sales_channel.product.repository'),new DocumentBuilder(),new ParentTranslations($container->get('product.repository')),$config,$root.'/var/shopware-meilisearch');
$command=new IndexCommand($indexer,$container->get(CachedSalesChannelContextFactory::class),$db,$config);$handler=new ReindexTaskHandler($container->get('scheduled_task.repository'),new NullLogger(),$command,$state);
$repository=$container->get('property_group_option_translation.repository');$context=Context::createDefaultContext();$changed=false;
try{
 $before=(int)$db->fetchOne('SELECT revision FROM shopware_meilisearch_state WHERE id=1');$changed=true;
 $repository->update([['propertyGroupOptionId'=>$product['option_id'],'languageId'=>$domains['de-DE']['language'],'name'=>$deToken],['propertyGroupOptionId'=>$product['option_id'],'languageId'=>$domains['en-GB']['language'],'name'=>$enToken]],$context);
 $after=(int)$db->fetchOne('SELECT revision FROM shopware_meilisearch_state WHERE id=1');if($after<=$before||$state->revisionToBuild()!==$after)throw new RuntimeException('Option translation write did not mark the index dirty.');
 foreach(['de-DE'=>$deToken,'en-GB'=>$enToken] as $locale=>$token){
  $other=$locale==='de-DE'?$enToken:$deToken;$shown=$fetch($locale,$product['number'],$product['product_id']);
  if(!$shown['visible']||!str_contains($shown['options'],$token)||str_contains($shown['options'],$other))throw new RuntimeException('Current option label did not update locally in '.$locale);
  $index=Engine::indexName($channel,$domains[$locale]['language']);$doc=$engine->request('GET','/indexes/'.$index.'/documents/'.$product['product_id']);if(in_array($token,$doc['properties']??[],true))throw new RuntimeException('Unpublished option appeared in the index.');
 }
 $handler->run();if($state->revisionToBuild()!==null)throw new RuntimeException('Option index task remains pending.');
 foreach($domains as $locale=>$domain){$index=Engine::indexName($channel,$domain['language']);$doc=$engine->request('GET','/indexes/'.$index.'/documents/'.$product['product_id'],allowMissing:true);if($doc===null)continue;
  foreach(['de-DE'=>$deToken,'en-GB'=>$enToken] as $expectedLocale=>$token){$indexed=in_array($token,$doc['properties']??[],true);if($indexed!==($locale===$expectedLocale))throw new RuntimeException('Option text crossed a language boundary.');}
  if(isset(['de-DE'=>$deToken,'en-GB'=>$enToken][$locale])){
   $token=$locale==='de-DE'?$deToken:$enToken;
   $hits=$engine->request('POST','/indexes/'.$index.'/search',['q'=>$token,'page'=>1,'hitsPerPage'=>10000,'attributesToRetrieve'=>['id']]);
   if(!in_array($product['product_id'],array_column($hits['hits']??[],'id'),true)||!$fetch($locale,$token.' '.$product['number'],$product['product_id'])['visible'])throw new RuntimeException('Updated option was not searchable in '.$locale);
  }
 }
 echo "Option edits update card labels immediately and rebuild language-local search indexes.\n";
}finally{
 if($changed){$repository->update([['propertyGroupOptionId'=>$product['option_id'],'languageId'=>$domains['de-DE']['language'],'name'=>$product['de_option']],['propertyGroupOptionId'=>$product['option_id'],'languageId'=>$domains['en-GB']['language'],'name'=>$product['en_option']]],$context);if($state->revisionToBuild()===null)$state->markDirty();$handler->run();
  foreach(['de-DE'=>$deToken,'en-GB'=>$enToken] as $locale=>$token){$shown=$fetch($locale,$product['number'],$product['product_id']);if(!$shown['visible']||str_contains($shown['options'],$token))throw new RuntimeException('The original option display was not restored.');$doc=$engine->request('GET','/indexes/'.Engine::indexName($channel,$domains[$locale]['language']).'/documents/'.$product['product_id']);if(in_array($token,$doc['properties']??[],true))throw new RuntimeException('The original option index was not restored.');}
  echo "Original option translations and published indexes restored.\n";
 }
 $kernel->shutdown();
}
