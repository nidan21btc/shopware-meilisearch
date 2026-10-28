<?php declare(strict_types=1);
// Temporarily changes names and search documents in the isolated test copy.
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
$domains=$db->fetchAllAssociative("SELECT LOWER(HEX(d.sales_channel_id)) channel,LOWER(HEX(d.language_id)) language,o.code locale FROM sales_channel_domain d JOIN language l ON l.id=d.language_id JOIN locale o ON o.id=l.locale_id JOIN sales_channel s ON s.id=d.sales_channel_id WHERE s.active=1 AND o.code IN ('de-DE','en-GB')");
$byLocale=[];foreach($domains as $domain)$byLocale[$domain['locale']]=$domain;
if(!isset($byLocale['de-DE'],$byLocale['en-GB'])||$byLocale['de-DE']['channel']!==$byLocale['en-GB']['channel'])throw new RuntimeException('German and English domains in one test channel required.');
$en=$byLocale['en-GB']['language'];$de=$byLocale['de-DE']['language'];$index=Engine::indexName($byLocale['en-GB']['channel'],$en);
$engine=new Engine(HttpClient::create(),getenv('SHOPWARE_MEILISEARCH_URL')?:'',getenv('SHOPWARE_MEILISEARCH_KEY')?:'');
if(!in_array('keywords',$engine->request('GET','/indexes/'.$index.'/settings/searchable-attributes'),true))throw new RuntimeException('The English test index must include keywords.');
$search=static function(string $query,string $sort='relevance')use($host):array {
 $context=stream_context_create(['http'=>['header'=>"Host: $host\r\nX-Requested-With: XMLHttpRequest\r\n",'timeout'=>60,'follow_location'=>0,'max_redirects'=>0]]);
 $html=file_get_contents(rtrim(getenv('EL_SEARCH_TEST_URL'),'/').'/en/shopware-meilisearch?'.http_build_query(['q'=>$query,'sort'=>$sort]),false,$context);
 if($html===false)throw new RuntimeException('Local storefront unavailable.');$dom=new DOMDocument();@$dom->loadHTML($html);$xpath=new DOMXPath($dom);$result=[];foreach($xpath->query('//article[@data-product-id]') as $node)$result[]=$node->getAttribute('data-product-id');return $result;
};
$families=$db->fetchAllAssociative("SELECT LOWER(HEX(p.id)) parent,LOWER(HEX(c.id)) child,c.product_number number,pt.name parent_name,ct.name child_name,dt.name child_de_name FROM product c JOIN product p ON p.id=c.parent_id AND p.version_id=c.parent_version_id JOIN product_translation pt ON pt.product_id=p.id AND pt.product_version_id=p.version_id AND pt.language_id=UNHEX(:en1) JOIN product_translation ct ON ct.product_id=c.id AND ct.product_version_id=c.version_id AND ct.language_id=UNHEX(:en2) JOIN product_translation dt ON dt.product_id=c.id AND dt.product_version_id=c.version_id AND dt.language_id=UNHEX(:de) WHERE c.version_id=UNHEX('0fa91ce3e96a4bc2be4bd9ce752c3425') AND c.active=1 AND p.active=1 AND pt.name<>'' AND ct.name<>'' AND dt.name<>'' LIMIT 100",['en1'=>$en,'en2'=>$en,'de'=>$de]);
$family=null;$childDoc=null;foreach($families as $row){$doc=$engine->request('GET','/indexes/'.$index.'/documents/'.$row['child'],allowMissing:true);if($doc!==null&&in_array($row['child'],$search($row['number']),true)){$family=$row;$childDoc=$doc;break;}}
if($family===null)throw new RuntimeException('Visible translated test variant required.');
$others=$db->fetchAllAssociative("SELECT LOWER(HEX(p.id)) id,p.product_number number,t.name FROM product p JOIN product_translation t ON t.product_id=p.id AND t.product_version_id=p.version_id AND t.language_id=UNHEX(:en) JOIN product_visibility v ON v.product_id=p.id AND v.product_version_id=p.version_id WHERE p.version_id=UNHEX('0fa91ce3e96a4bc2be4bd9ce752c3425') AND p.active=1 AND p.parent_id IS NULL AND p.id<>UNHEX(:parent) AND t.name<>'' AND v.sales_channel_id=UNHEX(:channel) AND v.visibility>=20 AND NOT EXISTS(SELECT 1 FROM product c WHERE c.parent_id=p.id AND c.parent_version_id=p.version_id) LIMIT 100",['en'=>$en,'parent'=>$family['parent'],'channel'=>$byLocale['en-GB']['channel']]);
$other=null;$otherDoc=null;foreach($others as $row){$doc=$engine->request('GET','/indexes/'.$index.'/documents/'.$row['id'],allowMissing:true);if($doc!==null&&in_array($row['id'],$search($row['number']),true)){$other=$row;$otherDoc=$doc;break;}}
if($other===null)throw new RuntimeException('Visible standalone test product required.');
$unique='zq'.strtr(bin2hex(random_bytes(8)),'0123456789abcdef','abcdefghijklmnop');
$updates=[['id'=>$family['parent'],'lang'=>$en,'before'=>$family['parent_name'],'after'=>'Alpha '.$unique],['id'=>$family['child'],'lang'=>$en,'before'=>$family['child_name'],'after'=>null],['id'=>$family['child'],'lang'=>$de,'before'=>$family['child_de_name'],'after'=>'Zulu '.$unique],['id'=>$other['id'],'lang'=>$en,'before'=>$other['name'],'after'=>'Mike '.$unique]];
$changed=false;$indexed=false;
try{
 $db->beginTransaction();foreach($updates as $entry)$db->executeStatement("UPDATE product_translation SET name=:name WHERE product_id=UNHEX(:id) AND product_version_id=UNHEX('0fa91ce3e96a4bc2be4bd9ce752c3425') AND language_id=UNHEX(:language)",['name'=>$entry['after'],'id'=>$entry['id'],'language'=>$entry['lang']]);$db->commit();$changed=true;
 $docs=[$childDoc,$otherDoc];$modified=$docs;$modified[0]['name']='Alpha '.$unique;$modified[1]['name']='Mike '.$unique;foreach($modified as &$doc)$doc['keywords']=$unique;unset($doc);
 $indexed=true;$engine->mutate('POST','/indexes/'.$index.'/documents',$modified);
 $actual=$search($unique,'name-asc');$expected=[$family['child'],$other['id']];
 if($actual!==$expected)throw new RuntimeException('Name sorting did not follow same-language displayed names.');
 echo "Name sorting uses inherited names in the current language.\n";
}finally{
 if($db->isTransactionActive())$db->rollBack();
 if($changed){$db->beginTransaction();try{foreach($updates as $entry)$db->executeStatement("UPDATE product_translation SET name=:name WHERE product_id=UNHEX(:id) AND product_version_id=UNHEX('0fa91ce3e96a4bc2be4bd9ce752c3425') AND language_id=UNHEX(:language)",['name'=>$entry['before'],'id'=>$entry['id'],'language'=>$entry['lang']]);$db->commit();}catch(Throwable $e){$db->rollBack();throw $e;}}
 if($indexed){$engine->mutate('POST','/indexes/'.$index.'/documents',[$childDoc,$otherDoc]);foreach([$childDoc,$otherDoc] as $doc)if($engine->request('GET','/indexes/'.$index.'/documents/'.$doc['id'])!=$doc)throw new RuntimeException('Original document was not restored.');}
 $kernel->shutdown();
}
