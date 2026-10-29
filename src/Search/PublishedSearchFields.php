<?php declare(strict_types=1);

namespace ShopwareMeilisearch\Search;

use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/** Actual published settings; configuration may change before the next index swap. */
final class PublishedSearchFields
{
    public function __construct(private readonly Engine $engine, private readonly CacheInterface $cache) {}

    public function get(string $index, bool &$fetched): array
    {
        $fetched = false;
        return $this->cache->get(self::key($index), function (ItemInterface $item) use ($index, &$fetched): array {
            $fetched = true;
            $fields = $this->engine->request('GET', '/indexes/' . $index . '/settings/searchable-attributes');
            if (!is_array($fields) || !array_is_list($fields) || array_filter($fields, static fn ($x) => !is_string($x))) throw new \RuntimeException('Invalid published search fields.');
            $item->expiresAfter(300);
            return $fields;
        });
    }

    public function invalidate(string $index): void { $this->cache->delete(self::key($index)); }
    private static function key(string $index): string { return 'shopware_meilisearch_fields_' . hash('sha256', $index); }
}
