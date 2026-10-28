<?php declare(strict_types=1);
// Temporarily changes search documents and availability in an isolated test copy.
use Doctrine\DBAL\Connection;
use ShopwareMeilisearch\Search\Engine;
use Shopware\Core\Framework\Adapter\Kernel\KernelFactory;
use Symfony\Component\HttpClient\HttpClient;
$dbUrl=parse_url(getenv('DATABASE_URL')?:'');$engineUrl=parse_url(getenv('SHOPWARE_MEILISEARCH_URL')?:'');$shop=parse_url(getenv('EL_SEARCH_TEST_URL')?:'');$host=getenv('EL_SEARCH_TEST_HOST')?:'';
if(!is_array($dbUrl)||!preg_match('/^\/el_search_test_[a-f0-9]+$/D',$dbUrl['path']??'')||!in_array($dbUrl['host']??'', ['localhost','127.0.0.1'],true)
 ||!is_array($engineUrl)||($engineUrl['scheme']??'')!=='http'||($engineUrl['host']??'')!=='127.0.0.1'
 ||!is_array($shop)||($shop['scheme']??'')!=='http'||($shop['host']??'')!=='127.0.0.1'||!preg_match('/^[a-zA-Z0-9.-]+$/D',$host))throw new RuntimeException('Isolated database and loopback test services required.');
