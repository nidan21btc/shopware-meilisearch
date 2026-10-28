<?php declare(strict_types=1);
// Temporarily changes two English names in an isolated database and index.
use Doctrine\DBAL\Connection;
use ShopwareMeilisearch\Search\Engine;
use Shopware\Core\Framework\Adapter\Kernel\KernelFactory;
use Symfony\Component\HttpClient\HttpClient;

$dbUrl=parse_url(getenv('DATABASE_URL')?:'');$engineUrl=parse_url(getenv('SHOPWARE_MEILISEARCH_URL')?:'');$shop=parse_url(getenv('EL_SEARCH_TEST_URL')?:'');$host=getenv('EL_SEARCH_TEST_HOST')?:'';
if(!is_array($dbUrl)||!preg_match('/^\/el_search_test_[a-f0-9]+$/D',$dbUrl['path']??'')||!in_array($dbUrl['host']??'', ['localhost','127.0.0.1'],true)
 ||!is_array($engineUrl)||($engineUrl['scheme']??'')!=='http'||($engineUrl['host']??'')!=='127.0.0.1'
 ||!is_array($shop)||($shop['scheme']??'')!=='http'||($shop['host']??'')!=='127.0.0.1'||!preg_match('/^[a-zA-Z0-9.-]+$/D',$host))throw new RuntimeException('Isolated database and loopback test services required.');
