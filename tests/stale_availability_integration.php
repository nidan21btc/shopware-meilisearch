<?php declare(strict_types=1);
// Verify Shopware's current availability against an intentionally stale index.
use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Adapter\Kernel\KernelFactory;
use Shopware\Core\System\SystemConfig\SystemConfigService;
$dbUrl=parse_url(getenv('DATABASE_URL')?:'');$shop=parse_url(getenv('EL_SEARCH_TEST_URL')?:'');$host=getenv('EL_SEARCH_TEST_HOST')?:'';
if(!is_array($dbUrl)||!preg_match('/^\/el_search_test_[a-f0-9]+$/D',$dbUrl['path']??'')||!in_array($dbUrl['host']??'', ['localhost','127.0.0.1'],true)
 ||!is_array($shop)||($shop['scheme']??'')!=='http'||($shop['host']??'')!=='127.0.0.1'||!preg_match('/^[a-zA-Z0-9.-]+$/D',$host))throw new RuntimeException('Isolated database and loopback storefront required.');
foreach(['PROJECT_ROOT','DATABASE_URL','APP_URL','SHOPWARE_MEILISEARCH_URL','SHOPWARE_MEILISEARCH_KEY'] as $name)if(getenv($name)!==false)$_SERVER[$name]=$_ENV[$name]=getenv($name);
$root=getenv('PROJECT_ROOT')?:'';if(!is_file($root.'/vendor/autoload.php'))throw new RuntimeException('Test project root required.');
$loader=require $root.'/vendor/autoload.php';$kernel=KernelFactory::create('dev',true,$loader);$kernel->boot();$container=$kernel->getContainer();$db=$container->get(Connection::class);$config=$container->get(SystemConfigService::class);
if((int)$db->fetchOne('SELECT COUNT(*) FROM app WHERE active=1')||(int)$db->fetchOne('SELECT COUNT(*) FROM webhook WHERE active=1'))throw new RuntimeException('Apps and webhooks must be disabled.');
$rows=$db->fetchAllAssociative("SELECT DISTINCT LOWER(HEX(d.sales_channel_id)) channel,o.code locale FROM sales_channel_domain d JOIN language l ON l.id=d.language_id JOIN locale o ON o.id=l.locale_id JOIN sales_channel s ON s.id=d.sales_channel_id WHERE s.active=1 AND o.code IN ('de-DE','en-GB','es-ES','fr-FR','it-IT')");
if(count($rows)!==5||count(array_unique(array_column($rows,'channel')))!==1)throw new RuntimeException('Five locales in one test channel required.');
$channel=$rows[0]['channel'];$locales=array_column($rows,'locale');
$key='core.listing.hideCloseoutProductsWhenOutOfStock';
if((int)$db->fetchOne('SELECT COUNT(*) FROM system_config WHERE configuration_key=:key AND sales_channel_id IS NOT NULL',['key'=>$key]))throw new RuntimeException('No channel-specific override allowed.');
$setting=$db->fetchAssociative('SELECT configuration_value FROM system_config WHERE configuration_key=:key AND sales_channel_id IS NULL',['key'=>$key]);$originalSetting=$setting===false?null:json_decode($setting['configuration_value'],true,512,JSON_THROW_ON_ERROR)['_value'];
$fetch=static function(string $locale,string $number,string $id)use($host):?DOMXPath{
 $ctx=stream_context_create(['http'=>['header'=>"Host: $host\r\nX-Requested-With: XMLHttpRequest\r\n",'timeout'=>60,'follow_location'=>0,'max_redirects'=>0]]);
 $html=file_get_contents(rtrim(getenv('EL_SEARCH_TEST_URL'),'/').'/'.substr($locale,0,2).'/shopware-meilisearch?'.http_build_query(['q'=>$number]),false,$ctx);
 if($html===false||!str_contains($html,'data-el-result-status'))throw new RuntimeException('Local storefront unavailable.');$dom=new DOMDocument();@$dom->loadHTML($html);$xpath=new DOMXPath($dom);return $xpath->query('//article[@data-product-id="'.$id.'"]')->length ? $xpath : null;
};
$candidates=$db->fetchAllAssociative("SELECT LOWER(HEX(p.id)) id,p.product_number number,p.active,p.stock,p.available,p.is_closeout,p.min_purchase,LOWER(HEX(v.id)) visibility_id,v.visibility FROM product p JOIN product_visibility v ON v.product_id=p.id AND v.product_version_id=p.version_id WHERE p.version_id=UNHEX('0fa91ce3e96a4bc2be4bd9ce752c3425') AND p.active=1 AND p.parent_id IS NULL AND p.available=1 AND v.sales_channel_id=UNHEX(:channel) AND v.visibility>=20 AND NOT EXISTS(SELECT 1 FROM product c WHERE c.parent_id=p.id AND c.parent_version_id=p.version_id) LIMIT 100",['channel'=>$channel]);
$product=null;foreach($candidates as $candidate){$visible=true;foreach($locales as $locale)if(!$fetch($locale,$candidate['number'],$candidate['id'])){$visible=false;break;}if($visible){$product=$candidate;break;}}
if($product===null)throw new RuntimeException('A visible product in all five locales is required.');
$assert=static function(bool $expected)use($locales,$product,$fetch):void {foreach($locales as $locale){if((bool)$fetch($locale,$product['number'],$product['id'])!==$expected)throw new RuntimeException('Current availability mismatch for '.$locale);}};
try {
 $db->executeStatement("UPDATE product SET active=0 WHERE id=UNHEX(:id) AND version_id=UNHEX('0fa91ce3e96a4bc2be4bd9ce752c3425')",['id'=>$product['id']]);$assert(false);
 $db->executeStatement("UPDATE product SET active=:active WHERE id=UNHEX(:id) AND version_id=UNHEX('0fa91ce3e96a4bc2be4bd9ce752c3425')",['id'=>$product['id'],'active'=>$product['active']]);$assert(true);
 $db->executeStatement('UPDATE product_visibility SET visibility=10 WHERE id=UNHEX(:id)',['id'=>$product['visibility_id']]);$assert(false);
 $db->executeStatement('UPDATE product_visibility SET visibility=:visibility WHERE id=UNHEX(:id)',['id'=>$product['visibility_id'],'visibility'=>$product['visibility']]);$assert(true);
 $db->executeStatement("UPDATE product SET is_closeout=1,available=0,stock=0,min_purchase=1 WHERE id=UNHEX(:id) AND version_id=UNHEX('0fa91ce3e96a4bc2be4bd9ce752c3425')",['id'=>$product['id']]);
 $config->set($key,true);$assert(false);
 $config->set($key,false);$assert(true);
 foreach($locales as $locale){$xpath=$fetch($locale,$product['number'],$product['id']);if($xpath->query('//article[@data-product-id="'.$product['id'].'"]//form[@data-add-to-cart]')->length)throw new RuntimeException('Sold-out closeout product showed an add-to-cart action.');}
 echo "Current activation, search visibility and closeout settings verified across five locales without reindexing.\n";
} finally {
 $db->executeStatement("UPDATE product SET active=:active,stock=:stock,available=:available,is_closeout=:closeout,min_purchase=:min WHERE id=UNHEX(:id) AND version_id=UNHEX('0fa91ce3e96a4bc2be4bd9ce752c3425')",['id'=>$product['id'],'active'=>$product['active'],'stock'=>$product['stock'],'available'=>$product['available'],'closeout'=>$product['is_closeout'],'min'=>$product['min_purchase']]);
 $db->executeStatement('UPDATE product_visibility SET visibility=:visibility WHERE id=UNHEX(:id)',['id'=>$product['visibility_id'],'visibility'=>$product['visibility']]);
 if($setting===false)$config->delete($key);else $config->set($key,$originalSetting);
 $restored=$db->fetchAssociative("SELECT p.active,p.stock,p.available,p.is_closeout,p.min_purchase,v.visibility FROM product p JOIN product_visibility v ON v.id=UNHEX(:visibility) WHERE p.id=UNHEX(:id) AND p.version_id=UNHEX('0fa91ce3e96a4bc2be4bd9ce752c3425')",['id'=>$product['id'],'visibility'=>$product['visibility_id']]);
 foreach(['active','stock','available','is_closeout','min_purchase','visibility'] as $field)if($restored===false||$restored[$field]!==$product[$field])throw new RuntimeException('Test product state was not restored.');
 $savedSetting=$db->fetchAssociative('SELECT configuration_value FROM system_config WHERE configuration_key=:key AND sales_channel_id IS NULL',['key'=>$key]);
 if(($setting===false)!==($savedSetting===false)||($savedSetting!==false&&json_decode($savedSetting['configuration_value'],true,512,JSON_THROW_ON_ERROR)['_value']!==$originalSetting))throw new RuntimeException('Test configuration was not restored.');
 $assert(true);
 $kernel->shutdown();
 echo "Test product, visibility and configuration restored.\n";
}
