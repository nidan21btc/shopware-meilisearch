<?php declare(strict_types=1);

// Compare family reference-price units with the actual cheapest variant.
use Doctrine\DBAL\Connection;
use ShopwareMeilisearch\Controller\SearchController;
use ShopwareMeilisearch\Search\SearchInput;
use Shopware\Core\Checkout\Cart\Price\Struct\CalculatedPrice;
use Shopware\Core\Checkout\Cart\Price\Struct\CartPrice;
use Shopware\Core\Checkout\Cart\Price\Struct\PriceCollection;
use Shopware\Core\Content\Product\SalesChannel\ProductAvailableFilter;
use Shopware\Core\Content\Product\DataAbstractionLayer\VariantListingConfig;
use Shopware\Core\Framework\Adapter\Kernel\KernelFactory;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SalesChannel\Context\CachedSalesChannelContextFactory;
use Shopware\Core\System\SalesChannel\Context\SalesChannelContextService;

$url = parse_url(getenv('DATABASE_URL') ?: '');
if (!is_array($url) || !preg_match('/^\/el_search_test_[a-f0-9]+$/D', $url['path'] ?? '')) {
    throw new RuntimeException('Use an isolated el_search_test_* database.');
}
foreach (['PROJECT_ROOT', 'DATABASE_URL', 'APP_URL', 'SHOPWARE_MEILISEARCH_URL', 'SHOPWARE_MEILISEARCH_KEY'] as $name) {
    if (getenv($name) !== false) $_ENV[$name] = $_SERVER[$name] = getenv($name);
}
$root = getenv('PROJECT_ROOT') ?: '';
if (!is_file($root . '/vendor/autoload.php')) throw new RuntimeException('Set PROJECT_ROOT to the test shop.');
$loader = require $root . '/vendor/autoload.php';
$kernel = KernelFactory::create('dev', true, $loader);
$kernel->boot();
$container = $kernel->getContainer();
$db = $container->get(Connection::class);
$domain = $db->fetchAssociative("SELECT LOWER(HEX(d.sales_channel_id)) channel, LOWER(HEX(d.language_id)) language
    FROM sales_channel_domain d JOIN language l ON l.id=d.language_id JOIN locale o ON o.id=l.locale_id
    JOIN sales_channel s ON s.id=d.sales_channel_id WHERE o.code='de-DE' AND s.active=1 LIMIT 1");
if ($domain === false) throw new RuntimeException('An active German test sales channel is required.');
$factory = $container->get(CachedSalesChannelContextFactory::class);
$context = $factory->create(Uuid::randomHex(), $domain['channel'], [SalesChannelContextService::LANGUAGE_ID => $domain['language']]);
$repository = $container->get('sales_channel.product.repository');
$shop=parse_url(getenv('EL_SEARCH_TEST_URL')?:'');$host=getenv('EL_SEARCH_TEST_HOST')?:'';
if(!is_array($shop)||($shop['scheme']??'')!=='http'||($shop['host']??'')!=='127.0.0.1'||!preg_match('/^[a-zA-Z0-9.-]+$/D',$host))throw new RuntimeException('Loopback test storefront required.');
$controller=$container->get(SearchController::class);$service=(new ReflectionProperty(SearchController::class,'search'))->getValue($controller);
$ids=$db->fetchFirstColumn("SELECT LOWER(HEX(id)) FROM product WHERE version_id=UNHEX('0fa91ce3e96a4bc2be4bd9ce752c3425') AND child_count>0");
$ids=$db->fetchFirstColumn("SELECT LOWER(HEX(id)) FROM product WHERE version_id=UNHEX('0fa91ce3e96a4bc2be4bd9ce752c3425') AND child_count>0");$checked=0;
foreach(array_chunk($ids,200) as $batch){
 $criteria=new Criteria($batch);$criteria->addFilter(new ProductAvailableFilter($context->getSalesChannelId(),20));
 foreach($repository->search($criteria,$context)->getEntities() as $product){
  $config=$product->getVariantListingConfig();$cheapest=$product->getCheapestPrice();$calculated=$product->getCalculatedCheapestPrice();
  if(!$config||!$config->getDisplayParent()||!$cheapest||!$calculated||!$calculated->getReferencePrice()||!$cheapest->getUnitId()||!$product->getUnitId()||$cheapest->getUnitId()===$product->getUnitId())continue;
  $name=$db->fetchOne('SELECT name FROM unit_translation WHERE unit_id=UNHEX(:unit) AND language_id=UNHEX(:language)',['unit'=>$cheapest->getUnitId(),'language'=>$domain['language']]);
  $parentName=$db->fetchOne('SELECT name FROM unit_translation WHERE unit_id=UNHEX(:unit) AND language_id=UNHEX(:language)',['unit'=>$product->getUnitId(),'language'=>$domain['language']]);
  if(!is_string($name)||!in_array($name,['l','L','ml','mL','g','kg'],true)||!is_string($parentName)||$name===$parentName)continue;
  $number=$product->getProductNumber();$result=$service->search(new SearchInput($number,1,'relevance',[],[],null,null,false),$context);
  $match=null;foreach($result['products'] as $candidate)if($candidate->getId()===$product->getId()){$match=$candidate;break;}
  if($match===null)continue;
  $label=$result['referenceUnits'][$product->getId()]??null;
  if(!is_array($label)||$label['unit']!==$name||$label['amount']!==$match->getCalculatedCheapestPrice()->getReferencePrice()->getReferenceUnit())throw new RuntimeException('Family reference unit does not follow the cheapest variant.');
  $http=stream_context_create(['http'=>['header'=>"Host: $host\r\nX-Requested-With: XMLHttpRequest\r\n",'timeout'=>60,'follow_location'=>0,'max_redirects'=>0]]);
  foreach(['de','en','es','fr','it'] as $locale){
   $url=rtrim(getenv('EL_SEARCH_TEST_URL'),'/').'/'.$locale.'/shopware-meilisearch?'.http_build_query(['q'=>$number]);
   $html=file_get_contents($url,false,$http);if($html===false)throw new RuntimeException('Test storefront response failed.');$dom=new DOMDocument();@$dom->loadHTML('<?xml encoding="UTF-8">'.$html);$xpath=new DOMXPath($dom);
   $node=$xpath->query('//article[@data-product-id="'.$product->getId().'"]//*[contains(concat(" ",normalize-space(@class)," ")," el-search__price ")]/small')->item(0);
   if(!$node||!str_ends_with(trim($node->textContent),' '.(string)$label['amount'].' '.$name))throw new RuntimeException('The storefront did not show the cheapest variant reference unit in '.$locale);
  }
  if(++$checked>=5)break 2;
 }
}
if($checked<5)throw new RuntimeException('Five visible parent products with distinct cheapest-variant units are required.');
printf("Family reference units match the cheapest variant for %d visible products in five languages.\n",$checked);$kernel->shutdown();
