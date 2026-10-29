<?php declare(strict_types=1);

namespace ShopwareMeilisearch\Search;

/** Prefer an explicitly requested exact physical amount among equally matched results. */
final class QuantityRelevance
{
    /**
     * For 3–5-letter product phrases with a quantity, hide typo matches if at
     * least one exact match exists. If none exist, retain typo matches so a
     * misspelling can still find products; unavailable metadata preserves order.
     */
    public static function usesPreciseCombined(string $phrase): bool
    {
        return (bool) preg_match('/^\p{L}{3,5}$/uD', $phrase);
    }

    public static function preciseCombined(array $hits): array
    {
        $exact = [];
        foreach ($hits as $hit) {
            $typos = $hit['_rankingScoreDetails']['typo']['typoCount'] ?? null;
            if (!is_int($typos)) return $hits;
            if ($typos === 0) $exact[] = $hit;
        }
        return $exact !== [] ? $exact : $hits;
    }

    /** Promote exact quantities only within the strongest engine relevance group. */
    public static function promoteCombined(array $hits, ?string $key, array $names, string $phrase): array
    {
        if ($key === null || $names === [] || $hits === []) return $hits;
        $details = $hits[0]['_rankingScoreDetails'] ?? [];
        $words = $details['words']['matchingWords'] ?? null; $typos = $details['typo']['typoCount'] ?? null;
        if (!is_int($words) || !is_int($typos)) return $hits;
        $exact = []; $remaining = []; $tail = []; $inStrongest = true;
        foreach ($hits as $hit) {
            $d = $hit['_rankingScoreDetails'] ?? [];
            if (($d['words']['matchingWords'] ?? null) !== $words || ($d['typo']['typoCount'] ?? null) !== $typos) $inStrongest = false;
            if (!$inStrongest) { $tail[] = $hit; continue; }
            $id = $hit['id'] ?? '';
            if (($hit['quantityKey'] ?? null) === $key && isset($names[$id]) && self::matchesAny($phrase, $names[$id])) $exact[] = $hit;
            else $remaining[] = $hit;
        }
        return array_merge($exact, $remaining, $tail);
    }

    private static function matchesAny(string $phrase, string|array $values): bool
    {
        foreach ((array) $values as $value) if (is_string($value) && self::wholeWords($phrase, $value)) return true;
        return false;
    }

    public static function wholeWords(string $phrase, string $name): bool
    {
        preg_match_all('/[\p{L}\p{N}]+/u', SearchFold::text($phrase), $needle);
        preg_match_all('/[\p{L}\p{N}]+/u', SearchFold::text($name), $haystack);
        return $needle[0] !== [] && array_diff($needle[0], $haystack[0]) === [];
    }

    public static function reorder(array $hits, ?string $key): array
    {
        if ($key === null || $hits === []) return $hits;
        $buckets = [];
        foreach ($hits as $hit) {
            $details = $hit['_rankingScoreDetails'] ?? [];
            $words = $details['words']['matchingWords'] ?? null;
            $typos = $details['typo']['typoCount'] ?? null;
            // Unknown response formats retain the search engine's order.
            if (!is_int($words) || !is_int($typos)) return $hits;
            $bucketKey = $words . ':' . $typos;
            $last = count($buckets) - 1;
            if ($last < 0 || $buckets[$last]['key'] !== $bucketKey) {
                $buckets[] = ['key' => $bucketKey, 'matching' => [], 'other' => []];
                ++$last;
            }
            $group = ($hit['quantityKey'] ?? null) === $key ? 'matching' : 'other';
            $buckets[$last][$group][] = $hit;
        }
        $result = [];
        foreach ($buckets as $bucket) {
            array_push($result, ...$bucket['matching'], ...$bucket['other']);
        }
        return $result;
    }
}