foreach(['PROJECT_ROOT','DATABASE_URL','APP_URL','SHOPWARE_MEILISEARCH_URL','SHOPWARE_MEILISEARCH_KEY'] as $name)if(getenv($name)!==false)$_SERVER[$name]=$_ENV[$name]=getenv($name);
$root=getenv('PROJECT_ROOT')?:'';if(!is_file($root.'/vendor/autoload.php'))throw new RuntimeException('Isolated project root required.');
$loader=require $root.'/vendor/autoload.php';$kernel=KernelFactory::create('dev',true,$loader);$kernel->boot();$db=$kernel->getContainer()->get(Connection::class);
if((int)$db->fetchOne('SELECT COUNT(*) FROM app WHERE active=1')||(int)$db->fetchOne('SELECT COUNT(*) FROM webhook WHERE active=1'))throw new RuntimeException('Apps and webhooks must be disabled.');
$domain=$db->fetchAssociative("SELECT LOWER(HEX(d.sales_channel_id)) channel,LOWER(HEX(d.language_id)) language FROM sales_channel_domain d JOIN language l ON l.id=d.language_id JOIN locale o ON o.id=l.locale_id JOIN sales_channel s ON s.id=d.sales_channel_id WHERE s.active=1 AND o.code='en-GB' LIMIT 1");
if($domain===false)throw new RuntimeException('English test domain required.');$index=Engine::indexName($domain['channel'],$domain['language']);
$engine=new Engine(HttpClient::create(),getenv('SHOPWARE_MEILISEARCH_URL')?:'',getenv('SHOPWARE_MEILISEARCH_KEY')?:'');
$settings=$engine->request('GET','/indexes/'.$index.'/settings/typo-tolerance');if(($settings['minWordSizeForTypos']['oneTypo']??0)!==4)throw new RuntimeException('Rebuild the English index first.');
$search=static function(string $query,bool $similar=false)use($host):DOMXPath {
 $context=stream_context_create(['http'=>['header'=>"Host: $host\r\nX-Requested-With: XMLHttpRequest\r\n",'timeout'=>30,'follow_location'=>0,'max_redirects'=>0]]);
 $html=file_get_contents(rtrim(getenv('EL_SEARCH_TEST_URL'),'/').'/en/shopware-meilisearch?'.http_build_query(['q'=>$query,'similar'=>$similar?'1':'0']),false,$context);
 if($html===false)throw new RuntimeException('Local storefront unavailable.');$dom=new DOMDocument();@$dom->loadHTML($html);return new DOMXPath($dom);
};
$rows=$db->fetchAllAssociative("SELECT LOWER(HEX(p.id)) parent,LOWER(HEX(c.id)) child,p.product_number parent_number,c.product_number child_number,pt.name parent_name,ct.name child_name FROM product c JOIN product p ON p.id=c.parent_id AND p.version_id=c.parent_version_id JOIN product_translation pt ON pt.product_id=p.id AND pt.product_version_id=p.version_id AND pt.language_id=UNHEX(:language) JOIN product_translation ct ON ct.product_id=c.id AND ct.product_version_id=c.version_id AND ct.language_id=UNHEX(:language) WHERE c.version_id=UNHEX('0fa91ce3e96a4bc2be4bd9ce752c3425') AND c.active=1 AND p.active=1 AND pt.name<>'' AND ct.name<>'' LIMIT 100",['language'=>$domain['language']]);
$chosen=null;$originalDocs=[];
foreach($rows as $row){
 $p=$engine->request('GET','/indexes/'.$index.'/documents/'.$row['parent'],allowMissing:true);$c=$engine->request('GET','/indexes/'.$index.'/documents/'.$row['child'],allowMissing:true);
 if($p===null||$c===null)continue;
 if(!$search($row['parent_number'])->query('//article[@data-product-id="'.$row['parent'].'"]')->length||!$search($row['child_number'])->query('//article[@data-product-id="'.$row['child'].'"]')->length)continue;
 $chosen=$row;$originalDocs=[$p,$c];break;
}
if($chosen===null)throw new RuntimeException('No suitable visible indexed test family found.');
// Synthetic alphabetic tokens are neither product identifiers nor customer queries.
$word='q'.strtr(bin2hex(random_bytes(2)),'0123456789abcdef','abcdefghijklmnop');$word=substr($word,0,4);$variant=substr($word,0,3).($word[3]==='z'?'y':'z');
$changed=false;$indexed=false;
try {
 $db->beginTransaction();foreach([['id'=>$chosen['parent'],'name'=>$word],['id'=>$chosen['child'],'name'=>$variant]] as $entry)$db->executeStatement("UPDATE product_translation SET name=:name WHERE product_id=UNHEX(:id) AND product_version_id=UNHEX('0fa91ce3e96a4bc2be4bd9ce752c3425') AND language_id=UNHEX(:language)",['name'=>$entry['name'],'id'=>$entry['id'],'language'=>$domain['language']]);$db->commit();$changed=true;
 $modified=$originalDocs;$modified[0]['name']=$word;$modified[1]['name']=$variant;$indexed=true;$engine->mutate('POST','/indexes/'.$index.'/documents',$modified);
 $ordinary=$search($word);$expanded=$search($word,true);$xpath='//article[@data-product-id="'.$chosen['parent'].'"]';
 if(!$ordinary->query($xpath)->length||!$ordinary->query('//*[@data-el-similar]')->length)throw new RuntimeException('Expected an exact parent and optional similar matches.');
 $childXpath='//article[@data-product-id="'.$chosen['child'].'"]';
 if($ordinary->query($childXpath)->length||!$expanded->query($childXpath)->length)throw new RuntimeException('Typo-matching variant was not controlled by the similar-results option.');
 if(!$expanded->query($xpath)->length)throw new RuntimeException('Exact parent disappeared when a typo-matching variant was added.');
 echo "Exact parent remains visible when a typo-matching variant is added.\n";
} finally {
 if($db->isTransactionActive())$db->rollBack();
 if($changed){$db->beginTransaction();try{foreach([['id'=>$chosen['parent'],'name'=>$chosen['parent_name']],['id'=>$chosen['child'],'name'=>$chosen['child_name']]] as $entry)$db->executeStatement("UPDATE product_translation SET name=:name WHERE product_id=UNHEX(:id) AND product_version_id=UNHEX('0fa91ce3e96a4bc2be4bd9ce752c3425') AND language_id=UNHEX(:language)",['name'=>$entry['name'],'id'=>$entry['id'],'language'=>$domain['language']]);$db->commit();}catch(Throwable $e){$db->rollBack();throw $e;}}
 if($indexed){
  $engine->mutate('POST','/indexes/'.$index.'/documents',$originalDocs);
  foreach($originalDocs as $original)if($engine->request('GET','/indexes/'.$index.'/documents/'.$original['id'])!=$original)throw new RuntimeException('Original search document was not restored.');
 }
 if($changed){
  foreach([['id'=>$chosen['parent'],'name'=>$chosen['parent_name']],['id'=>$chosen['child'],'name'=>$chosen['child_name']]] as $entry){
   $name=$db->fetchOne("SELECT name FROM product_translation WHERE product_id=UNHEX(:id) AND product_version_id=UNHEX('0fa91ce3e96a4bc2be4bd9ce752c3425') AND language_id=UNHEX(:language)",['id'=>$entry['id'],'language'=>$domain['language']]);
   if($name!==$entry['name'])throw new RuntimeException('Original product name was not restored.');
  }
 }
 $kernel->shutdown();
}
