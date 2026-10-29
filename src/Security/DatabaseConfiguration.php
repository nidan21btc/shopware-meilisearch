<?php declare(strict_types=1);

namespace ShopwareMeilisearch\Security;

use Doctrine\DBAL\Connection;

/** Connection secrets are private database data, not exposed via SystemConfig API. */
final class DatabaseConfiguration
{
    private ?array $cached = null;
    private bool $loaded = false;

    public function __construct(private readonly Connection $db, private readonly string $projectDir) {}

    public function instanceId(): string
    {
        $dir = realpath($this->projectDir) ?: $this->projectDir;
        // Releases of the same installation share an identity; copied shops do not.
        $dir = preg_replace('~/releases/[^/]+/htdocs/?$~', '', $dir) ?? $dir;
        return hash('sha256', (gethostname() ?: 'unknown-host') . "\0" . $dir);
    }

    public function get(): ?array
    {
        if ($this->loaded) return $this->cached;
        $row = $this->db->fetchAssociative('SELECT instance, configuration FROM shopware_meilisearch_connection WHERE id = 1');
        if ($row === false) { $this->loaded = true; return null; }
        if (!hash_equals($this->instanceId(), $row['instance'])) throw new \RuntimeException('Search connection belongs to a different installation and must be configured again.');
        $config = json_decode($row['configuration'], true, 16, JSON_THROW_ON_ERROR);
        self::validate($config);
        $this->loaded = true;
        return $this->cached = $config;
    }

    public function save(array $config, bool $replace = false): void
    {
        self::validate($config);
        if ($replace) {
            $this->db->executeStatement('INSERT INTO shopware_meilisearch_connection (id, instance, configuration) VALUES (1, :instance, :config) ON DUPLICATE KEY UPDATE instance = VALUES(instance), configuration = VALUES(configuration)', ['instance' => $this->instanceId(), 'config' => json_encode($config, JSON_THROW_ON_ERROR)]);
        } else {
            $this->db->executeStatement('INSERT INTO shopware_meilisearch_connection (id, instance, configuration) VALUES (1, :instance, :config)', ['instance' => $this->instanceId(), 'config' => json_encode($config, JSON_THROW_ON_ERROR)]);
        }
        $this->loaded = false;
        $this->cached = null;
    }

    public static function validate(mixed $config): void
    {
        if (!is_array($config)) throw new \InvalidArgumentException('Invalid search connection configuration.');
        foreach (['url', 'socket', 'searchKey', 'indexKey', 'statusKey'] as $key) {
            if (!isset($config[$key]) || !is_string($config[$key]) || strlen($config[$key]) > 4096 || str_contains($config[$key], "\0")) throw new \InvalidArgumentException('Invalid search connection configuration.');
        }
        foreach (['searchKey', 'indexKey', 'statusKey'] as $key) {
            if (strlen($config[$key]) < 32 || preg_match('/\s/', $config[$key])) throw new \InvalidArgumentException('Invalid search credential.');
        }
        $url = parse_url($config['url']);
        if (!is_array($url) || !in_array($url['scheme'] ?? '', ['http', 'https'], true) || !in_array($url['host'] ?? '', ['127.0.0.1', '[::1]'], true) || isset($url['user']) || isset($url['pass']) || isset($url['query']) || isset($url['fragment'])) throw new \InvalidArgumentException('Search origin must be local.');
        if ($config['socket'] !== '' && (!str_starts_with($config['socket'], '/') || strlen($config['socket']) > 100 || $url['scheme'] !== 'http')) throw new \InvalidArgumentException('Invalid search socket.');
        foreach (['concurrency', 'globalRate', 'clientRate'] as $key) {
            if (!isset($config[$key]) || !is_int($config[$key]) || $config[$key] < 1 || $config[$key] > 10) throw new \InvalidArgumentException('Search limits must be between 1 and 10.');
        }
    }
}
