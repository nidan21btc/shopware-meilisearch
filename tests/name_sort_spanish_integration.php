<?php declare(strict_types=1);
// Temporarily changes two Spanish labels and their local index documents.
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
$domains=$db->fetchAllAssociative("SELECT DISTINCT LOWER(HEX(d.sales_channel_id)) channel,LOWER(HEX(d.language_id)) language FROM sales_channel_domain d JOIN language l ON l.id=d.language_id JOIN locale o ON o.id=l.locale_id JOIN sales_channel s ON s.id=d.sales_channel_id WHERE s.active=1 AND o.code='es-ES'");
if(count($domains)!==1)throw new RuntimeException('One Spanish test sales channel is required.');
$channel=$domains[0]['channel'];$language=$domains[0]['language'];$index=Engine::indexName($channel,$language);
$engine=new Engine(HttpClient::create(),getenv('SHOPWARE_MEILISEARCH_URL')?:'',getenv('SHOPWARE_MEILISEARCH_KEY')?:'');
if(!in_array('keywords',$engine->request('GET','/indexes/'.$index.'/settings/searchable-attributes'),true))throw new RuntimeException('Spanish test index must include keywords.');
$search=static function(string $query,string $sort='relevance')use($host):array {
 $context=stream_context_create(['http'=>['header'=>"Host: $host\r\nX-Requested-With: XMLHttpRequest\r\n",'timeout'=>60,'follow_location'=>0,'max_redirects'=>0]]);
 $html=file_get_contents(rtrim(getenv('EL_SEARCH_TEST_URL'),'/').'/es/shopware-meilisearch?'.http_build_query(['q'=>$query,'sort'=>$sort]),false,$context);
 if($html===false||!str_contains($html,'data-el-result-status'))throw new RuntimeException('Local storefront unavailable.');$dom=new DOMDocument();@$dom->loadHTML($html);$xpath=new DOMXPath($dom);$result=[];foreach($xpath->query('//article[@data-product-id]') as $node)$result[]=$node->getAttribute('data-product-id');return $result;
};
$rows=$db->fetchAllAssociative("SELECT LOWER(HEX(p.id)) id,p.product_number number,t.name FROM product p JOIN product_translation t ON t.product_id=p.id AND t.product_version_id=p.version_id AND t.language_id=UNHEX(:language) JOIN product_visibility v ON v.product_id=p.id AND v.product_version_id=p.version_id WHERE p.version_id=UNHEX('0fa91ce3e96a4bc2be4bd9ce752c3425') AND p.active=1 AND p.parent_id IS NULL AND t.name<>'' AND v.sales_channel_id=UNHEX(:channel) AND v.visibility>=20 AND NOT EXISTS(SELECT 1 FROM product c WHERE c.parent_id=p.id AND c.parent_version_id=p.version_id) LIMIT 100",['language'=>$language,'channel'=>$channel]);
$chosen=[];$originalDocs=[];foreach($rows as $row){$doc=$engine->request('GET','/indexes/'.$index.'/documents/'.$row['id'],allowMissing:true);if($doc!==null&&in_array($row['id'],$search($row['number']),true)){$chosen[]=$row;$originalDocs[]=$doc;if(count($chosen)===2)break;}}
if(count($chosen)!==2)throw new RuntimeException('Two visible Spanish standalone products required.');
$token='zq'.strtr(bin2hex(random_bytes(8)),'0123456789abcdef','abcdefghijklmnop');$changed=false;$indexed=false;
try{
 $db->beginTransaction();foreach($chosen as $i=>$row)$db->executeStatement("UPDATE product_translation SET name=:name WHERE product_id=UNHEX(:id) AND product_version_id=UNHEX('0fa91ce3e96a4bc2be4bd9ce752c3425') AND language_id=UNHEX(:language)",['name'=>($i===0?'Nube ':'Ñame ').$token,'id'=>$row['id'],'language'=>$language]);$db->commit();$changed=true;
 $modified=$originalDocs;foreach($modified as $i=>&$doc){$doc['name']=($i===0?'Nube ':'Ñame ').$token;$doc['keywords']=$token;}unset($doc);
 $indexed=true;$engine->mutate('POST','/indexes/'.$index.'/documents',$modified);
 if($search($token,'name-asc')!==array_column($chosen,'id'))throw new RuntimeException('Spanish name sort must place N before Ñ.');
 echo "Spanish name sorting follows the displayed language.\n";
}finally{
 if($db->isTransactionActive())$db->rollBack();
 if($changed){$db->beginTransaction();try{foreach($chosen as $row)$db->executeStatement("UPDATE product_translation SET name=:name WHERE product_id=UNHEX(:id) AND product_version_id=UNHEX('0fa91ce3e96a4bc2be4bd9ce752c3425') AND language_id=UNHEX(:language)",['name'=>$row['name'],'id'=>$row['id'],'language'=>$language]);$db->commit();}catch(Throwable $e){$db->rollBack();throw $e;}}
 if($indexed){$engine->mutate('POST','/indexes/'.$index.'/documents',$originalDocs);foreach($originalDocs as $doc)if($engine->request('GET','/indexes/'.$index.'/documents/'.$doc['id'])!=$doc)throw new RuntimeException('Original document was not restored.');}
 $kernel->shutdown();
}
