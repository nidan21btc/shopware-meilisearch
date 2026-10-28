<?php declare(strict_types=1);

namespace ShopwareMeilisearch\Search;

use Doctrine\DBAL\Connection;

/** Anonymous daily aggregate. Search text can still be sensitive; see the documentation. */
final class PopularSearches
{
    private const DAYS = 30;
    private const MIN_COUNT = 3;
    private const MAX_DAILY_TERMS = 2000;

    public function __construct(private readonly Connection $db) {}

    public static function normalize(string $query): ?string
    {
        $query = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $query) ?? ''), 'UTF-8');
        if (mb_strlen($query) < 3 || mb_strlen($query) > 60 || !preg_match('/^\p{L}[\p{L}\p{M} -]*\p{L}$/uD', $query)) return null;
        if (count(explode(' ', $query)) > 4) return null;
        return $query;
    }

    public function record(string $salesChannelId, string $languageId, string $query): void
    {
        $query = self::normalize($query);
        if ($query === null || !preg_match('/^[a-f0-9]{32}$/D', $salesChannelId) || !preg_match('/^[a-f0-9]{32}$/D', $languageId)) return;
        $key = [hex2bin($salesChannelId), hex2bin($languageId), hash('sha256', $query, true)];
        if ($this->db->executeStatement('UPDATE shopware_meilisearch_popular_query SET count = LEAST(count + 1, 1000000) WHERE day = UTC_DATE() AND sales_channel_id = ? AND language_id = ? AND query_hash = ?', $key) > 0) return;
        // Limit new distinct entries; concurrent first inserts can slightly exceed this limit.
        $dayCount = (int) $this->db->fetchOne('SELECT COUNT(*) FROM shopware_meilisearch_popular_query WHERE day = UTC_DATE()');
        if ($dayCount >= self::MAX_DAILY_TERMS) return;
        $this->db->executeStatement('INSERT INTO shopware_meilisearch_popular_query (sales_channel_id, language_id, day, query_hash, query, count) VALUES (?, ?, UTC_DATE(), ?, ?, 1) ON DUPLICATE KEY UPDATE count = LEAST(count + 1, 1000000)', [...$key, $query]);
    }

    public function suggestions(string $salesChannelId, string $languageId): array
    {
        if (!preg_match('/^[a-f0-9]{32}$/D', $salesChannelId) || !preg_match('/^[a-f0-9]{32}$/D', $languageId)) return [];
        return $this->db->fetchFirstColumn('SELECT query FROM shopware_meilisearch_popular_query WHERE sales_channel_id = ? AND language_id = ? AND day >= UTC_DATE() - INTERVAL 29 DAY GROUP BY query_hash, query HAVING SUM(count) >= ' . self::MIN_COUNT . ' ORDER BY SUM(count) DESC, query ASC LIMIT 10', [hex2bin($salesChannelId), hex2bin($languageId)]);
    }

    public function purge(): void
    {
        $this->db->executeStatement('DELETE FROM shopware_meilisearch_popular_query WHERE day < UTC_DATE() - INTERVAL ' . (self::DAYS - 1) . ' DAY');
    }
}
