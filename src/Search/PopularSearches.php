<?php declare(strict_types=1);

namespace ShopwareMeilisearch\Search;

use Doctrine\DBAL\Connection;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/** Local aggregates contain search text and pseudonymous session digests. */
final class PopularSearches
{
    private const DAYS = 30;
    private const MIN_SESSIONS = 10;
    private const MAX_DAILY_RECORDS = 20000;
    private const MAX_SESSION_RECORDS = 30;
    private const PROOF_TTL = 300;

    public function __construct(private readonly Connection $db, private readonly string $secret, private readonly CacheInterface $cache) {}

    public static function normalize(string $query): ?string
    {
        $query = \Normalizer::normalize($query, \Normalizer::FORM_C);
        if ($query === false) return null;
        $query = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $query) ?? ''), 'UTF-8');
        if (mb_strlen($query) < 3 || mb_strlen($query) > 60 || !preg_match('/^\p{L}[\p{L}\p{M} -]*\p{L}$/uD', $query)) return null;
        if (count(explode(' ', $query)) > 4) return null;
        return $query;
    }

    /** A malformed blocklist disables automatic suggestions, rather than bypassing the block. */
    public static function blocklist(string $json, string $locale): array
    {
        if ($json === '') return [];
        $data = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
        if (!is_array($data) || ($data !== [] && array_is_list($data))) throw new \InvalidArgumentException('Invalid blocklist.');
        $terms = $data[$locale] ?? [];
        if (!is_array($terms) || !array_is_list($terms) || count($terms) > 100) throw new \InvalidArgumentException('Invalid blocklist.');
        $blocked = [];
        foreach ($terms as $term) {
            if (!is_string($term) || !mb_check_encoding($term, 'UTF-8') || mb_strlen($term) > 60) throw new \InvalidArgumentException('Invalid blocklist.');
            $term = \Normalizer::normalize(trim($term), \Normalizer::FORM_C);
            if ($term === false) throw new \InvalidArgumentException('Invalid blocklist.');
            if ($term !== '') {
                $term = self::blockKey($term);
                if ($term !== '') $blocked[] = $term;
            }
        }
        return $blocked;
    }

    public static function blocked(string $query, array $blocked): bool
    {
        $query = self::blockKey($query);
        foreach ($blocked as $term) if ($term !== '' && str_contains($query, $term)) return true;
        return false;
    }

    private static function blockKey(string $value): string
    {
        $value = \Normalizer::normalize($value, \Normalizer::FORM_C);
        if ($value === false) return '';
        $value = mb_convert_case($value, MB_CASE_FOLD, 'UTF-8');
        $value = strtr($value, ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue']);
        return preg_replace('/[\s\p{Pd}]+/u', '', $value) ?? '';
    }

    /** A short-lived proof binds a successful search to its session and sales-channel context. */
    public function proof(string $channel, string $language, string $query, string $session): string
    {
        $timestamp = (string) time();
        return $timestamp . '.' . hash_hmac('sha256', implode("\0", [$timestamp, $channel, $language, $query, $session]), $this->secret);
    }

    public function validProof(string $proof, string $channel, string $language, string $query, string $session): bool
    {
        if (!preg_match('/^([0-9]{10})\.([a-f0-9]{64})$/D', $proof, $match)) return false;
        $timestamp = (int) $match[1];
        if ($timestamp > time() || $timestamp < time() - self::PROOF_TTL || $session === '') return false;
        $expected = hash_hmac('sha256', implode("\0", [$match[1], $channel, $language, $query, $session]), $this->secret);
        return hash_equals($expected, $match[2]);
    }

    public function record(string $salesChannelId, string $languageId, string $query, string $session, array $blocked): void
    {
        $query = self::normalize($query);
        if ($query === null || self::blocked($query, $blocked) || $session === '' || !preg_match('/^[a-f0-9]{32}$/D', $salesChannelId) || !preg_match('/^[a-f0-9]{32}$/D', $languageId)) return;
        $channel = hex2bin($salesChannelId); $language = hex2bin($languageId);
        $sessionHash = hash_hmac('sha256', "popular-session\0" . $session, $this->secret, true);
        if ((int) $this->db->fetchOne('SELECT COUNT(*) FROM shopware_meilisearch_popular_session WHERE day=UTC_DATE()') >= self::MAX_DAILY_RECORDS) return;
        if ((int) $this->db->fetchOne('SELECT COUNT(*) FROM shopware_meilisearch_popular_session WHERE day=UTC_DATE() AND sales_channel_id=? AND language_id=? AND session_hash=?', [$channel, $language, $sessionHash]) >= self::MAX_SESSION_RECORDS) return;
        $this->db->executeStatement('INSERT IGNORE INTO shopware_meilisearch_popular_session (day,sales_channel_id,language_id,session_hash,query_hash,query) VALUES (UTC_DATE(),?,?,?,?,?)', [$channel, $language, $sessionHash, hash('sha256', $query, true), $query]);
    }

    public function suggestions(string $salesChannelId, string $languageId, array $blocked): array
    {
        if (!preg_match('/^[a-f0-9]{32}$/D', $salesChannelId) || !preg_match('/^[a-f0-9]{32}$/D', $languageId)) return [];
        $key = 'shopware_meilisearch_popular_' . hash('sha256', $salesChannelId . ':' . $languageId);
        $values = $this->cache->get($key, function (ItemInterface $item) use ($salesChannelId, $languageId): array {
            $item->expiresAfter(300);
            return $this->db->fetchFirstColumn('SELECT query FROM shopware_meilisearch_popular_session WHERE sales_channel_id=? AND language_id=? AND day>=UTC_DATE()-INTERVAL 29 DAY GROUP BY query_hash,query HAVING COUNT(DISTINCT session_hash)>=' . self::MIN_SESSIONS . ' ORDER BY COUNT(DISTINCT session_hash) DESC,query ASC LIMIT 110', [hex2bin($salesChannelId),hex2bin($languageId)]);
        });
        return array_slice(array_values(array_filter($values, static fn (string $query): bool => !self::blocked($query, $blocked))), 0, 10);
    }

    public function purge(): void
    {
        $this->db->executeStatement('DELETE FROM shopware_meilisearch_popular_session WHERE day < UTC_DATE() - INTERVAL ' . (self::DAYS - 1) . ' DAY');
        $this->db->executeStatement('DELETE FROM shopware_meilisearch_popular_query WHERE day < UTC_DATE() - INTERVAL ' . (self::DAYS - 1) . ' DAY');
    }
}
