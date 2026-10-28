<?php declare(strict_types=1);

// Isolated integration test. The supplied server and key must point at a test
// instance; each test index is created under a random name and deleted after.
require (getenv('SHOPWARE_ROOT') ?: dirname(__DIR__, 4)) . '/vendor/autoload.php';
spl_autoload_register(static function (string $class): void {
    $prefix = 'ShopwareMeilisearch\\';
    if (str_starts_with($class, $prefix)) {
        require dirname(__DIR__) . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    }
});

use ShopwareMeilisearch\Search\DocumentBuilder;
use ShopwareMeilisearch\Search\Engine;
use Symfony\Component\HttpClient\HttpClient;

$url = getenv('SHOPWARE_MEILISEARCH_URL') ?: '';
$key = getenv('SHOPWARE_MEILISEARCH_KEY') ?: '';
if ($url === '' || $key === '') {
    throw new RuntimeException('A dedicated test Meilisearch URL and key are required.');
}
$engine = new Engine(HttpClient::create(), $url, $key, getenv('SHOPWARE_MEILISEARCH_SOCKET') ?: '');
$engine->ensureCompatible();
$prefix = 'el_test_' . bin2hex(random_bytes(8));
$indexes = [$prefix . '_de', $prefix . '_en', $prefix . '_building'];
$fields = ['productNumber', 'name', 'unitShortCode', 'customFields.theme', 'description'];
$test = static function (bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
};
$search = static function (string $index, string $query) use ($engine): array {
    $result = $engine->request('POST', '/indexes/' . $index . '/search', ['q' => $query, 'matchingStrategy' => 'all', 'limit' => 20]);
    return array_column($result['hits'], 'id');
};
try {
    foreach ($indexes as $index) {
        $engine->mutate('POST', '/indexes', ['uid' => $index, 'primaryKey' => 'id']);
        $engine->mutate('PATCH', '/indexes/' . $index . '/settings', DocumentBuilder::settings([], $fields));
    }
    $engine->mutate('POST', '/indexes/' . $indexes[0] . '/documents', [[
        'id' => 'one', 'groupId' => 'one', 'productNumber' => 'EL001', 'name' => 'Bachblüten Rescue',
        'unitShortCode' => 'ml', 'customFields' => ['theme' => 'Gelassenheit'], 'description' => 'Innere Ruhe', 'categories' => [], 'manufacturer' => '',
    ]]);
    $engine->mutate('POST', '/indexes/' . $indexes[1] . '/documents', [[
        'id' => 'one', 'groupId' => 'one', 'productNumber' => 'EL001', 'name' => 'Flower Rescue',
        'customFields' => ['theme' => 'Calm'], 'description' => 'Inner balance', 'categories' => [], 'manufacturer' => '',
    ]]);
    $test($search($indexes[0], 'bachbluten') === ['one'], 'Accent normalization failed');
    $test($search($indexes[0], 'bachblutn') === ['one'], 'Typo tolerance failed');
    $test($search($indexes[0], 'gelassen') === ['one'], 'Configured nested custom field or prefix matching failed');
    $test($search($indexes[0], 'ml') === ['one'], 'Configured unit abbreviation was not searchable');
    $test($search($indexes[1], 'ml') === [], 'Unit abbreviation leaked into another language index');
    $test($search($indexes[1], 'gelassenheit') === [], 'German custom field leaked into English index');
    $test($search($indexes[0], 'balance') === [], 'English description leaked into German index');
    $test($search($indexes[1], 'calm') === ['one'], 'English custom field not searchable');
    $test($search($indexes[0], 'EL002') === [], 'Identifier typo was incorrectly tolerated');
    $multi = $engine->request('POST', '/multi-search', ['queries' => [
        ['indexUid' => $indexes[0], 'q' => 'gelassen', 'limit' => 20, 'matchingStrategy' => 'frequency'],
        ['indexUid' => $indexes[0], 'q' => 'balance', 'limit' => 20, 'matchingStrategy' => 'frequency'],
    ]]);
    $test(array_column($multi['results'][0]['hits'], 'id') === ['one'], 'Partial-word search did not use the requested language index');
    $test($multi['results'][1]['hits'] === [], 'Partial-word search leaked across languages');

    $engine->mutate('POST', '/indexes/' . $indexes[2] . '/documents', [[
        'id' => 'two', 'groupId' => 'two', 'productNumber' => 'EL002', 'name' => 'Replacement',
        'customFields' => ['theme' => 'Austausch'], 'description' => '', 'categories' => [], 'manufacturer' => '',
    ]]);
    $engine->mutate('POST', '/swap-indexes', [['indexes' => [$indexes[0], $indexes[2]]]]);
    $test($search($indexes[0], 'replacement') === ['two'], 'Atomic swap did not publish new index');
    $test($search($indexes[2], 'bachbluten') === ['one'], 'Atomic swap did not retain old generation');
    echo "Meilisearch integration checks passed: fields, language separation, partial matches, accents, typos, identifiers and atomic swap.\n";
} finally {
    foreach ($indexes as $index) {
        if ($engine->request('GET', '/indexes/' . $index, allowMissing: true) !== null) {
            $engine->mutate('DELETE', '/indexes/' . $index);
        }
    }
}
