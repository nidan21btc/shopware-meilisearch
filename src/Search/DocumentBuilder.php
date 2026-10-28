<?php declare(strict_types=1);

namespace ShopwareMeilisearch\Search;

use Shopware\Core\Content\Product\SalesChannel\SalesChannelProductEntity;

final class DocumentBuilder
{
    public function build(SalesChannelProductEntity $product, string $languageId, array $fields = IndexFields::DEFAULTS, int $popularity = 0, array $queryHints = [], array $quantityUnits = []): ?array
    {
        $name = LanguageText::field($product, $languageId, 'name');
        // Classify the same visible name text that will actually be indexed.
        $unnamed = !is_string($name) || IndexFields::text($name) === '';
        $categories = [];
        foreach ([$product->getCategories(), $product->getCategoriesRo()] as $collection) {
            foreach ($collection ?? [] as $category) {
                if ($category->getActive()) {
                    $categories[] = LanguageText::field($category, $languageId, 'name') ?? '';
                }
            }
        }
        $properties = [];
        $options = [];
        foreach ([$product->getProperties(), $product->getOptions()] as $collection) {
            foreach ($collection ?? [] as $option) {
                $properties[] = LanguageText::field($option, $languageId, 'name') ?? '';
            }
        }
        foreach ($product->getOptions() ?? [] as $option) {
            $options[] = LanguageText::field($option, $languageId, 'name') ?? '';
        }
        $manufacturer = $product->getManufacturer();

        $values = [
            'id' => $product->getId(),
            // Group variants without sacrificing exact variant-number matches.
            'groupId' => $product->getParentId() ?? $product->getId(),
            'productNumber' => $product->getProductNumber(),
            'ean' => $product->getEan() ?? '',
            'manufacturerNumber' => $product->getManufacturerNumber() ?? '',
            'name' => $name ?? '',
            'quantity' => QuantityUnits::tokens($product->getUnitId(), $product->getPurchaseUnit(), $quantityUnits),
            'keywords' => LanguageText::field($product, $languageId, 'keywords') ?? '',
            'customSearchKeywords' => LanguageText::field($product, $languageId, 'customSearchKeywords') ?? [],
            'manufacturer' => LanguageText::field($manufacturer, $languageId, 'name') ?? '',
            'manufacturerDescription' => LanguageText::field($manufacturer, $languageId, 'description') ?? '',
            'categories' => array_values(array_unique(array_filter($categories))),
            'properties' => array_values(array_unique(array_filter($properties))),
            'options' => array_values(array_unique(array_filter($options))),
            'description' => LanguageText::field($product, $languageId, 'description') ?? '',
            'unit' => LanguageText::field($product->getUnit(), $languageId, 'name') ?? '',
            'unitShortCode' => LanguageText::field($product->getUnit(), $languageId, 'shortCode') ?? '',
            'deliveryTime' => LanguageText::field($product->getDeliveryTime(), $languageId, 'name') ?? '',
            'weight' => $product->getWeight(),
            'width' => $product->getWidth(),
            'height' => $product->getHeight(),
            'length' => $product->getLength(),
            'purchaseUnit' => $product->getPurchaseUnit(),
            'referenceUnit' => $product->getReferenceUnit(),
            'releaseDate' => $product->getReleaseDate()?->format('Y-m-d'),
            // Prices and availability are resolved by Shopware at search time.
        ];
        $document = array_intersect_key($values, array_flip(['id', 'groupId', 'manufacturer', 'categories']));
        $document['popularity'] = $popularity;
        if ($queryHints !== []) {
            $document['queryHints'] = $queryHints;
        }
        foreach ($fields as $field) {
            if (str_starts_with($field, 'customFields.')) {
                $key = substr($field, strlen('customFields.'));
                $document['customFields'][$key] = IndexFields::text(LanguageText::customField($product, $languageId, $key));
            } else {
                $document[$field] = IndexFields::text(array_key_exists($field, $values) ? $values[$field] : LanguageText::field($product, $languageId, $field));
                if ($field === 'name' && is_string($document[$field])) {
                    $aliases = NameAliases::hyphenCompounds($document[$field]);
                    if ($aliases !== []) $document['nameHyphenAliases'] = $aliases;
                }
                if ($field === 'quantity') {
                    $key = QuantityUnits::key($product->getUnitId(), $product->getPurchaseUnit(), $quantityUnits);
                    if ($key !== null) $document['quantityKey'] = $key;
                }
            }
        }

        if ($unnamed) {
            // Only a language-local, product-specific text (or explicitly
            // imported language-local hints) can qualify a nameless product.
            // A category, manufacturer or identifier alone must not make it
            // appear in unrelated natural-language searches.
            $ownText = $queryHints !== [];
            foreach ($fields as $field) {
                if (str_starts_with($field, 'customFields.')) {
                    $value = $document['customFields'][substr($field, strlen('customFields.'))] ?? '';
                } elseif (in_array($field, ['keywords', 'customSearchKeywords', 'description', 'metaTitle', 'metaDescription', 'ogTitle', 'ogDescription', 'packUnit', 'packUnitPlural'], true)) {
                    $value = $document[$field] ?? '';
                } else {
                    continue;
                }
                if ($value !== '' && $value !== []) $ownText = true;
            }
            if (!$ownText) return null;
            $document['unnamed'] = true;
        }

        return $document;
    }

    public static function settings(array $synonyms, array $fields = IndexFields::DEFAULTS, string $locale = '', bool $useQueryHints = false): array
    {
        $rules = ['words', 'typo', 'proximity', 'attribute', 'exactness', 'popularity:desc', 'sort'];
        $shortTypos = in_array($locale, ['de-DE', 'es-ES', 'fr-FR', 'it-IT'], true);
        $oneTypoAtFour = $shortTypos || $locale === 'en-GB';
        $searchable = $fields;
        $namePosition = array_search('name', $searchable, true);
        if ($namePosition !== false) array_splice($searchable, $namePosition + 1, 0, ['nameHyphenAliases']);
        if ($useQueryHints) {
            // Repeated product clicks are a useful signal, but the configured
            // title, identifiers and other fields keep their own priority.
            $namePosition = array_search('name', $searchable, true);
            $descriptionPosition = array_search('description', $searchable, true);
            $position = $namePosition !== false ? $namePosition + 1 : ($descriptionPosition === false ? count($searchable) : $descriptionPosition);
            array_splice($searchable, $position, 0, ['queryHints']);
        }

        return [
            'searchableAttributes' => $searchable,
            'displayedAttributes' => ['id', 'unnamed', 'quantityKey'],
            'filterableAttributes' => ['manufacturer', 'categories'],
            // Different sizes/variants can have their own price and buy button.
            // Collapsing them here hides valid results and breaks price filters.
            'distinctAttribute' => null,
            'rankingRules' => $rules,
            'typoTolerance' => [
                'enabled' => true,
                'minWordSizeForTypos' => ['oneTypo' => $oneTypoAtFour ? 4 : 5, 'twoTypos' => $shortTypos ? 8 : 9],
                'disableOnAttributes' => array_values(array_intersect(['productNumber', 'ean', 'manufacturerNumber', 'quantity', 'nameHyphenAliases'], $searchable)),
            ],
            'synonyms' => (object) $synonyms,
            'pagination' => ['maxTotalHits' => 10000],
            'faceting' => ['maxValuesPerFacet' => 100],
        ];
    }
}
