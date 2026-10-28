<?php declare(strict_types=1);

namespace ShopwareMeilisearch\Search;

use Symfony\Contracts\HttpClient\HttpClientInterface;

/** The browser never receives this connection or its API key. */
final class Engine
{
    private bool $versionChecked = false;
    private readonly string $key;
    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly string $url,
        string|\ShopwareMeilisearch\Security\Secret $key,
        private readonly string $socketPath = '',
        private readonly string|\ShopwareMeilisearch\Security\Secret $statusKey = '',
    ) {
        $this->key = (string) $key;
    }

    public static function indexName(string $salesChannelId, string $languageId): string
    {
        foreach ([$salesChannelId, $languageId] as $id) {
            if (!preg_match('/^[a-f0-9]{32}$/D', $id)) {
                throw new \InvalidArgumentException('Expected a Shopware UUID.');
            }
        }

        return 'shopware_' . $salesChannelId . '_' . $languageId;
    }

    public function ensureCompatible(): void
    {
        if ($this->versionChecked) {
            return;
        }
        $version = $this->request('GET', '/version')['pkgVersion'] ?? null;
        if (!is_string($version) || !preg_match('/^1\.\d+\.\d+/', $version) || version_compare($version, '1.9.0', '<')) {
            throw new \RuntimeException('Meilisearch 1.x starting with 1.9 is required; tested with 1.56.0.');
        }
        $this->versionChecked = true;
    }

    public function request(string $method, string $path, ?array $body = null, bool $allowMissing = false): ?array
    {
        if ($this->key === '') {
            throw new \RuntimeException('SHOPWARE_MEILISEARCH_KEY is not configured.');
        }
        $url = parse_url($this->url);
        if (!is_array($url) || !in_array($url['scheme'] ?? '', ['http', 'https'], true)
            || !in_array($url['host'] ?? '', ['127.0.0.1', '[::1]'], true)
            || isset($url['user']) || isset($url['pass'])
            || isset($url['query']) || isset($url['fragment'])) {
            throw new \RuntimeException('SHOPWARE_MEILISEARCH_URL must use a local loopback address.');
        }
        $options = [
            'headers' => ['Authorization' => 'Bearer ' . (($path === '/version' || str_starts_with($path, '/tasks/')) && (string) $this->statusKey !== '' ? (string) $this->statusKey : $this->key)],
            'timeout' => 3,
            'max_duration' => 10,
            'max_redirects' => 0,
            // Do not send search requests or the API key via an HTTP proxy
            // inherited from the PHP process environment.
            'no_proxy' => '*',
        ];
        if ($this->socketPath !== '') {
            // The socket is opt-in and must already exist. Never let a missing
            // path be interpreted by curl as an interface and silently fall
            // back to a TCP connection.
            clearstatcache(true, $this->socketPath);
            if (!str_starts_with($this->socketPath, '/') || strlen($this->socketPath) > 100
                || str_contains($this->socketPath, "\0") || is_link($this->socketPath)
                || !file_exists($this->socketPath) || @filetype($this->socketPath) !== 'socket'
                || ($url['scheme'] ?? '') !== 'http') {
                throw new \RuntimeException('The configured local search socket is unavailable or invalid.');
            }
            $options['bindto'] = $this->socketPath;
        }
        if ($body !== null) {
            $options['json'] = $body;
        }
        $response = $this->http->request($method, rtrim($this->url, '/') . '/' . ltrim($path, '/'), $options);
        if ($allowMissing && $response->getStatusCode() === 404) {
            return null;
        }

        return $response->toArray();
    }

    /** Mutations are asynchronous in Meilisearch; only publish a completed index. */
    public function mutate(string $method, string $path, array $body = []): void
    {
        $task = $this->request($method, $path, $body);
        if (!isset($task['taskUid'])) {
            throw new \RuntimeException('Search engine did not return a task.');
        }
        $deadline = microtime(true) + 300;
        do {
            $result = $this->request('GET', '/tasks/' . (int) $task['taskUid']);
            if (($result['status'] ?? '') === 'succeeded') {
                return;
            }
            if (in_array($result['status'] ?? '', ['failed', 'canceled'], true)) {
                throw new \RuntimeException('Search indexing task failed: ' . ($result['error']['code'] ?? $result['status']));
            }
            usleep(100000);
        } while (microtime(true) < $deadline);

        throw new \RuntimeException('Search indexing task timed out; inspect the task before retrying.');
    }
}
