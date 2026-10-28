<?php declare(strict_types=1);
// Temporarily edits two documents and one availability flag in a disposable test copy.
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
$search=static function(string $query,?string $manufacturer=null,string $locale='de')use($host):DOMXPath {
 $context=stream_context_create(['http'=>['header'=>"Host: $host\r\nX-Requested-With: XMLHttpRequest\r\n",'timeout'=>60,'follow_location'=>0,'max_redirects'=>0]]);
 $params=['q'=>$query];if($manufacturer!==null)$params['manufacturer']=[$manufacturer];
 $html=file_get_contents(rtrim(getenv('EL_SEARCH_TEST_URL'),'/').'/'.$locale.'/shopware-meilisearch?'.http_build_query($params),false,$context);
 if($html===false)throw new RuntimeException('Local storefront unavailable.');$dom=new DOMDocument();@$dom->loadHTML($html);return new DOMXPath($dom);
};
$rows=$db->fetchAllAssociative("SELECT LOWER(HEX(p.id)) id,LOWER(HEX(p.product_manufacturer_id)) manufacturer,p.product_number number FROM product p JOIN product_visibility v ON v.product_id=p.id AND v.product_version_id=p.version_id JOIN product_translation t ON t.product_id=p.id AND t.product_version_id=p.version_id AND t.language_id=UNHEX(:language) WHERE p.version_id=UNHEX('0fa91ce3e96a4bc2be4bd9ce752c3425') AND p.active=1 AND p.parent_id IS NULL AND p.product_manufacturer_id IS NOT NULL AND t.name<>'' AND v.sales_channel_id=UNHEX(:channel) AND v.visibility>=20 AND NOT EXISTS(SELECT 1 FROM product c WHERE c.parent_id=p.id AND c.parent_version_id=p.version_id) LIMIT 300",['language'=>$domain['language'],'channel'=>$domain['channel']]);
$chosen=[];$docs=[];
foreach($rows as $row){
 if($chosen!==[] && $row['manufacturer']===$chosen[0]['manufacturer'])continue;
 $doc=$engine->request('GET','/indexes/'.$index.'/documents/'.$row['id'],allowMissing:true);if($doc===null)continue;
 if(!$search($row['number'])->query('//article[@data-product-id="'.$row['id'].'"]')->length)continue;
 $chosen[]=$row;$docs[]=$doc;if(count($chosen)===2)break;
}
if(count($chosen)!==2)throw new RuntimeException('Two visible products with different manufacturers are required.');
$unique='zq'.strtr(bin2hex(random_bytes(8)),'0123456789abcdef','abcdefghijklmnop');
$ascii=$unique.'aessueoe';$alternate=$unique.'äßüö';
$docChanged=false;$deactivated=false;
try{
 $modified=$docs;$modified[0]['name']=$ascii;$modified[1]['name']=$alternate;$docChanged=true;$engine->mutate('POST','/indexes/'.$index.'/documents',$modified);
 $hits=$engine->request('POST','/indexes/'.$index.'/search',['q'=>$ascii,'limit'=>100,'attributesToRetrieve'=>['id']]);
 $ids=array_column($hits['hits']??[],'id');if(!in_array($chosen[0]['id'],$ids,true)||in_array($chosen[1]['id'],$ids,true))throw new RuntimeException('Synthetic spelling inputs are not distinct in the index.');
 $deactivated=true;$db->executeStatement("UPDATE product SET active=0 WHERE id=UNHEX(:id) AND version_id=UNHEX('0fa91ce3e96a4bc2be4bd9ce752c3425')",['id'=>$chosen[0]['id']]);
 $result=$search($ascii);
 if(!$result->query('//article[@data-product-id="'.$chosen[1]['id'].'"]')->length)throw new RuntimeException('Alternative spelling was not tried after an unavailable indexed hit.');
 if(!str_contains($result->document->textContent,'eine andere Schreibweise.'))throw new RuntimeException('Missing spelling fallback explanation.');
 $db->executeStatement("UPDATE product SET active=1 WHERE id=UNHEX(:id) AND version_id=UNHEX('0fa91ce3e96a4bc2be4bd9ce752c3425')",['id'=>$chosen[0]['id']]);$deactivated=false;
 $original=$search($ascii);if(!$original->query('//article[@data-product-id="'.$chosen[0]['id'].'"]')->length||$original->query('//article[@data-product-id="'.$chosen[1]['id'].'"]')->length)throw new RuntimeException('Visible original results were not preserved.');
 $filtered=$search($ascii,$chosen[1]['manufacturer']);if(!$filtered->query('//article[@data-product-id="'.$chosen[1]['id'].'"]')->length)throw new RuntimeException('Alternative spelling was not tried after the original hit was filtered out.');
 if($search($ascii,null,'en')->query('//article[@data-product-id="'.$chosen[1]['id'].'"]')->length)throw new RuntimeException('German fallback crossed languages.');
 echo "German spelling fallback handles unavailable and filtered index hits; visible originals and language boundaries are preserved.\n";
}finally{
 if($deactivated)$db->executeStatement("UPDATE product SET active=1 WHERE id=UNHEX(:id) AND version_id=UNHEX('0fa91ce3e96a4bc2be4bd9ce752c3425')",['id'=>$chosen[0]['id']]);
 if($docChanged){$engine->mutate('POST','/indexes/'.$index.'/documents',$docs);foreach($docs as $doc)if($engine->request('GET','/indexes/'.$index.'/documents/'.$doc['id'])!=$doc)throw new RuntimeException('Original document was not restored.');}
 $kernel->shutdown();
}