foreach(['PROJECT_ROOT','DATABASE_URL','APP_URL','SHOPWARE_MEILISEARCH_URL','SHOPWARE_MEILISEARCH_KEY'] as $name)if(getenv($name)!==false)$_SERVER[$name]=$_ENV[$name]=getenv($name);
$root=getenv('PROJECT_ROOT')?:'';if(!is_file($root.'/vendor/autoload.php'))throw new RuntimeException('Test project root required.');
$loader=require $root.'/vendor/autoload.php';$kernel=KernelFactory::create('dev',true,$loader);$kernel->boot();$db=$kernel->getContainer()->get(Connection::class);
if((int)$db->fetchOne('SELECT COUNT(*) FROM app WHERE active=1')||(int)$db->fetchOne('SELECT COUNT(*) FROM webhook WHERE active=1'))throw new RuntimeException('Apps and webhooks must be disabled.');
$domain=$db->fetchAssociative("SELECT LOWER(HEX(d.sales_channel_id)) channel,LOWER(HEX(d.language_id)) language FROM sales_channel_domain d JOIN language l ON l.id=d.language_id JOIN locale o ON o.id=l.locale_id JOIN sales_channel s ON s.id=d.sales_channel_id WHERE s.active=1 AND o.code='de-DE' LIMIT 1");
if($domain===false)throw new RuntimeException('German test domain required.');$index=Engine::indexName($domain['channel'],$domain['language']);
$engine=new Engine(HttpClient::create(),getenv('SHOPWARE_MEILISEARCH_URL')?:'',getenv('SHOPWARE_MEILISEARCH_KEY')?:'');
$search=static function(string $query,?string $manufacturer=null,?string $minimumPrice=null,string $locale='de')use($host):DOMXPath {
 $context=stream_context_create(['http'=>['header'=>"Host: $host\r\nX-Requested-With: XMLHttpRequest\r\n",'timeout'=>60,'follow_location'=>0,'max_redirects'=>0]]);
 $params=['q'=>$query];if($manufacturer!==null)$params['manufacturer']=[$manufacturer];if($minimumPrice!==null)$params['minPrice']=$minimumPrice;
 $html=file_get_contents(rtrim(getenv('EL_SEARCH_TEST_URL'),'/').'/'.$locale.'/shopware-meilisearch?'.http_build_query($params),false,$context);
 if($html===false)throw new RuntimeException('Local storefront unavailable.');$dom=new DOMDocument();@$dom->loadHTML($html);return new DOMXPath($dom);
};
$rows=$db->fetchAllAssociative("SELECT LOWER(HEX(p.id)) id,LOWER(HEX(p.product_manufacturer_id)) manufacturer,p.product_number number FROM product p JOIN product_visibility v ON v.product_id=p.id AND v.product_version_id=p.version_id JOIN product_translation t ON t.product_id=p.id AND t.product_version_id=p.version_id AND t.language_id=UNHEX(:language) WHERE p.version_id=UNHEX('0fa91ce3e96a4bc2be4bd9ce752c3425') AND p.active=1 AND p.parent_id IS NULL AND p.product_manufacturer_id IS NOT NULL AND t.name<>'' AND v.sales_channel_id=UNHEX(:channel) AND v.visibility>=20 AND (p.is_closeout=0 OR p.stock>=p.min_purchase) AND NOT EXISTS(SELECT 1 FROM product c WHERE c.parent_id=p.id AND c.parent_version_id=p.version_id) ORDER BY p.product_manufacturer_id,p.id",['language'=>$domain['language'],'channel'=>$domain['channel']]);
$groups=[];foreach($rows as $row)$groups[$row['manufacturer']][]=$row;
$group=null;foreach($groups as $manufacturer=>$items)if(count($items)>=25){$group=$manufacturer;break;}
if($group===null)throw new RuntimeException('A sufficiently large manufacturer group is required.');
$chosen=[];$docs=[];foreach($groups[$group] as $row){$doc=$engine->request('GET','/indexes/'.$index.'/documents/'.$row['id'],allowMissing:true);if($doc===null)continue;$chosen[]=$row;$docs[]=$doc;if(count($chosen)===20)break;}
if(count($chosen)<20)throw new RuntimeException('Twenty indexed products are required.');
$target=null;$targetDoc=null;$seed=null;$seedDoc=null;
foreach($groups as $manufacturer=>$items){
 if($manufacturer===$group||count($items)<2)continue;
 $available=[];foreach($items as $row){$doc=$engine->request('GET','/indexes/'.$index.'/documents/'.$row['id'],allowMissing:true);if($doc!==null&&$search($row['number'])->query('//article[@data-product-id="'.$row['id'].'"]')->length)$available[]=[$row,$doc];if(count($available)===2)break;}
 if(count($available)===2){[$target,$targetDoc]=$available[0];[$seed,$seedDoc]=$available[1];break;}
}
if($target===null||$seed===null)throw new RuntimeException('Two visible products from another manufacturer are required.');
$token=static fn()=>strtr(bin2hex(random_bytes(8)),'0123456789abcdef','abcdefghijklmnop');$first='zq'.$token();$second='yx'.$token();$query=$first.' '.$second;
$original=array_merge($docs,[$seedDoc,$targetDoc]);$modified=$original;foreach($modified as $i=>&$doc)$doc['name']=$i<21?$query:$second;unset($doc);
$indexed=false;$deactivated=false;
try{
 $indexed=true;$engine->mutate('POST','/indexes/'.$index.'/documents',$modified);
 $response=$engine->request('POST','/indexes/'.$index.'/search',['q'=>$query,'page'=>1,'hitsPerPage'=>10000,'matchingStrategy'=>'frequency','attributesToRetrieve'=>['id']]);
 $ids=array_column($response['hits']??[],'id');
 if(count($ids)<20||in_array($target['id'],$ids,true))throw new RuntimeException('Synthetic query did not isolate at least 20 primary hits from the partial target.');
 $unfiltered=$search($query);if(!$unfiltered->query('//article[@data-product-id]')->length||$unfiltered->query('//article[@data-product-id="'.$target['id'].'"]')->length)throw new RuntimeException('A full page of primary products should remain unchanged.');
 if(!$unfiltered->query('//input[@name="manufacturer[]" and @value="'.$target['manufacturer'].'"]')->length)throw new RuntimeException('The customer must be able to select the target manufacturer.');
 $filtered=$search($query,$target['manufacturer']);
 if(!$filtered->query('//article[@data-product-id="'.$target['id'].'"]')->length)throw new RuntimeException('The eligible partial match disappeared when the primary hits were filtered out.');
 if(!$filtered->query('//article[@data-product-id="'.$seed['id'].'"]')->length)throw new RuntimeException('The matching primary product was lost after expansion.');
 if(!str_contains($filtered->document->textContent,'passen zu einzelnen'))throw new RuntimeException('The partial-match explanation is missing.');
 $priced=$search($query,$target['manufacturer'],'0');
 if(!$priced->query('//article[@data-product-id="'.$target['id'].'"]')->length)throw new RuntimeException('Partial expansion failed with an active price filter.');
 $db->beginTransaction();foreach($chosen as $row)$db->executeStatement("UPDATE product SET active=0 WHERE id=UNHEX(:id) AND version_id=UNHEX('0fa91ce3e96a4bc2be4bd9ce752c3425')",['id'=>$row['id']]);$db->commit();$deactivated=true;
 $stale=$search($query);
 if(!$stale->query('//article[@data-product-id="'.$target['id'].'"]')->length)throw new RuntimeException('Unavailable index hits suppressed an eligible partial match.');
 $db->beginTransaction();foreach($chosen as $row)$db->executeStatement("UPDATE product SET active=1 WHERE id=UNHEX(:id) AND version_id=UNHEX('0fa91ce3e96a4bc2be4bd9ce752c3425')",['id'=>$row['id']]);$db->commit();$deactivated=false;
 $engine->mutate('POST','/indexes/'.$index.'/documents',$original);foreach($original as $doc)if($engine->request('GET','/indexes/'.$index.'/documents/'.$doc['id'])!=$doc)throw new RuntimeException('Original search document was not restored.');$indexed=false;
 // Twenty primary documents can represent only ten displayed cards when
 // they are ten parent/variant pairs. This is also a sparse visible result.
 $families=$db->fetchAllAssociative("SELECT LOWER(HEX(p.id)) parent,LOWER(HEX(c.id)) child,p.product_number parent_number,c.product_number child_number FROM product c JOIN product p ON p.id=c.parent_id AND p.version_id=c.parent_version_id JOIN product_translation pt ON pt.product_id=p.id AND pt.product_version_id=p.version_id AND pt.language_id=UNHEX(:language1) JOIN product_translation ct ON ct.product_id=c.id AND ct.product_version_id=c.version_id AND ct.language_id=UNHEX(:language2) WHERE c.version_id=UNHEX('0fa91ce3e96a4bc2be4bd9ce752c3425') AND c.active=1 AND p.active=1 AND pt.name<>'' AND ct.name<>'' LIMIT 500",['language1'=>$domain['language'],'language2'=>$domain['language']]);
 $pairs=[];$familyDocs=[];$seenParents=[];
 foreach($families as $pair){
  if(isset($seenParents[$pair['parent']]))continue;
  $parentDoc=$engine->request('GET','/indexes/'.$index.'/documents/'.$pair['parent'],allowMissing:true);
  $childDoc=$engine->request('GET','/indexes/'.$index.'/documents/'.$pair['child'],allowMissing:true);
  if($parentDoc===null||$childDoc===null)continue;
  if(!$search($pair['parent_number'])->query('//article[@data-product-id="'.$pair['parent'].'"]')->length||!$search($pair['child_number'])->query('//article[@data-product-id="'.$pair['child'].'"]')->length)continue;
  $seenParents[$pair['parent']]=true;$pairs[]=$pair;$familyDocs[]=$parentDoc;$familyDocs[]=$childDoc;
  if(count($pairs)===10)break;
 }
 if(count($pairs)!==10)throw new RuntimeException('Ten visible variant families are required.');
 $original=array_merge($familyDocs,[$targetDoc]);$modified=$original;foreach($modified as $i=>&$doc)$doc['name']=$i<20?$query:$second;unset($doc);
 $indexed=true;$engine->mutate('POST','/indexes/'.$index.'/documents',$modified);
 $response=$engine->request('POST','/indexes/'.$index.'/search',['q'=>$query,'page'=>1,'hitsPerPage'=>10000,'matchingStrategy'=>'frequency','attributesToRetrieve'=>['id']]);
 $ids=array_column($response['hits']??[],'id');if(count($ids)<20||in_array($target['id'],$ids,true))throw new RuntimeException('Synthetic variant query did not isolate the partial target.');
 $collapsed=$search($query);if(!$collapsed->query('//article[@data-product-id="'.$target['id'].'"]')->length)throw new RuntimeException('Collapsed variant families suppressed an eligible partial match.');
 foreach($pairs as $pair){
  if(!$collapsed->query('//article[@data-product-id="'.$pair['child'].'"]')->length||$collapsed->query('//article[@data-product-id="'.$pair['parent'].'"]')->length)throw new RuntimeException('Variant family visibility was not preserved.');
 }
 $engine->mutate('POST','/indexes/'.$index.'/documents',$original);foreach($original as $doc)if($engine->request('GET','/indexes/'.$index.'/documents/'.$doc['id'])!=$doc)throw new RuntimeException('Original variant document was not restored.');$indexed=false;
 // Many unfiltered matches can precede the customer's eligible partial hit.
 $used=array_fill_keys(array_column($chosen,'id'),true);$distractors=[];
 foreach($groups[$group] as $row){
  if(isset($used[$row['id']]))continue;
  $doc=$engine->request('GET','/indexes/'.$index.'/documents/'.$row['id'],allowMissing:true);
  if($doc!==null)$distractors[]=$doc;
  if(count($distractors)===25)break;
 }
 if(count($distractors)!==25)throw new RuntimeException('Twenty-five indexed distractors are required.');
 $original=array_merge($docs,[$seedDoc],$distractors,[$targetDoc]);$modified=$original;
 foreach($modified as $i=>&$doc){
  if($i<21)$doc['name']=$query;
  elseif($i<46){$doc['name']=$second;$doc['popularity']=1000000;}
  else {$doc['name']='extra '.$second;$doc['popularity']=0;}
 }
 unset($doc);$indexed=true;$engine->mutate('POST','/indexes/'.$index.'/documents',$modified);
 $main=$engine->request('POST','/indexes/'.$index.'/search',['q'=>$query,'page'=>1,'hitsPerPage'=>10000,'matchingStrategy'=>'frequency','attributesToRetrieve'=>['id']]);
 $word=$engine->request('POST','/indexes/'.$index.'/search',['q'=>$second,'limit'=>100,'matchingStrategy'=>'frequency','attributesToRetrieve'=>['id']]);
 $wordIds=array_column($word['hits']??[],'id');$rank=array_search($target['id'],$wordIds,true);
 if(in_array($target['id'],array_column($main['hits']??[],'id'),true)||$rank===false||$rank<20)throw new RuntimeException('Synthetic target must occur beyond the unfiltered first 20 partial hits.');
 $deep=$search($query,$target['manufacturer']);
 if(!$deep->query('//article[@data-product-id="'.$seed['id'].'"]')->length||!$deep->query('//article[@data-product-id="'.$target['id'].'"]')->length)throw new RuntimeException('Eligible partial hit beyond the unfiltered first page was lost.');
 $deepPrice=$search($query,$target['manufacturer'],'0');
 if(!$deepPrice->query('//article[@data-product-id="'.$target['id'].'"]')->length)throw new RuntimeException('Eligible deep partial hit was lost under a price filter.');
 if(!$deep->query('//input[@name="manufacturer[]" and @value="'.$group.'"]')->length)throw new RuntimeException('The unfiltered manufacturer choice disappeared after partial expansion.');
 foreach(['en','es','fr','it'] as $locale){
  $otherLanguage=$search($query,null,null,$locale);
  if($otherLanguage->query('//article[@data-product-id="'.$target['id'].'"]')->length||$otherLanguage->query('//article[@data-product-id="'.$seed['id'].'"]')->length)throw new RuntimeException('A German-only partial search leaked into another language index.');
 }
 $engine->mutate('POST','/indexes/'.$index.'/documents',$original);foreach($original as $doc)if($engine->request('GET','/indexes/'.$index.'/documents/'.$doc['id'])!=$doc)throw new RuntimeException('Original deep-search document was not restored.');$indexed=false;
 // The first twenty eligible partial hits can collapse to ten variant cards;
 // continue to the next hit while the visible page is still sparse.
 $original=array_merge([$seedDoc],$familyDocs,[$targetDoc]);$modified=$original;
 foreach($modified as $i=>&$doc){
  if($i===0)$doc['name']=$query;
  elseif($i<=20){$doc['name']=$second;$doc['popularity']=1000000;}
  else {$doc['name']='extra '.$second;$doc['popularity']=0;}
 }
 unset($doc);$indexed=true;$engine->mutate('POST','/indexes/'.$index.'/documents',$modified);
 $main=$engine->request('POST','/indexes/'.$index.'/search',['q'=>$query,'page'=>1,'hitsPerPage'=>10000,'matchingStrategy'=>'frequency','attributesToRetrieve'=>['id']]);
 $word=$engine->request('POST','/indexes/'.$index.'/search',['q'=>$second,'limit'=>100,'matchingStrategy'=>'frequency','attributesToRetrieve'=>['id']]);
 $wordIds=array_column($word['hits']??[],'id');$rank=array_search($target['id'],$wordIds,true);
 if(in_array($target['id'],array_column($main['hits']??[],'id'),true)||$rank===false||$rank<20)throw new RuntimeException('Synthetic target must follow the first twenty variant hits.');
 $deepVariants=$search($query);
 if(!$deepVariants->query('//article[@data-product-id="'.$target['id'].'"]')->length)throw new RuntimeException('A partial target after collapsed variant hits was lost.');
 foreach($pairs as $pair)if(!$deepVariants->query('//article[@data-product-id="'.$pair['child'].'"]')->length||$deepVariants->query('//article[@data-product-id="'.$pair['parent'].'"]')->length)throw new RuntimeException('Partial variant family visibility was not preserved.');
 echo "Sparse filtered, unavailable and variant-collapsed multiword searches show eligible partial matches; full primary pages remain unchanged.\n";
}finally{
 if($db->isTransactionActive())$db->rollBack();
 if($deactivated){$db->beginTransaction();try{foreach($chosen as $row)$db->executeStatement("UPDATE product SET active=1 WHERE id=UNHEX(:id) AND version_id=UNHEX('0fa91ce3e96a4bc2be4bd9ce752c3425')",['id'=>$row['id']]);$db->commit();}catch(Throwable $e){$db->rollBack();throw $e;}}
 if($indexed){$engine->mutate('POST','/indexes/'.$index.'/documents',$original);foreach($original as $doc)if($engine->request('GET','/indexes/'.$index.'/documents/'.$doc['id'])!=$doc)throw new RuntimeException('Original search document was not restored.');}
 $kernel->shutdown();
}
