<?php declare(strict_types=1);

namespace ShopwareMeilisearch\Search;

/** Prefer stronger configured fields within an otherwise equally matched typo bucket. */
final class TypoRelevance
{
    public static function reorder(array $hits): array
    {
        $buckets = [];
        foreach ($hits as $hit) {
            $details = $hit['_rankingScoreDetails'] ?? null;
            $words = $details['words']['matchingWords'] ?? null;
            $typos = $details['typo']['typoCount'] ?? null;
            $proximity = $details['proximity']['score'] ?? null;
            $attribute = $details['attribute']['score'] ?? null;
            // Never guess at the engine's ordering if the response format differs.
            if (!is_int($words) || !is_int($typos) || !is_numeric($proximity) || !is_numeric($attribute)) {
                return $hits;
            }
            $key = json_encode([$words, $typos, $proximity], JSON_THROW_ON_ERROR);
            $last = count($buckets) - 1;
            if ($last < 0 || $buckets[$last]['key'] !== $key) {
                $buckets[] = ['key' => $key, 'typos' => $typos, 'hits' => []];
                ++$last;
            }
            $buckets[$last]['hits'][] = $hit;
        }
        $ordered = [];
        foreach ($buckets as $bucket) {
            $group = $bucket['hits'];
            if ($bucket['typos'] > 0) {
                // PHP's stable sort preserves the engine's order for equal scores.
                usort($group, static fn (array $a, array $b): int => $b['_rankingScoreDetails']['attribute']['score'] <=> $a['_rankingScoreDetails']['attribute']['score']);
            }
            array_push($ordered, ...$group);
        }

        return $ordered;
    }
}
