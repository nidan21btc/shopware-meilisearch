<?php declare(strict_types=1);
// Verify the clearly marked name-only fallback in isolated language indexes.
use Doctrine\DBAL\Connection;
use ShopwareMeilisearch\Search\Engine;
use ShopwareMeilisearch\Search\PartialMatches;
use ShopwareMeilisearch\Search\QuantityUnits;
use Shopware\Core\Framework\Adapter\Kernel\KernelFactory;
use Symfony\Component\HttpClient\HttpClient;
$database=parse_url(getenv('DATABASE_URL')?:'');$engineUrl=parse_url(getenv('SHOPWARE_MEILISEARCH_URL')?:'');$shop=parse_url(getenv('EL_SEARCH_TEST_URL')?:'');$host=getenv('EL_SEARCH_TEST_HOST')?:'';
if(!is_array($database)||!preg_match('/^\/el_search_test_[a-f0-9]+$/D',$database['path']??'')||!in_array($database['host']??'', ['localhost','127.0.0.1'],true)
 ||!is_array($engineUrl)||($engineUrl['scheme']??'')!=='http'||($engineUrl['host']??'')!=='127.0.0.1'
 ||!is_array($shop)||($shop['scheme']??'')!=='http'||($shop['host']??'')!=='127.0.0.1'||!preg_match('/^[a-zA-Z0-9.-]+$/D',$host))throw new RuntimeException('Isolated database and loopback test services required.');
