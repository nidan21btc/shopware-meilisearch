<?php declare(strict_types=1);
// Read-only check of visible size labels for same-name variants in five languages.
use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Adapter\Kernel\KernelFactory;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use ShopwareMeilisearch\Search\QuantityUnits;

$database=parse_url(getenv('DATABASE_URL')?:'');$shop=parse_url(getenv('EL_SEARCH_TEST_URL')?:'');$host=getenv('EL_SEARCH_TEST_HOST')?:'';
if(!is_array($database)||!preg_match('/^\/el_search_test_[a-f0-9]+$/D',$database['path']??'')||!in_array($database['host']??'', ['localhost','127.0.0.1'],true)
 ||!is_array($shop)||($shop['scheme']??'')!=='http'||($shop['host']??'')!=='127.0.0.1'||!preg_match('/^[a-zA-Z0-9.-]+$/D',$host))throw new RuntimeException('Isolated database and loopback test storefront required.');
foreach(['PROJECT_ROOT','DATABASE_URL','APP_URL','SHOPWARE_MEILISEARCH_URL','SHOPWARE_MEILISEARCH_KEY'] as $name)if(getenv($name)!==false)$_SERVER[$name]=$_ENV[$name]=getenv($name);
$root=getenv('PROJECT_ROOT')?:'';if(!is_file($root.'/vendor/autoload.php'))throw new RuntimeException('Test project root required.');
$loader=require $root.'/vendor/autoload.php';$kernel=KernelFactory::create('dev',true,$loader);$kernel->boot();$container=$kernel->getContainer();$db=$container->get(Connection::class);$config=$container->get(SystemConfigService::class);
$domains=$db->fetchAllAssociative("SELECT DISTINCT LOWER(HEX(d.sales_channel_id)) channel,LOWER(HEX(d.language_id)) language,o.code locale FROM sales_channel_domain d JOIN language l ON l.id=d.language_id JOIN locale o ON o.id=l.locale_id JOIN sales_channel s ON s.id=d.sales_channel_id WHERE s.active=1 AND o.code IN ('de-DE','en-GB','es-ES','fr-FR','it-IT')");
if(count($domains)!==5)throw new RuntimeException('Five test languages required.');
$unit='00000000000000000000000000100031';
foreach($domains as $domain){
 $mapping=QuantityUnits::parse($config->getString('ShopwareMeilisearch.config.quantityUnits',$domain['channel']));
 if(($mapping[$unit]['symbol']??null)!=='ml'||($mapping[$unit]['factor']??null)!==1000)throw new RuntimeException('The litre-to-millilitre test mapping is required.');
 $pairs=$db->fetchAllAssociative("SELECT LOWER(HEX(a.id)) a,LOWER(HEX(b.id)) b,a.purchase_unit amount_a,b.purchase_unit amount_b,COALESCE(at.name,pt.name) name
 FROM product a JOIN product b ON b.parent_id=a.parent_id AND b.parent_version_id=a.parent_version_id AND b.id>a.id
 LEFT JOIN product_translation at ON at.product_id=a.id AND at.product_version_id=a.version_id AND at.language_id=UNHEX(:lang1)
 LEFT JOIN product_translation bt ON bt.product_id=b.id AND bt.product_version_id=b.version_id AND bt.language_id=UNHEX(:lang2)
 LEFT JOIN product_translation pt ON pt.product_id=a.parent_id AND pt.product_version_id=a.parent_version_id AND pt.language_id=UNHEX(:lang3)
 WHERE a.version_id=UNHEX('0fa91ce3e96a4bc2be4bd9ce752c3425') AND b.version_id=a.version_id AND a.parent_id IS NOT NULL AND a.active=1 AND b.active=1
 AND a.unit_id=UNHEX(:unit1) AND b.unit_id=UNHEX(:unit2) AND a.purchase_unit>0 AND b.purchase_unit>0 AND a.purchase_unit<>b.purchase_unit
 AND COALESCE(at.name,pt.name)=COALESCE(bt.name,pt.name) AND COALESCE(at.name,pt.name)<>'' LIMIT 300",['lang1'=>$domain['language'],'lang2'=>$domain['language'],'lang3'=>$domain['language'],'unit1'=>$unit,'unit2'=>$unit]);
 $checked=false;
 foreach($pairs as $pair){
  if(mb_strlen($pair['name'])>150||QuantityUnits::queryKey($pair['name'])!==null)continue;
  $ctx=stream_context_create(['http'=>['header'=>"Host: $host\r\nX-Requested-With: XMLHttpRequest\r\n",'timeout'=>60,'follow_location'=>0,'max_redirects'=>0]]);
  $html=file_get_contents(rtrim(getenv('EL_SEARCH_TEST_URL'),'/').'/'.substr($domain['locale'],0,2).'/shopware-meilisearch?'.http_build_query(['q'=>$pair['name']]),false,$ctx);
  if($html===false||!str_contains($html,'data-el-result-status'))throw new RuntimeException('Local storefront unavailable.');
  $dom=new DOMDocument();@$dom->loadHTML('<?xml encoding="UTF-8">'.$html);$xpath=new DOMXPath($dom);
  $a=$xpath->query('//article[@data-product-id="'.$pair['a'].'"]')->item(0);$b=$xpath->query('//article[@data-product-id="'.$pair['b'].'"]')->item(0);if(!$a||!$b)continue;
  foreach([[$a,$pair['amount_a']],[$b,$pair['amount_b']]] as [$card,$amount]){
   $label=$xpath->query('.//*[contains(concat(" ", normalize-space(@class), " "), " el-search__variant-quantity ")]',$card)->item(0);
   $number=rtrim(rtrim(number_format((float)$amount*1000,8,'.',''),'0'),'.');$separator=$domain['locale']==='en-GB'?'.':',';$expected=str_replace('.',$separator,$number).' ml';
   if(!$label||trim($label->textContent)!==$expected)throw new RuntimeException('Displayed variant size differs from the catalog in '.$domain['locale']);
  }
  $checked=true;break;
 }
 if(!$checked)throw new RuntimeException('Two visible same-name variants with different sizes are required in '.$domain['locale']);
 echo $domain['locale']." same-name variant quantities passed.\n";
}
$kernel->shutdown();
