<?php declare(strict_types=1);
// Tests buy/detail actions in a disposable shop; all product/config changes are restored.
use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Adapter\Kernel\KernelFactory;
use Shopware\Core\System\SystemConfig\SystemConfigService;

$parts=parse_url(getenv('DATABASE_URL')?:'');
if (!is_array($parts) || !preg_match('/^\/el_search_test_[a-f0-9]+$/D',$parts['path']??'')) throw new RuntimeException('Isolated database required.');
foreach (['PROJECT_ROOT','DATABASE_URL','APP_URL','SHOPWARE_MEILISEARCH_URL','SHOPWARE_MEILISEARCH_KEY'] as $name) if (getenv($name)!==false) $_SERVER[$name]=$_ENV[$name]=getenv($name);
$origin=getenv('EL_SEARCH_TEST_URL')?:'';$host=getenv('EL_SEARCH_TEST_HOST')?:'';$target=parse_url($origin);
if (!is_array($target) || ($target['scheme']??'')!=='http' || ($target['host']??'')!=='127.0.0.1' || !preg_match('/^[a-zA-Z0-9.-]+$/D',$host)) throw new RuntimeException('Loopback test URL and host required.');
$root=getenv('PROJECT_ROOT')?:'';if(!is_file($root.'/vendor/autoload.php'))throw new RuntimeException('Isolated project root required.');
$loader=require $root.'/vendor/autoload.php';$kernel=KernelFactory::create('dev',true,$loader);$kernel->boot();$container=$kernel->getContainer();$db=$container->get(Connection::class);$config=$container->get(SystemConfigService::class);
if ((int)$db->fetchOne('SELECT COUNT(*) FROM app WHERE active=1') || (int)$db->fetchOne('SELECT COUNT(*) FROM webhook WHERE active=1')) throw new RuntimeException('Apps and webhooks must be disabled.');
$keys=['core.listing.allowBuyInListing','core.listing.hideCloseoutProductsWhenOutOfStock'];$saved=[];
foreach($keys as $key){
 if((int)$db->fetchOne('SELECT COUNT(*) FROM system_config WHERE configuration_key=:key AND sales_channel_id IS NOT NULL',['key'=>$key]))throw new RuntimeException('No channel-specific overrides allowed in this isolated test.');
 $row=$db->fetchAssociative('SELECT configuration_value FROM system_config WHERE configuration_key=:key AND sales_channel_id IS NULL',['key'=>$key]);
 $saved[$key]=$row===false ? null : json_decode($row['configuration_value'],true,512,JSON_THROW_ON_ERROR)['_value'];
}
$fetch=static function(string $number,string $id) use($origin,$host):?DOMXPath {
 $ctx=stream_context_create(['http'=>['header'=>"Host: $host\r\nX-Requested-With: XMLHttpRequest\r\n",'timeout'=>30, 'follow_location'=>0, 'max_redirects'=>0]]);
 $html=file_get_contents(rtrim($origin,'/').'/de/shopware-meilisearch?'.http_build_query(['q'=>$number]),false,$ctx);
 if($html===false)throw new RuntimeException('Test storefront unavailable.');$dom=new DOMDocument();@$dom->loadHTML($html);$xpath=new DOMXPath($dom);
 return $xpath->query('//article[@data-product-id="'.$id.'"]')->length ? $xpath : null;
};
$rows=$db->fetchAllAssociative("SELECT LOWER(HEX(p.id)) id,p.product_number number,p.stock,p.min_purchase,p.is_closeout FROM product p WHERE p.version_id=UNHEX('0fa91ce3e96a4bc2be4bd9ce752c3425') AND p.active=1 AND p.parent_id IS NOT NULL AND COALESCE(p.child_count,0)=0 LIMIT 100");
$chosen=null;
try {
 $config->set($keys[0],true);$config->set($keys[1],false);
 foreach($rows as $row){$xpath=$fetch($row['number'],$row['id']);if($xpath && $xpath->query('//article[@data-product-id="'.$row['id'].'"]//form[@data-add-to-cart]')->length){$chosen=$row;break;}}
 if($chosen===null)throw new RuntimeException('No purchasable variant found in the isolated test catalog.');
 $set=static function(int $stock) use($db,$chosen):void {$db->executeStatement("UPDATE product SET stock=:stock,min_purchase=2,is_closeout=1 WHERE id=UNHEX(:id) AND version_id=UNHEX('0fa91ce3e96a4bc2be4bd9ce752c3425')",['stock'=>$stock,'id'=>$chosen['id']]);};
 $set(2);$available=$fetch($chosen['number'],$chosen['id']);
 if(!$available)throw new RuntimeException('Available test variant missing.');
 $form='//article[@data-product-id="'.$chosen['id'].'"]//form[@data-add-to-cart]';
 if($available->query($form)->length!==1 || $available->query($form.'//input[@name="lineItems['.$chosen['id'].'][quantity]" and @value="2"]')->length!==1)throw new RuntimeException('Variant buy action or minimum quantity is wrong.');
 $set(1);$soldOut=$fetch($chosen['number'],$chosen['id']);
 if(!$soldOut || $soldOut->query($form)->length!==0 || $soldOut->query('//article[@data-product-id="'.$chosen['id'].'"]//a[contains(concat(" ",normalize-space(@class)," ")," btn-detail ")]')->length!==1)throw new RuntimeException('Unavailable variant should lead to product details instead of direct purchase.');
 echo "Variant purchase action, minimum quantity and out-of-stock detail action verified.\n";
} finally {
 if($chosen!==null)$db->executeStatement("UPDATE product SET stock=:stock,min_purchase=:min,is_closeout=:closeout WHERE id=UNHEX(:id) AND version_id=UNHEX('0fa91ce3e96a4bc2be4bd9ce752c3425')",['stock'=>$chosen['stock'],'min'=>$chosen['min_purchase'],'closeout'=>$chosen['is_closeout'],'id'=>$chosen['id']]);
 foreach($saved as $key=>$value){if($value===null)$config->delete($key);else $config->set($key,$value);}
 if($chosen!==null){
  $restored=$db->fetchAssociative("SELECT stock,min_purchase,is_closeout FROM product WHERE id=UNHEX(:id) AND version_id=UNHEX('0fa91ce3e96a4bc2be4bd9ce752c3425')",['id'=>$chosen['id']]);
  foreach(['stock','min_purchase','is_closeout'] as $field)if($restored===false || $restored[$field]!==$chosen[$field])throw new RuntimeException('Test product was not restored.');
 }
 foreach($saved as $key=>$value){
  $row=$db->fetchAssociative('SELECT configuration_value FROM system_config WHERE configuration_key=:key AND sales_channel_id IS NULL',['key'=>$key]);
  $restored=$row===false ? null : json_decode($row['configuration_value'],true,512,JSON_THROW_ON_ERROR)['_value'];
  if($restored!==$value)throw new RuntimeException('Test configuration was not restored.');
 }
 $kernel->shutdown();
}