foreach(['PROJECT_ROOT','DATABASE_URL','APP_URL','SHOPWARE_MEILISEARCH_URL','SHOPWARE_MEILISEARCH_KEY'] as $name)if(getenv($name)!==false)$_SERVER[$name]=$_ENV[$name]=getenv($name);
$root=getenv('PROJECT_ROOT')?:'';if(!is_file($root.'/vendor/autoload.php'))throw new RuntimeException('Test project root required.');
$loader=require $root.'/vendor/autoload.php';$kernel=KernelFactory::create('dev',true,$loader);$kernel->boot();$container=$kernel->getContainer();$db=$container->get(Connection::class);
if((int)$db->fetchOne('SELECT COUNT(*) FROM app WHERE active=1')||(int)$db->fetchOne('SELECT COUNT(*) FROM webhook WHERE active=1'))throw new RuntimeException('Apps and webhooks must be disabled.');
$domains=$db->fetchAllAssociative("SELECT DISTINCT LOWER(HEX(d.sales_channel_id)) channel,LOWER(HEX(d.language_id)) language,o.code locale FROM sales_channel_domain d JOIN language l ON l.id=d.language_id JOIN locale o ON o.id=l.locale_id JOIN sales_channel s ON s.id=d.sales_channel_id WHERE s.active=1 AND o.code IN ('de-DE','en-GB','es-ES','fr-FR','it-IT')");
if(count($domains)!==5)throw new RuntimeException('Five test languages required.');
$rows=$db->fetchAllAssociative("SELECT LOWER(HEX(id)) id,product_number number,LOWER(HEX(unit_id)) unit,purchase_unit amount FROM product WHERE version_id=UNHEX('0fa91ce3e96a4bc2be4bd9ce752c3425') AND parent_id IS NOT NULL AND active=1 AND unit_id IS NOT NULL AND purchase_unit>0 LIMIT 1500");
$engine=new Engine(HttpClient::create(),getenv('SHOPWARE_MEILISEARCH_URL')?:'',getenv('SHOPWARE_MEILISEARCH_KEY')?:'');$units=QuantityUnits::parse('');
$fetch=static function(string $locale,string $query,array $extra=[])use($host):array{
 $ctx=stream_context_create(['http'=>['header'=>"Host: $host\r\nX-Requested-With: XMLHttpRequest\r\n",'timeout'=>60,'follow_location'=>0,'max_redirects'=>0]]);
 $html=file_get_contents(rtrim(getenv('EL_SEARCH_TEST_URL'),'/').'/'.substr($locale,0,2).'/shopware-meilisearch?'.http_build_query(['q'=>$query]+$extra),false,$ctx);
 if($html===false||!str_contains($html,'data-el-result-status'))throw new RuntimeException('Local storefront unavailable.');$dom=new DOMDocument();@$dom->loadHTML('<?xml encoding="UTF-8">'.$html);$xpath=new DOMXPath($dom);$ids=[];foreach($xpath->query('//article[@data-product-id]') as $card)$ids[]=$card->getAttribute('data-product-id');
 return ['ids'=>$ids,'notice'=>$xpath->query('//*[@data-el-quantity-fallback]')->length>0,'facetNotice'=>$xpath->query('//*[@data-el-facet-counts-may-change]')->length>0,'facets'=>$xpath->query('//input[@type="checkbox" and (@name="manufacturer[]" or @name="category[]")]')->length,'rawKey'=>str_contains($html,'elSearch.quantityFallback')];
};
foreach($domains as $domain){
 $index=Engine::indexName($domain['channel'],$domain['language']);$fields=$engine->request('GET','/indexes/'.$index.'/settings/searchable-attributes');if(!is_array($fields)||in_array('*',$fields,true))throw new RuntimeException('Explicit searchable fields required.');
 $without=array_values(array_diff($fields,['quantity']));$changed=false;
 try{
  if($without!==$fields){$changed=true;$engine->mutate('PUT','/indexes/'.$index.'/settings/searchable-attributes',$without);}
  $response=$engine->request('GET','/indexes/'.$index.'/documents?limit=10000');$docs=[];foreach($response['results']??[] as $doc)$docs[$doc['id']]=$doc;
  $found=null;
  foreach($rows as $row){
   $doc=$docs[$row['id']]??null;$name=is_array($doc)?($doc['name']??''):'';$tokens=QuantityUnits::tokens($row['unit'],(float)$row['amount'],$units);if(!is_string($name)||count($tokens)<3)continue;
   $query=$name.' '.$tokens[2];if(mb_strlen($query)>150||PartialMatches::withoutTrailingQuantity($query)!==$name)continue;
   $primary=$engine->request('POST','/indexes/'.$index.'/search',['q'=>$query,'limit'=>20,'matchingStrategy'=>'frequency','attributesToRetrieve'=>['id']]);if(in_array($row['id'],array_column($primary['hits']??[],'id'),true))continue;
   $nameHits=$engine->request('POST','/indexes/'.$index.'/search',['q'=>$name,'limit'=>20,'matchingStrategy'=>'all','attributesToRetrieve'=>['id']]);if(!in_array($row['id'],array_column($nameHits['hits']??[],'id'),true))continue;
   if(!in_array($row['id'],$fetch($domain['locale'],$row['number'])['ids'],true))continue;
   $result=$fetch($domain['locale'],$query);if(!in_array($row['id'],$result['ids'],true))continue;
   if(!$result['notice']||$result['rawKey'])throw new RuntimeException('A localized quantity warning is required in '.$domain['locale']);
   if($result['facets']>0&&!$result['facetNotice'])throw new RuntimeException('Changing facet counts must be explained in '.$domain['locale']);
   $found=['query'=>$query,'name'=>$name,'id'=>$row['id']];break;
  }
  if($found===null)throw new RuntimeException('A visible synthetic fallback case is required in '.$domain['locale']);
  $filtered=$fetch($domain['locale'],$found['query'],['maxPrice'=>'0']);if(in_array($found['id'],$filtered['ids'],true))throw new RuntimeException('The quantity fallback bypassed a price filter.');
  if($fetch($domain['locale'],$found['name'].' 1 ml + 2 ml')['notice'])throw new RuntimeException('An ambiguous multi-quantity query activated the fallback.');
  $with=$without;$with[]='quantity';$engine->mutate('PUT','/indexes/'.$index.'/settings/searchable-attributes',$with);$changed=true;
  if($fetch($domain['locale'],$found['query'])['notice'])throw new RuntimeException('Fallback activated when the quantity field was searchable.');
  echo $domain['locale']." quantity fallback, warning, filters and field selection passed.\n";
 }finally{if($changed)$engine->mutate('PUT','/indexes/'.$index.'/settings/searchable-attributes',$fields);}
}
$kernel->shutdown();echo "Original index settings restored.\n";
