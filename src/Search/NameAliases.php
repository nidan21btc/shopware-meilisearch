<?php declare(strict_types=1);

namespace ShopwareMeilisearch\Search;

/** Language-local forms of alphabetic, hyphenated product-name compounds. */
final class NameAliases
{
    /** @return list<string> */
    public static function hyphenCompounds(string $name): array
    {
        $pattern = '/(?<![\p{L}\p{M}\p{N}])([\p{L}\p{M}]{3,})[-‐‑]([\p{L}\p{M}]{3,})(?![\p{L}\p{M}\p{N}])/u';
        if (!preg_match_all($pattern, $name, $matches, PREG_SET_ORDER)) return [];
        $aliases = [];
        foreach ($matches as $match) {
            $aliases[$match[1] . $match[2]] = true;
            if (count($aliases) >= 16) return array_keys($aliases);
        }
        // Keep the existing pairwise forms above, and also join complete
        // three-or-more-part compounds (e.g. Alpha-Beta-Gamma).
        $chains = '/(?<![\p{L}\p{M}\p{N}])[\p{L}\p{M}]{3,}(?:[-‐‑][\p{L}\p{M}]{3,}){2,}(?![\p{L}\p{M}\p{N}])/u';
        if (preg_match_all($chains, $name, $matches)) {
            foreach ($matches[0] as $chain) {
                $alias = preg_replace('/[-‐‑]/u', '', $chain);
                if ($alias !== null) $aliases[$alias] = true;
                if (count($aliases) >= 16) break;
            }
        }
        return array_keys($aliases);
    }
}
