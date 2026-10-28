<?php declare(strict_types=1);

// Verify calculated prices and filters in Shopware-resolved customer contexts.
// A local transaction temporarily assigns one test-copy customer to each existing group.
use Doctrine\DBAL\Connection;
use ShopwareMeilisearch\Controller\SearchController;
use ShopwareMeilisearch\Search\SearchInput;
use Shopware\Core\Checkout\Cart\Price\Struct\CalculatedPrice;
use Shopware\Core\Checkout\Cart\Price\Struct\PriceCollection;
use Shopware\Core\Content\Product\SalesChannel\ProductAvailableFilter;
use Shopware\Core\Content\Product\DataAbstractionLayer\VariantListingConfig;
use Shopware\Core\Framework\Adapter\Kernel\KernelFactory;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Uuid\Uuid;

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
$repository = $container->get('sales_channel.product.repository');
$ids = $db->fetchFirstColumn("SELECT LOWER(HEX(id)) FROM product WHERE version_id=UNHEX('0fa91ce3e96a4bc2be4bd9ce752c3425')");

function prices($repository, $context, array $ids, bool $partial): array
{
    $values = [];
    $start = microtime(true);
    foreach (array_chunk($ids, 200) as $batch) {
        $criteria = new Criteria($batch);
        $criteria->addFilter(new ProductAvailableFilter($context->getSalesChannelId(), 20));
        if ($partial) $criteria->addFields(['id', 'parentId', 'childCount', 'variantListingConfig', 'calculatedPrice', 'calculatedPrices', 'calculatedCheapestPrice',
            'prices.ruleId', 'prices.price', 'prices.quantityStart', 'prices.quantityEnd']);
        foreach ($repository->search($criteria, $context)->getEntities() as $product) {
            $prices = $product->get('calculatedPrices');
            $price = ($prices instanceof PriceCollection ? $prices->last() : null) ?? $product->get('calculatedPrice');
            $listing = $product->get('variantListingConfig');
            $cheapest = $product->get('calculatedCheapestPrice');
            if ($product->get('parentId') === null && $listing instanceof VariantListingConfig && $listing->getDisplayParent() && $cheapest instanceof CalculatedPrice) $price = $cheapest;
            if (!$price instanceof CalculatedPrice) throw new RuntimeException('Missing calculated price.');
            $values[$product->getId()] = $price->getUnitPrice();
        }
    }
    return [$values, microtime(true) - $start];
}


if (!in_array($url['host'] ?? '', ['127.0.0.1', 'localhost'], true)
    || (int) $db->fetchOne('SELECT COUNT(*) FROM app WHERE active=1') !== 0
    || (int) $db->fetchOne('SELECT COUNT(*) FROM webhook WHERE active=1') !== 0) {
    throw new RuntimeException('Local copy with apps and webhooks disabled required.');
}
$engineUrl=parse_url(getenv('SHOPWARE_MEILISEARCH_URL')?:'');
if(!is_array($engineUrl)||($engineUrl['scheme']??'')!=='http'||($engineUrl['host']??'')!=='127.0.0.1')throw new RuntimeException('Loopback test search service required.');
// Some isolated copies intentionally have no application secret. Shopware's
// cart service needs one even for an empty cart; this value is process-local.
if (!(getenv('APP_SECRET') ?: ($_ENV['APP_SECRET'] ?? ''))) {
    $_ENV['APP_SECRET'] = $_SERVER['APP_SECRET'] = 'isolated-search-test-only-secret';
    putenv('APP_SECRET=isolated-search-test-only-secret');
}
$customer=$db->fetchOne("SELECT LOWER(HEX(id)) FROM customer WHERE active=1 AND (bound_sales_channel_id IS NULL OR bound_sales_channel_id=UNHEX(:channel)) LIMIT 1",['channel'=>$domain['channel']]);
$groups=$db->fetchFirstColumn('SELECT LOWER(HEX(id)) FROM customer_group');
if(!$customer || count($groups)<2)throw new RuntimeException('An active test customer and multiple customer groups are required.');
$priceRules=$db->fetchFirstColumn('SELECT DISTINCT LOWER(HEX(rule_id)) FROM product_price WHERE rule_id IS NOT NULL');
$previewController=$container->get(Shopware\Administration\Controller\AdminProductStreamController::class);
$contextService=(new ReflectionProperty(Shopware\Administration\Controller\AdminProductStreamController::class,'salesChannelContextService'))->getValue($previewController);
$controller=$container->get(SearchController::class);
$search=(new ReflectionProperty(SearchController::class,'search'))->getValue($controller);
$checked=0;$baseline=null;
$db->beginTransaction();
try {
 foreach($groups as $group){
  $db->executeStatement('UPDATE customer SET customer_group_id=UNHEX(:group) WHERE id=UNHEX(:id)',['group'=>$group,'id'=>$customer]);
  $token=Uuid::randomHex();
  $customerContext=$contextService->get(new Shopware\Core\System\SalesChannel\Context\SalesChannelContextServiceParameters($domain['channel'],$token,$domain['language'],customerId:$customer));
  if($customerContext->getCustomerId()!==$customer || $customerContext->getCurrentCustomerGroup()->getId()!==$group)throw new RuntimeException('Customer group context was not applied.');
  [$full]=prices($repository,$customerContext,$ids,false);[$partial]=prices($repository,$customerContext,$ids,true);
  if($full===[] || count($full)!==count($partial))throw new RuntimeException('Missing customer-context prices.');
  $changed=0;
  foreach($full as $id=>$value)if(!array_key_exists($id,$partial)||abs($partial[$id]-$value)>0.000001)throw new RuntimeException('Customer-context projected price differs.');
  if($baseline===null)$baseline=$full;
  else foreach($full as $id=>$value)if(isset($baseline[$id])&&abs($baseline[$id]-$value)>0.000001)++$changed;
  foreach(['price-asc','price-desc'] as $sort){
   $result=$search->search(new SearchInput('bach',1,$sort,[],[],5.0,40.0,false),$customerContext);
   if($result['products']===[])throw new RuntimeException('Customer-context price-filter search returned no products.');$last=null;
   foreach($result['products'] as $product){
    $price=$product->getCalculatedPrices()->last()??$product->getCalculatedPrice();$listing=$product->getVariantListingConfig();
    if($product->getParentId()===null&&$listing instanceof VariantListingConfig&&$listing->getDisplayParent()&&$product->getCalculatedCheapestPrice() instanceof CalculatedPrice)$price=$product->getCalculatedCheapestPrice();
    if(!$price instanceof CalculatedPrice)throw new RuntimeException('Missing displayed customer-context price.');
    $value=$price->getUnitPrice();
    if(!isset($full[$product->getId()])||abs($full[$product->getId()]-$value)>0.000001||$value<5||$value>40||($last!==null&&($sort==='price-asc'?$value<$last:$value>$last)))throw new RuntimeException('Customer-context price filtering or ordering differs from Shopware.');
    if($result['priceRange']===null||$value<$result['priceRange']['min']||$value>$result['priceRange']['max'])throw new RuntimeException('Customer-context price is outside filter bounds.');
    $last=$value;
   }
  }
  printf("Customer context %d/%d; available products: %d; applicable product-price rules: %d; prices changed from first context: %d; price projection, filter and sorting passed.\n",++$checked,count($groups),count($full),count(array_intersect($priceRules,$customerContext->getRuleIds())),$changed);
 }
} finally { $db->rollBack(); }
$kernel->shutdown();
echo "Temporary customer-group changes rolled back.\n";
