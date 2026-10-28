<?php declare(strict_types=1);

// Run only against a disposable development database; all writes are rolled back.
$root = getenv('PROJECT_ROOT') ?: '';
if ($root === '' || getenv('POPULAR_TEST_ALLOW_WRITES') !== '1') throw new RuntimeException('Set PROJECT_ROOT and explicitly allow test writes.');
require $root . '/vendor/autoload.php';
(new Symfony\Component\Dotenv\Dotenv())->bootEnv($root . '/.env');
if (($_SERVER['APP_ENV'] ?? '') !== 'dev') throw new RuntimeException('A development environment is required.');
$parser = new Doctrine\DBAL\Tools\DsnParser(['mysql' => 'pdo_mysql', 'mariadb' => 'pdo_mysql']);
$db = Doctrine\DBAL\DriverManager::getConnection($parser->parse($_SERVER['DATABASE_URL']));
require __DIR__ . '/../src/Search/PopularSearches.php';
require __DIR__ . '/../src/Search/Discovery.php';
use ShopwareMeilisearch\Search\PopularSearches;
$service = new PopularSearches($db);
$channel = bin2hex(random_bytes(16)); $other = bin2hex(random_bytes(16)); $language = bin2hex(random_bytes(16));
$assert = static function (bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); };
$db->beginTransaction();
try {
    $manual = ShopwareMeilisearch\Search\Discovery::suggestions('{"de-DE":["manuell"]}', 'de-DE');
    $assert($manual === ['manuell'], 'Manual suggestions must remain available.');
    $service->record($channel, $language, 'Rose');
    $service->record($channel, $language, ' ROSE ');
    $assert($service->suggestions($channel, $language) === [], 'Below threshold must use manual fallback.');
    $service->record($channel, $language, 'Rose');
    $assert($service->suggestions($channel, $language) === ['rose'], 'Three successful searches should appear.');
    $assert($service->suggestions($other, $language) === [], 'Sales channels must be isolated.');
    $assert($service->suggestions($channel, $other) === [], 'Languages must be isolated.');
    foreach (['a@b.de', '123456', 'test 123', 'a', 'one two three four five'] as $invalid) $assert(PopularSearches::normalize($invalid) === null, 'Invalid query accepted.');
    $db->executeStatement('UPDATE shopware_meilisearch_popular_query SET day = UTC_DATE() - INTERVAL 31 DAY WHERE sales_channel_id = ?', [hex2bin($channel)]);
    $assert($service->suggestions($channel, $language) === [], 'Expired search still suggested.');
    $service->purge();
    $assert((int) $db->fetchOne('SELECT COUNT(*) FROM shopware_meilisearch_popular_query WHERE sales_channel_id = ?', [hex2bin($channel)]) === 0, 'Expired rows not purged.');
    echo "Popular search database integration passed.\n";
} finally { $db->rollBack(); }
