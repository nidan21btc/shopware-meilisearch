<?php declare(strict_types=1);

namespace ShopwareMeilisearch\Search;

use Shopware\Core\Content\Product\SalesChannel\SalesChannelProductEntity;

/** Describe variant options using only their current-language translations. */
final class VariantOptionLabels
{
    /** @return array{labels: list<string>, showNumber: bool} */
    public static function forProduct(SalesChannelProductEntity $product, string $name, string $languageId, ?string $quantity): array
    {
        if ($product->getParentId() === null) return ['labels' => [], 'showNumber' => false];
        $labels = [];
        $missing = false;
        $options = $product->getOptions();
        $quantityKey = $quantity === null ? QuantityUnits::queryKey($name) : QuantityUnits::queryKey($quantity);
        foreach ($options ?? [] as $option) {
            $value = LanguageText::field($option, $languageId, 'name');
            if (!is_string($value) || trim($value) === '') {
                $missing = true;
                continue;
            }
            $value = trim($value);
            $optionQuantity = QuantityUnits::queryKey($value);
            if ($quantityKey !== null && $optionQuantity === $quantityKey) continue;
            // Do not repeat a whole option already visible in the product name.
            $pattern = '/(?<![\p{L}\p{N}])' . preg_quote($value, '/') . '(?![\p{L}\p{N}])/iu';
            if (preg_match($pattern, $name)) continue;
            $labels[mb_strtolower($value, 'UTF-8')] = $value;
        }
        // A missing option, or an otherwise undescribed variant, needs a
        // language-neutral identifier so customers can tell cards apart.
        $showNumber = $missing || (($options === null || $options->count() === 0) && $quantity === null && QuantityUnits::queryKey($name) === null);
        ksort($labels, SORT_STRING);
        return ['labels' => array_values($labels), 'showNumber' => $showNumber && trim($product->getProductNumber()) !== ''];
    }

    /** @param list<SalesChannelProductEntity> $products @return array<string, string> */
    public static function addNumbersForCollisions(array $products, array $names, array $quantities, array $options, array $numbers): array
    {
        $groups = [];
        foreach ($products as $product) {
            if ($product->getParentId() === null) continue;
            $id = $product->getId();
            $key = json_encode([$product->getParentId(), $names[$id], $quantities[$id] ?? null, $options[$id] ?? []], JSON_THROW_ON_ERROR);
            $groups[$key][] = $product;
        }
        foreach ($groups as $group) {
            if (count($group) < 2) continue;
            foreach ($group as $product) {
                if (trim($product->getProductNumber()) !== '') $numbers[$product->getId()] = $product->getProductNumber();
            }
        }
        return $numbers;
    }
}
