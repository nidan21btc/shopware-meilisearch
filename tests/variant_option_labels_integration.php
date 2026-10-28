<?php declare(strict_types=1);
// Read-only checks of language-local variant options and missing-translation identifiers.
use Doctrine\DBAL\Connection;
use ShopwareMeilisearch\Search\QuantityUnits;
use Shopware\Core\Framework\Adapter\Kernel\KernelFactory;
$database=parse_url(getenv('DATABASE_URL')?:'');$shop=parse_url(getenv('EL_SEARCH_TEST_URL')?:'');$host=getenv('EL_SEARCH_TEST_HOST')?:'';
if(!is_array($database)||!preg_match('/^\/el_search_test_[a-f0-9]+$/D',$database['path']??'')||!in_array($database['host']??'', ['localhost','127.0.0.1'],true)
 ||!is_array($shop)||($shop['scheme']??'')!=='http'||($shop['host']??'')!=='127.0.0.1'||!preg_match('/^[a-zA-Z0-9.-]+$/D',$host))throw new RuntimeException('Isolated database and loopback test storefront required.');
foreach(['PROJECT_ROOT','DATABASE_URL','APP_URL','SHOPWARE_MEILISEARCH_URL','SHOPWARE_MEILISEARCH_KEY'] as $name)if(getenv($name)!==false)$_SERVER[$name]=$_ENV[$name]=getenv($name);
$root=getenv('PROJECT_ROOT')?:'';if(!is_file($root.'/vendor/autoload.php'))throw new RuntimeException('Test project root required.');
$loader=require $root.'/vendor/autoload.php';$kernel=KernelFactory::create('dev',true,$loader);$kernel->boot();$db=$kernel->getContainer()->get(Connection::class);
$domains=$db->fetchAllAssociative("SELECT DISTINCT LOWER(HEX(d.sales_channel_id)) channel,LOWER(HEX(d.language_id)) language,o.code locale FROM sales_channel_domain d JOIN language l ON l.id=d.language_id JOIN locale o ON o.id=l.locale_id JOIN sales_channel s ON s.id=d.sales_channel_id WHERE s.active=1 AND o.code IN ('de-DE','en-GB','es-ES','fr-FR','it-IT')");
if(count($domains)!==5)throw new RuntimeException('Five test languages required.');$de=null;foreach($domains as $domain)if($domain['locale']==='de-DE')$de=$domain['language'];if(!$de)throw new RuntimeException('German test language required.');
$fetch=static function(string $locale,string $number,string $id)use($host):?string{
 $ctx=stream_context_create(['http'=>['header'=>"Host: $host\r\nX-Requested-With: XMLHttpRequest\r\n",'timeout'=>60,'follow_location'=>0,'max_redirects'=>0]]);
 $html=file_get_contents(rtrim(getenv('EL_SEARCH_TEST_URL'),'/').'/'.substr($locale,0,2).'/shopware-meilisearch?'.http_build_query(['q'=>$number]),false,$ctx);
 if($html===false||!str_contains($html,'data-el-result-status'))throw new RuntimeException('Local storefront unavailable.');$dom=new DOMDocument();@$dom->loadHTML('<?xml encoding="UTF-8">'.$html);$xpath=new DOMXPath($dom);
 $card=$xpath->query('//article[@data-product-id="'.$id.'"]')->item(0);if(!$card)return null;
 $node=$xpath->query('.//*[contains(concat(" ", normalize-space(@class), " "), " el-search__variant-options ")]',$card)->item(0);return $node?trim($node->textContent):'';
};
foreach($domains as $domain){
 $candidates=$db->fetchAllAssociative("SELECT LOWER(HEX(p.id)) id,p.product_number number,t.name option_name,COALESCE(pt.name,parent.name) product_name FROM product p JOIN product_option po ON po.product_id=p.id AND po.product_version_id=p.version_id JOIN property_group_option_translation t ON t.property_group_option_id=po.property_group_option_id AND t.language_id=UNHEX(:lang1) LEFT JOIN product_translation pt ON pt.product_id=p.id AND pt.product_version_id=p.version_id AND pt.language_id=UNHEX(:lang2) LEFT JOIN product_translation parent ON parent.product_id=p.parent_id AND parent.product_version_id=p.parent_version_id AND parent.language_id=UNHEX(:lang3) WHERE p.version_id=UNHEX('0fa91ce3e96a4bc2be4bd9ce752c3425') AND p.parent_id IS NOT NULL AND p.active=1 AND t.name<>'' AND COALESCE(pt.name,parent.name)<>'' LIMIT 500",['lang1'=>$domain['language'],'lang2'=>$domain['language'],'lang3'=>$domain['language']]);
 $shown=false;foreach($candidates as $candidate){
  $value=trim($candidate['option_name']);$productName=$candidate['product_name'];if(QuantityUnits::queryKey($value)!==null||preg_match('/(?<![\p{L}\p{N}])'.preg_quote($value,'/').'(?![\p{L}\p{N}])/iu',$productName))continue;
  $label=$fetch($domain['locale'],$candidate['number'],$candidate['id']);if($label===null)continue;
  if(!str_contains($label,$value))throw new RuntimeException('Current-language option is missing in '.$domain['locale']);$shown=true;break;
 }
 if(!$shown)throw new RuntimeException('Visible translated option required in '.$domain['locale']);
 $missingChecked=$domain['locale']==='de-DE';
 if(!$missingChecked){
  $missing=$db->fetchAllAssociative("SELECT LOWER(HEX(p.id)) id,p.product_number number,german.name foreign_name FROM product p JOIN product_option po ON po.product_id=p.id AND po.product_version_id=p.version_id JOIN property_group_option_translation german ON german.property_group_option_id=po.property_group_option_id AND german.language_id=UNHEX(:german) LEFT JOIN property_group_option_translation local ON local.property_group_option_id=po.property_group_option_id AND local.language_id=UNHEX(:local1) LEFT JOIN product_translation pt ON pt.product_id=p.id AND pt.product_version_id=p.version_id AND pt.language_id=UNHEX(:local2) LEFT JOIN product_translation parent ON parent.product_id=p.parent_id AND parent.product_version_id=p.parent_version_id AND parent.language_id=UNHEX(:local3) WHERE p.version_id=UNHEX('0fa91ce3e96a4bc2be4bd9ce752c3425') AND p.parent_id IS NOT NULL AND p.active=1 AND (local.name IS NULL OR TRIM(local.name)='') AND german.name<>'' AND COALESCE(pt.name,parent.name)<>'' LIMIT 500",['german'=>$de,'local1'=>$domain['language'],'local2'=>$domain['language'],'local3'=>$domain['language']]);
  foreach($missing as $candidate){$foreign=trim($candidate['foreign_name']);if($foreign===''||str_contains($candidate['number'],$foreign))continue;$label=$fetch($domain['locale'],$candidate['number'],$candidate['id']);if($label===null)continue;
   if(!str_contains($label,$candidate['number'])||str_contains($label,'elSearch.variantNumber'))throw new RuntimeException('Missing option translation lacks a localized item-number fallback in '.$domain['locale']);
   if(str_contains($label,$foreign))continue; // Another valid local option could share the same text; use a different fixture.
   $missingChecked=true;break;
  }
 }
 if(!$missingChecked)throw new RuntimeException('A visible missing-translation option is required in '.$domain['locale']);
 echo $domain['locale']." localized variant options and identifier fallback passed.\n";
}
$kernel->shutdown();
