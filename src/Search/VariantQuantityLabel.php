<?php declare(strict_types=1);

namespace ShopwareMeilisearch\Search;

use Shopware\Core\Content\Product\SalesChannel\SalesChannelProductEntity;

/** Disambiguate variant sizes without using a different language's unit text. */
final class VariantQuantityLabel
{
    public static function forProduct(SalesChannelProductEntity $product, string $name, string $languageId, string $locale, array $mapping): ?string
    {
        if ($product->getParentId() === null || $product->getUnitId() === null) return null;
        $unitId = $product->getUnitId();
        $amount = $product->getPurchaseUnit();
        $tokens = QuantityUnits::tokens($unitId, $amount, $mapping);
        if ($tokens === []) {
            // A selected language's real translation may expose a neutral SI
            // symbol even when no explicit conversion mapping is configured.
            $code = LanguageText::field($product->getUnit(), $languageId, 'shortCode');
            $symbol = is_string($code) ? mb_strtolower(trim($code), 'UTF-8') : '';
            if (!in_array($symbol, ['ml', 'l', 'g', 'kg'], true)) return null;
            $mapping = [$unitId => ['symbol' => $symbol, 'factor' => 1]];
            $tokens = QuantityUnits::tokens($unitId, $amount, $mapping);
        }
        if ($tokens === []) return null;
        $key = QuantityUnits::key($unitId, $amount, $mapping);
        if ($key !== null && QuantityUnits::queryKey($name) === $key) return null;
        [$number, $symbol] = explode(' ', $tokens[0], 2);
        $formatter = new \NumberFormatter($locale, \NumberFormatter::DECIMAL);
        $separator = $formatter->getSymbol(\NumberFormatter::DECIMAL_SEPARATOR_SYMBOL);
        return str_replace('.', $separator, $number) . ' ' . $symbol;
    }
}
