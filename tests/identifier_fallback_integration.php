<?php declare(strict_types=1);
// Read-only catalog checks plus a temporary test-index setting change.
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
if((int)$db->fetchOne('SELECT COUNT(*) FROM app WHERE active=1')||(int)$db->fetchOne('SELECT COUNT(*) FROM webhook WHERE active=1'))throw new RuntimeException('Apps and webhooks must be disabled in the test copy.');
$domains=$db->fetchAllAssociative("SELECT DISTINCT o.code locale,LOWER(HEX(d.sales_channel_id)) channel,LOWER(HEX(d.language_id)) language FROM sales_channel_domain d JOIN language l ON l.id=d.language_id JOIN locale o ON o.id=l.locale_id JOIN sales_channel s ON s.id=d.sales_channel_id WHERE s.active=1 AND o.code IN ('en-GB','es-ES','fr-FR','it-IT')");
if(count($domains)!==4)throw new RuntimeException('Four test languages required.');
$engine=new Engine(HttpClient::create(),getenv('SHOPWARE_MEILISEARCH_URL')?:'',getenv('SHOPWARE_MEILISEARCH_KEY')?:'');
$fetch=static function(string $locale,string $query,array $filters=[])use($host):DOMXPath {
 $context=stream_context_create(['http'=>['header'=>"Host: $host\r\nX-Requested-With: XMLHttpRequest\r\n",'timeout'=>45,'follow_location'=>0,'max_redirects'=>0]]);
 $html=file_get_contents(rtrim(getenv('EL_SEARCH_TEST_URL'),'/').'/'.substr($locale,0,2).'/shopware-meilisearch?'.http_build_query(['q'=>$query]+$filters),false,$context);
 if($html===false)throw new RuntimeException('Local storefront unavailable.');$dom=new DOMDocument();@$dom->loadHTML($html);$x=new DOMXPath($dom);if(!$x->query('//*[@data-el-result-status]')->length)throw new RuntimeException('Unexpected storefront response.');return $x;
};
$checks=[];$english=null;$eanChecks=0;
foreach($domains as $domain){
 $rows=$db->fetchAllAssociative("SELECT LOWER(HEX(p.id)) id,p.product_number number,p.ean,pp.ean parent_ean,p.manufacturer_number,pp.manufacturer_number parent_manufacturer FROM product p LEFT JOIN product pp ON pp.id=p.parent_id AND pp.version_id=p.parent_version_id LEFT JOIN product_translation t ON t.product_id=p.id AND t.product_version_id=p.version_id AND t.language_id=UNHEX(:language1) LEFT JOIN product_translation pt ON pt.product_id=p.parent_id AND pt.product_version_id=p.parent_version_id AND pt.language_id=UNHEX(:language2) WHERE p.version_id=UNHEX('0fa91ce3e96a4bc2be4bd9ce752c3425') AND p.active=1 AND (COALESCE(t.name,pt.name) IS NULL OR TRIM(COALESCE(t.name,pt.name))='') AND p.product_number REGEXP '[0-9]' ORDER BY p.id LIMIT 150",['language1'=>$domain['language'],'language2'=>$domain['language']]);
 $count=0;
 foreach($rows as $row){
  $x=$fetch($domain['locale'],$row['number']);$card=$x->query('//article[@data-product-id="'.$row['id'].'"]')->item(0);
  if(!$card)continue; // Products hidden by current Shopware availability are not expected.
  $names=$x->query('.//span[contains(concat(" ",normalize-space(@class)," ")," el-search__name ")]',$card);
  $notice=$x->query('.//small[contains(concat(" ",normalize-space(@class)," ")," el-search__missing-name ")]',$card);
  if($names->length!==1||trim($names->item(0)->textContent)!==$row['number']||$notice->length!==1)throw new RuntimeException('A missing name must display only the article number and a localized notice.');
  if($domain['locale']==='en-GB'&&$english===null&&$row['number']!==$row['ean']&&$row['number']!==$row['parent_ean']&&$row['number']!==$row['manufacturer_number']&&$row['number']!==$row['parent_manufacturer'])$english=[$domain,$row];
  ++$count;if($count>=5)break;
 }
 if($count<5)throw new RuntimeException('Not enough visible products without a local name.');$checks[$domain['locale']]=$count;
 $eanRow=$db->fetchAssociative("SELECT LOWER(HEX(p.id)) id,p.product_number number,p.ean FROM product p LEFT JOIN product_translation t ON t.product_id=p.id AND t.product_version_id=p.version_id AND t.language_id=UNHEX(:language1) LEFT JOIN product_translation pt ON pt.product_id=p.parent_id AND pt.product_version_id=p.parent_version_id AND pt.language_id=UNHEX(:language2) WHERE p.version_id=UNHEX('0fa91ce3e96a4bc2be4bd9ce752c3425') AND p.active=1 AND p.ean IS NOT NULL AND p.ean<>'' AND (COALESCE(t.name,pt.name) IS NULL OR TRIM(COALESCE(t.name,pt.name))='') LIMIT 1",['language1'=>$domain['language'],'language2'=>$domain['language']]);
 if($eanRow!==false&&$fetch('de-DE',$eanRow['ean'])->query('//article[@data-product-id="'.$eanRow['id'].'"]')->length){
  $x=$fetch($domain['locale'],$eanRow['ean']);$card=$x->query('//article[@data-product-id="'.$eanRow['id'].'"]')->item(0);
  if(!$card||trim($x->query('.//span[contains(concat(" ",normalize-space(@class)," ")," el-search__name ")]',$card)->item(0)?->textContent??'')!==$eanRow['number'])throw new RuntimeException('Exact EAN did not return the language-neutral article number.');
  ++$eanChecks;
 }
}
if($english===null)throw new RuntimeException('No suitable English setting test.');[$domain,$row]=$english;$index=Engine::indexName($domain['channel'],$domain['language']);
if($engine->request('GET','/indexes/'.$index.'_building',allowMissing:true)!==null)throw new RuntimeException('An existing build must not be disturbed.');
$original=$engine->request('GET','/indexes/'.$index.'/settings/searchable-attributes');
if(!in_array('productNumber',$original,true))throw new RuntimeException('Published test index must include article numbers.');
try {
 $engine->mutate('PUT','/indexes/'.$index.'/settings/searchable-attributes',array_values(array_diff($original,['productNumber'])));
 $x=$fetch('en-GB',$row['number']);if($x->query('//article[@data-product-id="'.$row['id'].'"]')->length)throw new RuntimeException('Disabled article-number field still returned the untranslated product.');
} finally {
 $engine->mutate('PUT','/indexes/'.$index.'/settings/searchable-attributes',$original);
 if($engine->request('GET','/indexes/'.$index.'/settings/searchable-attributes')!==$original)throw new RuntimeException('Test index settings were not restored.');
}
$prefix=substr($row['number'],0,-1);if($prefix!==''&&$fetch('en-GB',$prefix)->query('//article[@data-product-id="'.$row['id'].'"]')->length)throw new RuntimeException('Partial identifier unexpectedly returned an untranslated product.');
if($fetch('en-GB',$row['number'],['category'=>[str_repeat('0',32)]])->query('//article[@data-product-id="'.$row['id'].'"]')->length)throw new RuntimeException('Exact identifier bypassed the selected category filter.');
if(!$fetch('en-GB',$row['number'],['sort'=>'name-asc'])->query('//article[@data-product-id="'.$row['id'].'"]')->length)throw new RuntimeException('Exact identifier disappeared when sorting by displayed name.');
if(in_array('manufacturerNumber',$original,true)){
 $number='T9'.bin2hex(random_bytes(8));
 try {
  $db->executeStatement("UPDATE product SET manufacturer_number=:number WHERE id=UNHEX(:id) AND version_id=UNHEX('0fa91ce3e96a4bc2be4bd9ce752c3425')",['number'=>$number,'id'=>$row['id']]);
  if(!$fetch('en-GB',$number)->query('//article[@data-product-id="'.$row['id'].'"]')->length)throw new RuntimeException('Exact manufacturer number failed for an untranslated product.');
 } finally {
  $db->executeStatement("UPDATE product SET manufacturer_number=:number WHERE id=UNHEX(:id) AND version_id=UNHEX('0fa91ce3e96a4bc2be4bd9ce752c3425')",['number'=>$row['manufacturer_number'],'id'=>$row['id']]);
  $restored=$db->fetchOne("SELECT manufacturer_number FROM product WHERE id=UNHEX(:id) AND version_id=UNHEX('0fa91ce3e96a4bc2be4bd9ce752c3425')",['id'=>$row['id']]);
  if($restored!==$row['manufacturer_number'])throw new RuntimeException('Manufacturer number was not restored.');
 }
}
echo 'Exact code lookup with language-neutral labels verified: '.json_encode($checks,JSON_THROW_ON_ERROR).'; EAN cases: '.$eanChecks."; disabled field and partial code excluded.\n";
$kernel->shutdown();
