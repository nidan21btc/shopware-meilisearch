<?php declare(strict_types=1);

// Mutates a product timestamp via the DAL; only run against a disposable copy.
$url = getenv('DATABASE_URL') ?: '';
$parts = parse_url($url);
if (!is_array($parts) || !preg_match('/^\/el_search_test_[a-f0-9]+$/D', $parts['path'] ?? '')) {
    throw new RuntimeException('Use an explicitly isolated el_search_test_* database.');
}
$root = getenv('PROJECT_ROOT') ?: '';
if ($root === '' || !is_file($root . '/vendor/autoload.php')) {
    throw new RuntimeException('PROJECT_ROOT must point to the isolated Shopware project.');
}
foreach (['PROJECT_ROOT', 'DATABASE_URL', 'APP_URL', 'SHOPWARE_MEILISEARCH_URL', 'SHOPWARE_MEILISEARCH_KEY'] as $name) {
    if (getenv($name) !== false) $_SERVER[$name] = $_ENV[$name] = getenv($name);
}
$loader = require $root . '/vendor/autoload.php';
$kernel = Shopware\Core\Framework\Adapter\Kernel\KernelFactory::create('dev', true, $loader);
$kernel->boot();
$container = $kernel->getContainer();
$connection = $container->get(Doctrine\DBAL\Connection::class);
if ((int) $connection->fetchOne('SELECT COUNT(*) FROM app WHERE active=1') !== 0
    || (int) $connection->fetchOne('SELECT COUNT(*) FROM webhook WHERE active=1') !== 0) {
    throw new RuntimeException('Disable apps and webhooks in the disposable test copy before writing products.');
}
$before = (int) $connection->fetchOne('SELECT revision FROM shopware_meilisearch_state WHERE id=1');
$productId = $connection->fetchOne("SELECT LOWER(HEX(id)) FROM product WHERE version_id=UNHEX('0fa91ce3e96a4bc2be4bd9ce752c3425') AND active=1 LIMIT 1");
if (!is_string($productId)) throw new RuntimeException('No test product available.');
$repository = $container->get('product.repository');
$repository->update([['id' => $productId, 'active' => true]], Shopware\Core\Framework\Context::createDefaultContext());
$after = (int) $connection->fetchOne('SELECT revision FROM shopware_meilisearch_state WHERE id=1');
if ($after <= $before) throw new RuntimeException('Actual Shopware product write did not invalidate the index.');
$unit = $connection->fetchAssociative('SELECT LOWER(HEX(unit_id)) id, LOWER(HEX(language_id)) language_id, name FROM unit_translation WHERE name IS NOT NULL LIMIT 1');
$delivery = $connection->fetchAssociative('SELECT LOWER(HEX(id)) id, min FROM delivery_time LIMIT 1');
if ($unit === false || $delivery === false) throw new RuntimeException('The test catalog needs a unit translation and delivery time.');
$container->get('unit_translation.repository')->update([['unitId' => $unit['id'], 'languageId' => $unit['language_id'], 'name' => $unit['name']]], Shopware\Core\Framework\Context::createDefaultContext());
$unitRevision = (int) $connection->fetchOne('SELECT revision FROM shopware_meilisearch_state WHERE id=1');
if ($unitRevision <= $after) throw new RuntimeException('Unit translation write did not invalidate the index.');
$container->get('delivery_time.repository')->update([['id' => $delivery['id'], 'min' => (int) $delivery['min']]], Shopware\Core\Framework\Context::createDefaultContext());
$deliveryRevision = (int) $connection->fetchOne('SELECT revision FROM shopware_meilisearch_state WHERE id=1');
if ($deliveryRevision <= $unitRevision) throw new RuntimeException('Delivery-time write did not invalidate the index.');
$option = $connection->fetchAssociative("SELECT LOWER(HEX(product_id)) product_id, LOWER(HEX(property_group_option_id)) option_id
    FROM product_option WHERE product_version_id=UNHEX('0fa91ce3e96a4bc2be4bd9ce752c3425') LIMIT 1");
if ($option === false) throw new RuntimeException('The test catalog needs a product option.');
// Exercise a real DAL deletion and its event inside a rollback-only transaction.
// Removing a variant option changes searchable text and must trigger a rebuild.
$connection->beginTransaction();
try {
    $container->get('product_option.repository')->delete([[
        'productId' => $option['product_id'], 'optionId' => $option['option_id'],
    ]], Shopware\Core\Framework\Context::createDefaultContext());
    $remaining = (int) $connection->fetchOne("SELECT COUNT(*) FROM product_option WHERE product_id=UNHEX(:product)
        AND property_group_option_id=UNHEX(:option) AND product_version_id=UNHEX('0fa91ce3e96a4bc2be4bd9ce752c3425')",
        ['product' => $option['product_id'], 'option' => $option['option_id']]);
    if ($remaining !== 0) throw new RuntimeException('Test option was not deleted.');
    $deletedRevision = (int) $connection->fetchOne('SELECT revision FROM shopware_meilisearch_state WHERE id=1');
    if ($deletedRevision <= $deliveryRevision) throw new RuntimeException('Product option deletion did not invalidate the index.');
} finally {
    $connection->rollBack();
}
$restored = (int) $connection->fetchOne("SELECT COUNT(*) FROM product_option WHERE product_id=UNHEX(:product)
    AND property_group_option_id=UNHEX(:option) AND product_version_id=UNHEX('0fa91ce3e96a4bc2be4bd9ce752c3425')",
    ['product' => $option['product_id'], 'option' => $option['option_id']]);
if ($restored !== 1) throw new RuntimeException('Test option was not restored by rollback.');
echo "Shopware DAL product, unit translation and delivery-time writes and product option deletion invalidated the local index.\n";
$kernel->shutdown();
