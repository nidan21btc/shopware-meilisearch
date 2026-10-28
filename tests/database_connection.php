<?php declare(strict_types=1);
require (getenv('SHOPWARE_ROOT') ?: dirname(__DIR__, 4)) . '/vendor/autoload.php';
require dirname(__DIR__) . '/src/Security/DatabaseConfiguration.php';
require dirname(__DIR__) . '/src/Controller/ConnectionController.php';
require dirname(__DIR__) . '/src/Search/Engine.php';
use Doctrine\DBAL\DriverManager;
use ShopwareMeilisearch\Security\DatabaseConfiguration;
use ShopwareMeilisearch\Controller\ConnectionController;
use ShopwareMeilisearch\Search\Engine;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Request;
function checkConfig(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
$db = DriverManager::getConnection(['driver'=>'pdo_sqlite','memory'=>true]);
$db->executeStatement('CREATE TABLE shopware_meilisearch_connection (id INTEGER PRIMARY KEY, instance VARCHAR(64), configuration TEXT)');
$config = new DatabaseConfiguration($db, '/synthetic/shop');
$values = ['url'=>'http://127.0.0.1:7700','socket'=>'','searchKey'=>str_repeat('s',32),'indexKey'=>str_repeat('i',32),'statusKey'=>str_repeat('t',32),'concurrency'=>5,'globalRate'=>6,'clientRate'=>7];
checkConfig($config->get()===null, 'New installation must have no settings');
$config->save($values);
checkConfig($config->get()===$values, 'Saved settings should be available');
$controller = new ConnectionController($config);
$data = json_decode($controller->get()->getContent(), true, 16, JSON_THROW_ON_ERROR);
checkConfig($data['configured']===true && !isset($data['searchKey']) && !isset($data['indexKey']) && !isset($data['statusKey']), 'Admin status must not return keys');
$copied = new DatabaseConfiguration($db, '/synthetic/other-shop');
try { $copied->get(); throw new RuntimeException('Copied config accepted'); } catch (RuntimeException $e) { checkConfig(str_contains($e->getMessage(), 'different installation'), 'A copied database must fail closed'); }
$copiedController = new ConnectionController($copied);
checkConfig(json_decode($copiedController->get()->getContent(), true)['configured'] === false, 'Copied connection must not be reported as configured');
$copiedRequest = Request::create('/', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode(['url'=>$values['url'],'socket'=>'','concurrency'=>5,'globalRate'=>6,'clientRate'=>7]));
checkConfig($copiedController->set($copiedRequest)->getStatusCode() === 400, 'Copied credentials cannot be silently reused');
$bad = $values; $bad['url'] = 'http://example.com';
try { DatabaseConfiguration::validate($bad); throw new RuntimeException('Remote URL accepted'); } catch (InvalidArgumentException) {}
$bad = $values; $bad['concurrency'] = 100;
try { DatabaseConfiguration::validate($bad); throw new RuntimeException('Unsafe limit accepted'); } catch (InvalidArgumentException) {}
$headers = [];
$http = new MockHttpClient(static function ($method, $url, $options) use (&$headers) { $headers[] = $options['headers']; return new MockResponse('{}'); });
(new Engine($http, 'http://127.0.0.1:1', 'fallback', '', '', $config, 'search'))->request('POST','/indexes/test/search', []);
(new Engine($http, 'http://127.0.0.1:1', 'fallback', '', '', $config))->request('GET','/version');
checkConfig(str_contains(implode(' ', $headers[0]), $values['searchKey']), 'Search must use database search key');
checkConfig(str_contains(implode(' ', $headers[1]), $values['statusKey']), 'Status must use database status key');
echo "Database connection checks passed: private API, validation, instance binding and role keys.\n";
