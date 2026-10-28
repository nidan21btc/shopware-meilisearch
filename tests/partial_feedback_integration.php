<?php declare(strict_types=1);
// Verify click feedback never moves an added partial match above primary results.
use Doctrine\DBAL\Connection;
use ShopwareMeilisearch\Search\Engine;
use ShopwareMeilisearch\Search\QueryClickFeedback;
use Shopware\Core\System\SystemConfig\SystemConfigService;
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

$config=$kernel->getContainer()->get(SystemConfigService::class);$configKey='ShopwareMeilisearch.config.queryClickFeedback';
if((int)$db->fetchOne('SELECT COUNT(*) FROM system_config WHERE configuration_key=:key AND sales_channel_id IS NOT NULL',['key'=>$configKey]))throw new RuntimeException('No channel-specific feedback override allowed.');
$row=$db->fetchAssociative('SELECT configuration_value FROM system_config WHERE configuration_key=:key AND sales_channel_id IS NULL',['key'=>$configKey]);
$saved=$row===false?null:json_decode($row['configuration_value'],true,512,JSON_THROW_ON_ERROR)['_value'];
$secret=getenv('SHOPWARE_MEILISEARCH_KEY')?:'';
$token=static fn()=>strtr(bin2hex(random_bytes(8)),'0123456789abcdef','abcdefghijklmnop');$first='zq'.$token();$second='yx'.$token();$query=$first.' '.$second;
$getIds=static function(DOMXPath $result):array{$ids=[];foreach($result->query('//article[@data-product-id]') as $card)$ids[]=$card->getAttribute('data-product-id');return $ids;};
$signal=static function(string $product)use($secret,$query):string{return json_encode(['version'=>1,'keyCheck'=>QueryClickFeedback::keyCheck($secret),'locales'=>['de-DE'=>[QueryClickFeedback::fingerprint('de-DE',$query,$secret)=>[$product=>5]]]],JSON_THROW_ON_ERROR);};
$original=[];$indexed=false;
try{
 $config->set($configKey,'');
 // Five complete matches and one supplemental product: feedback for the latter
 // must not move it before any complete match.
 $original=array_merge(array_slice($docs,0,5),[$targetDoc]);$modified=$original;
 foreach($modified as $i=>&$doc)$doc['name']=$i<5?$query:$second;unset($doc);
 $indexed=true;$engine->mutate('POST','/indexes/'.$index.'/documents',$modified);
 $response=$engine->request('POST','/indexes/'.$index.'/search',['q'=>$query,'page'=>1,'hitsPerPage'=>10000,'matchingStrategy'=>'frequency','attributesToRetrieve'=>['id']]);
 if(in_array($target['id'],array_column($response['hits']??[],'id'),true))throw new RuntimeException('The supplemental fixture must be absent from the primary search.');
 $before=$getIds($search($query));$targetRank=array_search($target['id'],$before,true);
 if($targetRank===false||$targetRank<5)throw new RuntimeException('At least five visible primary cards must precede the supplemental product.');
 $config->set($configKey,$signal($target['id']));$after=$getIds($search($query));
 $afterRank=array_search($target['id'],$after,true);
 if($afterRank===false||$afterRank<5||array_slice($after,0,5)!==array_slice($before,0,5))throw new RuntimeException('Feedback promoted a supplemental hit ahead of primary products.');
 $engine->mutate('POST','/indexes/'.$index.'/documents',$original);$indexed=false;
 // A manufacturer filter removes the only primary result. Feedback should
 // still improve the ordering among eligible supplemental products.
 $config->set($configKey,'');$original=array_merge([$seedDoc],array_slice($docs,0,8));$modified=$original;
 foreach($modified as $i=>&$doc)$doc['name']=$i===0?$query:$second;unset($doc);
 $indexed=true;$engine->mutate('POST','/indexes/'.$index.'/documents',$modified);
 $primary=$engine->request('POST','/indexes/'.$index.'/search',['q'=>$query,'page'=>1,'hitsPerPage'=>10000,'matchingStrategy'=>'frequency','attributesToRetrieve'=>['id']]);
 if(count($primary['hits']??[])!==1||($primary['hits'][0]['id']??null)!==$seed['id'])throw new RuntimeException('The filtered fixture must contain one distinct primary result.');
 $beforeFiltered=$getIds($search($query,$group));
 if(count($beforeFiltered)<6||in_array($seed['id'],$beforeFiltered,true))throw new RuntimeException('The filter must leave at least six supplemental products and no primary product.');
 $promoted=$beforeFiltered[5];$config->set($configKey,$signal($promoted));$afterFiltered=$getIds($search($query,$group));
 if(array_slice($beforeFiltered,0,3)!==array_slice($afterFiltered,0,3)||($afterFiltered[3]??null)!==$promoted)throw new RuntimeException('Feedback did not order supplemental products after all primary matches were filtered out.');
 echo "Feedback keeps primary matches before supplemental results and still orders filtered supplemental-only results.\n";
}finally{
 if($indexed){$engine->mutate('POST','/indexes/'.$index.'/documents',$original);foreach($original as $doc)if($engine->request('GET','/indexes/'.$index.'/documents/'.$doc['id'])!=$doc)throw new RuntimeException('Original documents were not restored.');}
 if($saved===null)$config->delete($configKey);else $config->set($configKey,$saved);
 $kernel->shutdown();
}
