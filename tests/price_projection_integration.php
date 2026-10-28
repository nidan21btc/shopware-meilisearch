<?php declare(strict_types=1);

// Read-only: compare full and field-limited calculated prices in an isolated copy.
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

$rules = $db->fetchFirstColumn('SELECT DISTINCT LOWER(HEX(rule_id)) FROM product_price WHERE rule_id IS NOT NULL');
$scenarios = [[]];
foreach ($rules as $rule) $scenarios[] = [$rule];
foreach ([CartPrice::TAX_STATE_GROSS, CartPrice::TAX_STATE_NET] as $taxState) {
    $context->setTaxState($taxState);
    $baseline = null;
    foreach ($scenarios as $ruleIds) {
        // Synthetic contexts exercise price calculation; they do not claim a
        // real customer satisfies any particular rule.
        $context->setRuleIds($ruleIds);
        [$full, $fullTime] = prices($repository, $context, $ids, false);
        [$partial, $partialTime] = prices($repository, $context, $ids, true);
        if ($baseline === null) {
            $baseline = $full;
            if ($taxState === CartPrice::TAX_STATE_GROSS) $defaultGross = $full;
        }
        $missing = 0;
        $differences = 0;
        $changedFromBaseline = 0;
        foreach ($full as $id => $price) {
            if (!array_key_exists($id, $partial)) ++$missing;
            elseif (abs($partial[$id] - $price) > 0.000001) ++$differences;
            if (isset($baseline[$id]) && abs($baseline[$id] - $price) > 0.000001) ++$changedFromBaseline;
        }
        printf("Tax: %s; rule enabled: %s; products: %d; missing: %d; differing prices: %d; changed from no-rule prices: %d; full: %.2fs; selected fields: %.2fs\n",
            $taxState, $ruleIds === [] ? 'no' : 'yes', count($full), $missing, $differences, $changedFromBaseline, $fullTime, $partialTime);
        if ($missing || $differences || count($full) !== count($partial) || $full === []) throw new RuntimeException('Calculated prices differ.');
    }
}
if (getenv('EL_SEARCH_TEST_CURRENCY') === '1') {
    // An uncommitted synthetic currency exercises conversion without changing
    // the catalog or calling external systems. Roll back on success or failure.
    if (!in_array($url['host'] ?? '', ['127.0.0.1', 'localhost'], true)
        || (int) $db->fetchOne('SELECT COUNT(*) FROM app WHERE active=1') !== 0
        || (int) $db->fetchOne('SELECT COUNT(*) FROM webhook WHERE active=1') !== 0) {
        throw new RuntimeException('The currency test requires a local copy with apps and webhooks disabled.');
    }
    if ((int) $db->fetchOne("SELECT COUNT(*) FROM currency WHERE iso_code='XTS'") !== 0) {
        throw new RuntimeException('The reserved test currency code is already present.');
    }
    $currency = Uuid::randomHex();
    $controller = $container->get(SearchController::class);
    $search = (new ReflectionProperty(SearchController::class, 'search'))->getValue($controller);
    $db->beginTransaction();
    try {
        $db->executeStatement("INSERT INTO currency (id, iso_code, factor, symbol, position, created_at, item_rounding, total_rounding, tax_free_from)
            SELECT UNHEX(:id), 'XTS', 1.7, 'XTS', position, UTC_TIMESTAMP(3), item_rounding, total_rounding, tax_free_from
            FROM currency WHERE iso_code='EUR'", ['id' => $currency]);
        $db->executeStatement('INSERT INTO sales_channel_currency (sales_channel_id, currency_id) VALUES (UNHEX(:channel), UNHEX(:currency))',
            ['channel' => $domain['channel'], 'currency' => $currency]);
        $converted = $factory->create(Uuid::randomHex(), $domain['channel'], [SalesChannelContextService::LANGUAGE_ID => $domain['language'], SalesChannelContextService::CURRENCY_ID => $currency]);
        if ($converted->getCurrencyId() !== $currency || abs($converted->getContext()->getCurrencyFactor() - 1.7) > 0.000001) {
            throw new RuntimeException('The synthetic currency context was not applied.');
        }
        foreach ([CartPrice::TAX_STATE_GROSS, CartPrice::TAX_STATE_NET] as $taxState) {
            $converted->setTaxState($taxState);
            foreach ($scenarios as $ruleIds) {
                $converted->setRuleIds($ruleIds);
                [$full] = prices($repository, $converted, $ids, false);
                [$partial] = prices($repository, $converted, $ids, true);
                $differences = 0;
                $convertedChanges = 0;
                foreach ($full as $id => $price) {
                    if (!array_key_exists($id, $partial) || abs($partial[$id] - $price) > 0.000001) ++$differences;
                    if (isset($defaultGross[$id]) && abs($defaultGross[$id] - $price) > 0.000001) ++$convertedChanges;
                }
                printf("Test currency; tax: %s; rule enabled: %s; products: %d; differing prices: %d\n",
                    $taxState, $ruleIds === [] ? 'no' : 'yes', count($full), $differences);
                if ($differences || count($full) !== count($partial) || $full === []) throw new RuntimeException('Converted prices differ.');
                if ($taxState === CartPrice::TAX_STATE_GROSS && $ruleIds === [] && $convertedChanges === 0) throw new RuntimeException('The synthetic conversion did not change any prices.');
                foreach (['price-asc', 'price-desc'] as $sort) {
                    $result = $search->search(new SearchInput('bach', 1, $sort, [], [], 5.0, 40.0, false), $converted);
                    if ($result['products'] === []) throw new RuntimeException('Synthetic price-filter search returned no products.');
                    $last = null;
                    foreach ($result['products'] as $product) {
                        $prices = $product->getCalculatedPrices();
                        $price = $prices->last() ?? $product->getCalculatedPrice();
                        if (($product->getChildCount() ?? 0) > 0) $price = $product->getCalculatedCheapestPrice();
                        $value = $price->getUnitPrice();
                        if ($value < 5.0 || $value > 40.0 || ($last !== null && ($sort === 'price-asc' ? $value < $last : $value > $last))) {
                            throw new RuntimeException('Search did not filter or sort by the displayed contextual price.');
                        }
                        if ($result['priceRange'] === null || $value < $result['priceRange']['min'] || $value > $result['priceRange']['max']) {
                            throw new RuntimeException('A displayed contextual price is outside the slider bounds.');
                        }
                        $last = $value;
                    }
                }
            }
        }
    } finally {
        $db->rollBack();
    }
}
$kernel->shutdown();
