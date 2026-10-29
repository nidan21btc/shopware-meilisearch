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
use ShopwareMeilisearch\Search\PopularSearches;
$secret = bin2hex(random_bytes(32));
$service = new PopularSearches($db, $secret, new Symfony\Component\Cache\Adapter\NullAdapter());
$channel = bin2hex(random_bytes(16)); $other = bin2hex(random_bytes(16)); $language = bin2hex(random_bytes(16));
$assert = static function (bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); };
$db->beginTransaction();
try {
    $proof = $service->proof($channel, $language, 'rose', 'session-a');
    $assert($service->validProof($proof, $channel, $language, 'rose', 'session-a'), 'Valid proof rejected.');
    foreach ([[$other, $language, 'rose', 'session-a'], [$channel, $other, 'rose', 'session-a'], [$channel, $language, 'lily', 'session-a'], [$channel, $language, 'rose', 'session-b']] as [$c, $l, $q, $s]) {
        $assert(!$service->validProof($proof, $c, $l, $q, $s), 'Proof must bind query, session, channel and language.');
    }
    $time = (string) (time() - 301);
    $expired = $time . '.' . hash_hmac('sha256', implode("\0", [$time, $channel, $language, 'rose', 'session-a']), $secret);
    $assert(!$service->validProof($expired, $channel, $language, 'rose', 'session-a'), 'Expired proof accepted.');
    $db->executeStatement('INSERT INTO shopware_meilisearch_popular_query (day,sales_channel_id,language_id,query_hash,query,count) VALUES (UTC_DATE(),?,?,?,?,999)', [hex2bin($channel),hex2bin($language),hash('sha256','rose',true),'rose']);
    for ($i=0; $i<20; ++$i) $service->record($channel, $language, 'Rose', 'same-session', []);
    for ($i=0; $i<8; ++$i) $service->record($channel, $language, ' ROSE ', 'session-'.$i, []);
    $assert($service->suggestions($channel, $language, []) === [], 'Repeated requests and unverifiable historical counts must not reach the threshold.');
    $service->record($channel, $language, 'Rose', 'session-8', []);
    $assert($service->suggestions($channel, $language, []) === ['rose'], 'Ten independent sessions should reach the threshold.');
    $cache = new Symfony\Component\Cache\Adapter\ArrayAdapter();
    $cached = new PopularSearches($db, $secret, $cache);
    $assert($cached->suggestions($channel, $language, []) === ['rose'], 'Cached suggestions missing.');
    $assert($cached->suggestions($channel, $language, ['rose']) === [], 'Blocklist changes must apply immediately to cached suggestions.');
    $assert($cached->suggestions($other, $language, []) === [] && $cached->suggestions($channel, $other, []) === [], 'Cached suggestions must be isolated by channel and language.');

    $assert($service->suggestions($other, $language, []) === [] && $service->suggestions($channel, $other, []) === [], 'Sales channels and languages must be isolated.');
    foreach (['gruene bluete', 'GRÜNE-BLÜTE', 'Grüne Blüte'] as $variant) $assert(PopularSearches::blocked($variant, PopularSearches::blocklist('{"de-DE":["grüne blüte"]}', 'de-DE')), 'Blocklist normalization failed.');
    $blocked = PopularSearches::blocklist('{"de-DE":["ROS"]}', 'de-DE');
    $assert($service->suggestions($channel, $language, $blocked) === [], 'Blocklist must hide existing aggregates case-insensitively.');
    $before = (int) $db->fetchOne('SELECT COUNT(*) FROM shopware_meilisearch_popular_session WHERE sales_channel_id=?', [hex2bin($channel)]);
    $service->record($channel, $language, 'rose', 'blocked-session', $blocked);
    $assert((int) $db->fetchOne('SELECT COUNT(*) FROM shopware_meilisearch_popular_session WHERE sales_channel_id=?', [hex2bin($channel)]) === $before, 'Blocked query was recorded.');
    foreach (['a@b.de', '123456', 'test 123', 'a', 'one two three four five'] as $invalid) $assert(PopularSearches::normalize($invalid) === null, 'Invalid query accepted.');
    try { PopularSearches::blocklist('{bad', 'de-DE'); throw new RuntimeException('Invalid blocklist accepted.'); } catch (\JsonException) {}
    $db->executeStatement('UPDATE shopware_meilisearch_popular_session SET day=UTC_DATE()-INTERVAL 31 DAY WHERE sales_channel_id=?', [hex2bin($channel)]);
    $assert($service->suggestions($channel, $language, []) === [], 'Expired search still suggested.');
    $service->purge();
    $assert((int) $db->fetchOne('SELECT COUNT(*) FROM shopware_meilisearch_popular_session WHERE sales_channel_id=?', [hex2bin($channel)]) === 0, 'Expired records not purged.');
    echo "Popular search session, proof, blocklist and retention checks passed.\n";
} finally { $db->rollBack(); }
