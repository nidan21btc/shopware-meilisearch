<?php declare(strict_types=1);
// Checks quantity-aware variants in the isolated local indexes and storefront.
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
$search=static function(string $locale,string $query,bool $strict=false)use($host):array{
 $ctx=stream_context_create(['http'=>['header'=>"Host: $host\r\nX-Requested-With: XMLHttpRequest\r\n",'timeout'=>60,'follow_location'=>0,'max_redirects'=>0]]);
 $parameters=['q'=>$query];if($strict)$parameters['similar']='0';
 $html=file_get_contents(rtrim(getenv('EL_SEARCH_TEST_URL'),'/').'/'.substr($locale,0,2).'/shopware-meilisearch?'.http_build_query($parameters),false,$ctx);
 if($html===false||!str_contains($html,'data-el-result-status'))throw new RuntimeException('Local storefront unavailable.');
 $dom=new DOMDocument();@$dom->loadHTML($html);$xpath=new DOMXPath($dom);$ids=[];foreach($xpath->query('//article[@data-product-id]') as $node)$ids[]=$node->getAttribute('data-product-id');return $ids;
};
$litre=(getenv('EL_TEST_LITRE_UNIT_ID') ?: '');if(!preg_match('/^[a-f0-9]{32}$/D',$litre))throw new RuntimeException('Set EL_TEST_LITRE_UNIT_ID for the isolated fixture.');$units=ShopwareMeilisearch\Search\QuantityUnits::parse($config->getString('ShopwareMeilisearch.config.quantityUnits',$channel));
$rows=$db->fetchAllAssociative("SELECT LOWER(HEX(a.id)) first_id,LOWER(HEX(b.id)) second_id,a.product_number first_number,b.product_number second_number,a.purchase_unit first_amount,b.purchase_unit second_amount FROM product a JOIN product b ON b.parent_id=a.parent_id AND b.parent_version_id=a.parent_version_id AND b.id>a.id WHERE a.version_id=UNHEX('0fa91ce3e96a4bc2be4bd9ce752c3425') AND b.version_id=a.version_id AND a.parent_id IS NOT NULL AND a.active=1 AND b.active=1 AND a.unit_id=UNHEX(:unit1) AND b.unit_id=UNHEX(:unit2) AND a.purchase_unit>0 AND b.purchase_unit>0 AND a.purchase_unit<>b.purchase_unit LIMIT 200",['unit1'=>$litre,'unit2'=>$litre]);
foreach($domains as $locale=>$domain){
 $index=Engine::indexName($channel,$domain['language']);$selected=null;$originalDocs=[];
 foreach($rows as $row){
  $a=$engine->request('GET','/indexes/'.$index.'/documents/'.$row['first_id'],allowMissing:true);$b=$engine->request('GET','/indexes/'.$index.'/documents/'.$row['second_id'],allowMissing:true);
  if($a!==null&&$b!==null&&in_array($row['first_id'],$search($locale,$row['first_number']),true)&&in_array($row['second_id'],$search($locale,$row['second_number']),true)){$selected=$row;$originalDocs=[$a,$b];break;}
 }
 if($selected===null)throw new RuntimeException('Two visible variants with distinct quantities are required in '.$locale);
 $fields=$engine->request('GET','/indexes/'.$index.'/settings/searchable-attributes');$typo=$engine->request('GET','/indexes/'.$index.'/settings/typo-tolerance');$displayed=$engine->request('GET','/indexes/'.$index.'/settings/displayed-attributes');
 if(!in_array('keywords',$fields,true)||!in_array('name',$fields,true))throw new RuntimeException('Name and keywords are required in the test index.');
 $newFields=array_values(array_diff($fields,['quantity']));array_splice($newFields,array_search('name',$newFields,true)+1,0,['quantity']);
 $newTypo=$typo;$newTypo['disableOnAttributes']=array_values(array_unique(array_merge($typo['disableOnAttributes'],['quantity'])));
 $token='zq'.strtr(bin2hex(random_bytes(12)),'0123456789abcdef','abcdefghijklmnop');$changed=false;
 try{
  $changed=true;$engine->mutate('PUT','/indexes/'.$index.'/settings/searchable-attributes',$newFields);$engine->mutate('PATCH','/indexes/'.$index.'/settings/typo-tolerance',$newTypo);$engine->mutate('PUT','/indexes/'.$index.'/settings/displayed-attributes',array_values(array_unique(array_merge($displayed,['quantityKey']))));
  $docs=$originalDocs;$amounts=[(float)$selected['first_amount'],(float)$selected['second_amount']];
  foreach($docs as $i=>&$doc){$doc['keywords']=$token;$doc['quantity']=ShopwareMeilisearch\Search\QuantityUnits::tokens($litre,$amounts[$i],$units);$doc['quantityKey']=ShopwareMeilisearch\Search\QuantityUnits::key($litre,$amounts[$i],$units);if(count($doc['quantity'])!==4)throw new RuntimeException('Quantity conversion failed.');}unset($doc);
  $engine->mutate('POST','/indexes/'.$index.'/documents',$docs);
  foreach($docs as $doc){foreach($doc['quantity'] as $quantity){$ids=$search($locale,$token.' '.$quantity);if(($ids[0]??null)!==$doc['id'])throw new RuntimeException('The requested variant was not first in '.$locale);}}
  // Isolate a tokenizer collision: 0.1 ml must not outrank an exact 1 ml.
  $decimalDocs=$docs;
  $decimalDocs[0]['quantity']=['0.1 ml','0.1ml','0.0001 l','0.0001l'];$decimalDocs[0]['quantityKey']='ml:0.1';
  $decimalDocs[1]['quantity']=['1 ml','1ml','0.001 l','0.001l'];$decimalDocs[1]['quantityKey']='ml:1';
  $engine->mutate('POST','/indexes/'.$index.'/documents',$decimalDocs);
  foreach ([['1 ml',$decimalDocs[1]['id']],['1ml',$decimalDocs[1]['id']],['0,1 ml',$decimalDocs[0]['id']],['0.1ml',$decimalDocs[0]['id']],['0,001 l',$decimalDocs[1]['id']],['0,0001 l',$decimalDocs[0]['id']]] as [$quantity,$expected]) {
   $ids=$search($locale,$token.' '.$quantity);if(($ids[0]??null)!==$expected)throw new RuntimeException('Exact decimal quantity was not first in '.$locale);
  }

  // A spaced sign must not turn the following amount into a positive exact
  // quantity. Give the fractional variant a clear engine-order advantage so
  // an incorrect amount promotion is observable in the rendered shop.
  $signedDocs=$decimalDocs;
  foreach($signedDocs as $i=>&$doc){$doc['name']=$token.($i===0?'':' extra');}unset($doc);
  $engine->mutate('POST','/indexes/'.$index.'/documents',$signedDocs);
  foreach(['- 1 ml','− 1 ml','+ 1 ml'] as $signed){
   $query=$token.' '.$signed;
   $raw=$engine->request('POST','/indexes/'.$index.'/search',['q'=>$query,'page'=>1,'hitsPerPage'=>10000,'matchingStrategy'=>'frequency','attributesToRetrieve'=>['id'],'showRankingScoreDetails'=>true]);
   $hits=$raw['hits']??[];
   if(($hits[0]['id']??null)!==$signedDocs[0]['id']||($hits[1]['id']??null)!==$signedDocs[1]['id']||($hits[0]['_rankingScoreDetails']['words']['matchingWords']??null)!==($hits[1]['_rankingScoreDetails']['words']['matchingWords']??null)||($hits[0]['_rankingScoreDetails']['typo']['typoCount']??null)!==($hits[1]['_rankingScoreDetails']['typo']['typoCount']??null))throw new RuntimeException('Signed-quantity test needs two equally matched ordered variants.');
   $ids=$search($locale,$query);if(($ids[0]??null)!==$signedDocs[0]['id']||($ids[1]??null)!==$signedDocs[1]['id'])throw new RuntimeException('Signed amount unexpectedly changed variant order in '.$locale);
  }
  $engine->mutate('POST','/indexes/'.$index.'/documents',$decimalDocs);

  // Query the synthetic keyword by itself: a quantity is language-neutral and
  // can legitimately match a product in a different language's index.
  foreach($domains as $otherLocale=>$_)if($otherLocale!==$locale){$ids=$search($otherLocale,$token,true);if(in_array($docs[0]['id'],$ids,true)||in_array($docs[1]['id'],$ids,true))throw new RuntimeException('Quantity test keyword crossed a language boundary.');}

  echo $locale." quantity variants and language isolation passed.\n";
 }finally{
  if($changed){$engine->mutate('POST','/indexes/'.$index.'/documents',$originalDocs);$engine->mutate('PUT','/indexes/'.$index.'/settings/searchable-attributes',$fields);$engine->mutate('PATCH','/indexes/'.$index.'/settings/typo-tolerance',$typo);$engine->mutate('PUT','/indexes/'.$index.'/settings/displayed-attributes',$displayed);
   foreach($originalDocs as $doc)if($engine->request('GET','/indexes/'.$index.'/documents/'.$doc['id'])!=$doc)throw new RuntimeException('Original index document was not restored.');
  }
 }
}
$kernel->shutdown();
echo "Original index documents and settings restored.\n";
