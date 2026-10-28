<?php declare(strict_types=1);
// Temporarily changes indexed documents in a disposable local test copy.
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
$domains=$db->fetchAllAssociative("SELECT DISTINCT LOWER(HEX(d.sales_channel_id)) channel,LOWER(HEX(d.language_id)) language,o.code locale FROM sales_channel_domain d JOIN language l ON l.id=d.language_id JOIN locale o ON o.id=l.locale_id JOIN sales_channel s ON s.id=d.sales_channel_id WHERE s.active=1 AND o.code IN ('de-DE','en-GB','es-ES','fr-FR','it-IT')");
if(count($domains)!==5)throw new RuntimeException('Five test languages required.');
$engine=new Engine(HttpClient::create(),getenv('SHOPWARE_MEILISEARCH_URL')?:'',getenv('SHOPWARE_MEILISEARCH_KEY')?:'');
$search=static function(string $locale,string $query,?string $similar,int $page=1)use($host):DOMXPath {
 $context=stream_context_create(['http'=>['header'=>"Host: $host\r\nX-Requested-With: XMLHttpRequest\r\n",'timeout'=>60,'follow_location'=>0,'max_redirects'=>0]]);
 $params=['q'=>$query,'page'=>$page];if($similar!==null)$params['similar']=$similar;
 $html=file_get_contents(rtrim(getenv('EL_SEARCH_TEST_URL'),'/').'/'.substr($locale,0,2).'/shopware-meilisearch?'.http_build_query($params),false,$context);
 if($html===false)throw new RuntimeException('Local storefront unavailable.');$dom=new DOMDocument();@$dom->loadHTML($html);return new DOMXPath($dom);
};
$total=static function(DOMXPath $result):int {$node=$result->query('//*[@data-el-result-status]')->item(0);if(!$node||!preg_match('/^\d+/',$node->getAttribute('data-el-result-status'),$m))throw new RuntimeException('Missing result total.');return (int)$m[0];};
$token=static fn()=>strtr(bin2hex(random_bytes(8)),'0123456789abcdef','abcdefghijklmnop');
try {
 foreach($domains as $domain){
  $index=Engine::indexName($domain['channel'],$domain['language']);
  $rows=$db->fetchAllAssociative("SELECT LOWER(HEX(p.id)) id,p.product_number number FROM product p JOIN product_visibility v ON v.product_id=p.id AND v.product_version_id=p.version_id JOIN product_translation t ON t.product_id=p.id AND t.product_version_id=p.version_id AND t.language_id=UNHEX(:language) WHERE p.version_id=UNHEX('0fa91ce3e96a4bc2be4bd9ce752c3425') AND p.active=1 AND p.parent_id IS NULL AND t.name<>'' AND v.sales_channel_id=UNHEX(:channel) AND v.visibility>=20 AND (p.is_closeout=0 OR p.stock>=p.min_purchase) AND NOT EXISTS(SELECT 1 FROM product c WHERE c.parent_id=p.id AND c.parent_version_id=p.version_id) LIMIT 200",['language'=>$domain['language'],'channel'=>$domain['channel']]);
  $chosen=[];$docs=[];foreach($rows as $row){$doc=$engine->request('GET','/indexes/'.$index.'/documents/'.$row['id'],allowMissing:true);if($doc!==null){$chosen[]=$row;$docs[]=$doc;}if(count($chosen)===21)break;}
  if(count($chosen)!==21)throw new RuntimeException('Twenty-one standalone indexed products are required.');
  $word='zq'.$token();$other='yx'.$token();$chars=str_split($word);$chars[3]=$chars[3]==='x'?'z':'x';$chars[8]=$chars[8]==='x'?'z':'x';$weak=implode('',$chars);$query=$word.' '.$other;
  $modified=$docs;foreach($modified as $i=>&$doc){$doc['name']=($i<20?$word:$weak).' '.$other;$doc['popularity']=0;}unset($doc);
  $indexed=false;
  try {
   $indexed=true;$engine->mutate('POST','/indexes/'.$index.'/documents',$modified);
   $hits=$engine->request('POST','/indexes/'.$index.'/search',['q'=>$query,'page'=>1,'hitsPerPage'=>10000,'attributesToRetrieve'=>['id'],'showRankingScoreDetails'=>true,'matchingStrategy'=>'frequency']);
   $typos=[];foreach($hits['hits']??[] as $hit)$typos[$hit['id']]=$hit['_rankingScoreDetails']['typo']['typoCount']??null;
   if(($typos[$chosen[20]['id']]??null)!==2)throw new RuntimeException('Synthetic target did not require two typos.');
   foreach(array_slice($chosen,0,20) as $row)if(($typos[$row['id']]??null)!==0)throw new RuntimeException('Synthetic primary hit was not exact.');
   $default=$search($domain['locale'],$query,null);$narrow=$search($domain['locale'],$query,'0');$expanded=$search($domain['locale'],$query,'1',2);
   if($total($default)!==21||$total($narrow)!==20||$total($expanded)!==21)throw new RuntimeException('Unexpected default or optional precision totals.');
   if(!$default->query('//*[@data-el-similar and @aria-pressed="true"]')->length||!$narrow->query('//*[@data-el-similar and @aria-pressed="false"]')->length)throw new RuntimeException('Precision control state was not rendered.');
   if(!$expanded->query('//article[@data-product-id="'.$chosen[20]['id'].'"]')->length)throw new RuntimeException('The two-typo target was lost in the expanded view.');
   echo $domain['locale']." default preserves two-typo target; narrower view is optional.\n";
  }finally{
   if($indexed){$engine->mutate('POST','/indexes/'.$index.'/documents',$docs);foreach($docs as $doc)if($engine->request('GET','/indexes/'.$index.'/documents/'.$doc['id'])!=$doc)throw new RuntimeException('Original document was not restored.');}
  }
 }
} finally {$kernel->shutdown();}
