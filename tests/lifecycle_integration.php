<?php declare(strict_types=1);

// Destructive lifecycle test: use only a disposable Shopware installation and database.
use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Adapter\Kernel\KernelFactory;
use Shopware\Core\System\SystemConfig\SystemConfigService;

$root = getenv('PROJECT_ROOT') ?: '';
$url = parse_url(getenv('DATABASE_URL') ?: '');
if (!is_array($url) || !preg_match('/^\/el_search_test_[a-f0-9]+$/D', $url['path'] ?? '') || !is_file($root . '/vendor/autoload.php')) {
    throw new RuntimeException('A disposable project and el_search_test_<hex> database are required.');
}
foreach (['PROJECT_ROOT', 'DATABASE_URL', 'APP_URL'] as $name) if (getenv($name) !== false) $_SERVER[$name] = $_ENV[$name] = getenv($name);
$loader = require $root . '/vendor/autoload.php';
$kernel = KernelFactory::create('dev', true, $loader);
$kernel->boot();
$db = $kernel->getContainer()->get(Connection::class);
$config = $kernel->getContainer()->get(SystemConfigService::class);
if ((int) $db->fetchOne('SELECT COUNT(*) FROM app WHERE active=1') || (int) $db->fetchOne('SELECT COUNT(*) FROM webhook WHERE active=1')) throw new RuntimeException('Active apps or webhooks are not allowed.');
$run = static function (array $args) use ($root): void {
    $command = array_merge([PHP_BINARY, $root . '/bin/console'], $args, ['--no-ansi', '--no-interaction']);
    $pipes = [];
    $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root);
    if (!is_resource($process)) throw new RuntimeException('Cannot start Shopware command.');
    fclose($pipes[0]); $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
    if (proc_close($process) !== 0) throw new RuntimeException('Lifecycle command failed: ' . $out . $err);
};
$state = static function () use ($db): bool { return (int) $db->fetchOne('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?', ['shopware_meilisearch_state']) === 1; };
$configExists = static function () use ($db): bool { return (int) $db->fetchOne('SELECT COUNT(*) FROM system_config WHERE configuration_key=?', ['ShopwareMeilisearch.config.enabled']) === 1; };
$sentinel = 'ShopwareMeilisearchOther\\Migration\\Sentinel' . bin2hex(random_bytes(4));
$db->insert('migration', ['class' => $sentinel, 'creation_timestamp' => 1]);
try {
    $config->set('ShopwareMeilisearch.config.enabled', true);
    $run(['plugin:uninstall', '--keep-user-data', '--skip-asset-build', 'ShopwareMeilisearch']);
    if (!$state() || !$configExists()) throw new RuntimeException('Keep-data uninstall lost plugin data.');
    $run(['plugin:install', '--activate', '--skip-asset-build', 'ShopwareMeilisearch']);
    if (!$state() || !$configExists()) throw new RuntimeException('Reinstall after keep-data lost plugin data.');
    $run(['plugin:uninstall', '--skip-asset-build', 'ShopwareMeilisearch']);
    if ($state() || $configExists()) throw new RuntimeException('Clean uninstall retained plugin data.');
    if ((int) $db->fetchOne('SELECT COUNT(*) FROM migration WHERE class=?', [$sentinel]) !== 1) throw new RuntimeException('Another migration was removed.');
    $pluginMigrations = $db->fetchFirstColumn('SELECT class FROM migration WHERE class LIKE ?', ['ShopwareMeilisearch%']);
    if (array_filter($pluginMigrations, static fn (string $class): bool => str_starts_with($class, 'ShopwareMeilisearch\\Migration\\'))) throw new RuntimeException('Plugin migration records remain after clean uninstall.');
    $run(['plugin:install', '--activate', '--skip-asset-build', 'ShopwareMeilisearch']);
    if (!$state() || (int) $db->fetchOne('SELECT COUNT(*) FROM shopware_meilisearch_state') !== 1) throw new RuntimeException('Clean reinstall did not restore state.');
    echo "Lifecycle passed: retain/delete, migration isolation and reinstall.\n";
} finally {
    $db->delete('migration', ['class' => $sentinel]);
    $kernel->shutdown();
}
