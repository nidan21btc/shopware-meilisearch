<?php declare(strict_types=1);

namespace ShopwareMeilisearch\Search;

final class PartialMatches
{
    /** An added partial variant must not remove a primary parent result. */
    public static function hideParent(string $parentId, string $childId, array $primaryIds): bool
    {
        return !isset($primaryIds[$parentId]) || isset($primaryIds[$childId]);
    }

    /** Partial matching is for short natural-language queries, never article codes. */
    public static function terms(string $query): array
    {
        if (!preg_match('/^[\p{L}\p{M}\s]+$/uD', $query)) {
            return [];
        }
        $parts = preg_split('/\s+/u', trim($query), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if (count($parts) < 2 || count($parts) > 5) {
            return [];
        }
        $terms = [];
        foreach ($parts as $part) {
            if (mb_strlen($part, 'UTF-8') >= 3) {
                $terms[mb_strtolower($part, 'UTF-8')] = $part;
            }
        }

        return count($terms) >= 2 ? array_values($terms) : [];
    }

    /** A whole name before one unambiguous trailing SI quantity, never a code. */
    public static function withoutTrailingQuantity(string $query): ?string
    {
        if (QuantityUnits::queryKey($query) === null) return null;
        if (!preg_match('/^([\p{L}\p{M}\s]+?)\s+[0-9]{1,9}(?:[.,][0-9]{1,8})?\s*(?:ml|kg|l|g)\s*$/iuD', $query, $match)) return null;
        $phrase = trim(preg_replace('/\s+/u', ' ', $match[1]) ?? '');
        $words = preg_split('/\s+/u', $phrase, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if (count($words) < 1 || count($words) > 5 || !array_filter($words, static fn (string $word): bool => mb_strlen($word, 'UTF-8') >= 3)) return null;
        return $phrase;
    }

    /** Accept exactly one quantity at either end and a natural-language phrase. */
    public static function quantityPhrase(string $query): ?string
    {
        if (QuantityUnits::queryKey($query) === null) return null;
        $quantity = '[0-9]{1,9}(?:[.,][0-9]{1,8})?\s*(?:ml|kg|l|g)';
        if (!preg_match('/^\s*(?:(' . $quantity . ')\s+([\p{L}\p{M}\s]+)|([\p{L}\p{M}\s]+)\s+(' . $quantity . '))\s*$/iuD', $query, $m)) return null;
        $phrase = trim(preg_replace('/\s+/u', ' ', $m[2] !== '' ? $m[2] : $m[3]) ?? '');
        $words = preg_split('/\s+/u', $phrase, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if (count($words) < 1 || count($words) > 5 || mb_strlen(preg_replace('/[^\p{L}]/u', '', $phrase) ?? '', 'UTF-8') < 3) return null;
        return $phrase;
    }

    /** Preserve original order, then round-robin each word's ranked results. */
    public static function append(array $original, array $wordHits, int $perTerm = 20): array
    {
        $result = $original;
        $seen = array_fill_keys($original, true);
        for ($position = 0; $position < $perTerm; ++$position) {
            foreach ($wordHits as $hits) {
                $id = $hits[$position]['id'] ?? null;
                if (is_string($id) && preg_match('/^[a-f0-9]{32}$/D', $id) && !isset($seen[$id])) {
                    $seen[$id] = true;
                    $result[] = $id;
                }
            }
        }

        return $result;
    }
}
