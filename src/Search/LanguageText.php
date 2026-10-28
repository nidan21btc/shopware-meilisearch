<?php declare(strict_types=1);

namespace ShopwareMeilisearch\Search;

use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\Framework\DataAbstractionLayer\Entity;

/** Read actual translations, never Shopware's cross-language `translated` fallback. */
final class LanguageText
{
    public static function customField(?ProductEntity $product, string $languageId, string $key): mixed
    {
        if ($product === null) {
            return null;
        }
        foreach ($product->getTranslations() ?? [] as $translation) {
            if ($translation->getLanguageId() === $languageId) {
                $values = $translation->getCustomFields() ?? [];
                if (array_key_exists($key, $values) && $values[$key] !== null) {
                    return $values[$key];
                }
                break;
            }
        }

        return self::customField($product->getParent(), $languageId, $key);
    }

    public static function field(?Entity $entity, string $languageId, string $field): mixed
    {
        if ($entity === null || !method_exists($entity, 'getTranslations')) {
            return null;
        }
        $getter = 'get' . ucfirst($field);
        foreach ($entity->getTranslations() ?? [] as $translation) {
            if ($translation->getLanguageId() === $languageId) {
                $value = $translation->$getter();
                if ($value !== null) {
                    return $value;
                }
                break;
            }
        }
        // Variant inheritance is allowed only within the very same language.
        if ($entity instanceof ProductEntity && $entity->getParent() !== null) {
            return self::field($entity->getParent(), $languageId, $field);
        }

        return null;
    }
}
