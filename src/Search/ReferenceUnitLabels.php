<?php declare(strict_types=1);

namespace ShopwareMeilisearch\Search;

use Shopware\Core\Content\Product\DataAbstractionLayer\VariantListingConfig;
use Shopware\Core\Content\Product\SalesChannel\SalesChannelProductEntity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\Unit\UnitCollection;
use Shopware\Core\System\SystemConfig\SystemConfigService;

/** Ground-price units are read in the current language or shown as neutral SI symbols. */
final class ReferenceUnitLabels
{
    /** @param EntityRepository<UnitCollection> $units */
    public function __construct(private readonly EntityRepository $units, private readonly SystemConfigService $config)
    {
    }

    /** @param list<SalesChannelProductEntity> $products @return array<string, array{amount: float|string, unit: string}> */
    public function forProducts(array $products, SalesChannelContext $context): array
    {
        $references = [];
        $unitIds = [];
        foreach ($products as $product) {
            $reference = self::referenceForProduct($product);
            if ($reference === null) continue;
            $references[$product->getId()] = $reference;
            $unitIds[$reference['id']] = true;
        }
        if ($unitIds === []) return [];
        $criteria = new Criteria(array_keys($unitIds));
        $criteria->addAssociation('translations');
        $localNames = [];
        foreach ($this->units->search($criteria, $context->getContext())->getEntities() as $unit) {
            $name = LanguageText::field($unit, $context->getLanguageId(), 'name');
            if (is_string($name) && trim($name) !== '') $localNames[$unit->getId()] = trim($name);
        }
        try {
            $mapping = QuantityUnits::parse($this->config->getString('ShopwareMeilisearch.config.quantityUnits', $context->getSalesChannelId()));
        } catch (\Throwable) {
            // A bad optional mapping must never break the normal product search.
            $mapping = [];
        }
        $result = [];
        foreach ($references as $productId=>$reference) {
            $label = self::label($reference['id'], $reference['amount'], $reference['fallback'], $localNames, $mapping);
            if ($label !== null) $result[$productId] = $label;
        }
        return $result;
    }

    /** @return array{id: string, amount: float, fallback: string}|null */
    public static function referenceForProduct(SalesChannelProductEntity $product): ?array
    {
        $price = $product->getCalculatedPrices()->last() ?? $product->getCalculatedPrice();
        $unitId = $product->getUnitId();
        $listing = $product->getVariantListingConfig();
        if ($product->getParentId() === null && $listing instanceof VariantListingConfig && $listing->getDisplayParent()) {
            $price = $product->getCalculatedCheapestPrice();
            // The cheapest family member may use a different physical unit.
            $unitId = $product->getCheapestPrice()?->getUnitId() ?? $unitId;
        }
        $reference = $price->getReferencePrice();
        if ($reference === null || $unitId === null) return null;
        return ['id'=>$unitId, 'amount'=>$reference->getReferenceUnit(), 'fallback'=>$reference->getUnitName()];
    }

    /** @return array{amount: float|string, unit: string}|null */
    public static function label(string $unitId, float $amount, string $fallback, array $localNames, array $mapping): ?array
    {
        if (isset($localNames[$unitId]) && is_string($localNames[$unitId]) && trim($localNames[$unitId]) !== '') {
            return ['amount'=>$amount, 'unit'=>trim($localNames[$unitId])];
        }
        // SI symbols do not borrow a word from another language.
        if (in_array($fallback, ['l', 'L', 'ml', 'mL', 'g', 'kg'], true)) {
            return ['amount'=>$amount, 'unit'=>$fallback];
        }
        $tokens = QuantityUnits::tokens($unitId, $amount, $mapping);
        if ($tokens !== [] && preg_match('/^([0-9]+(?:\.[0-9]+)?) (ml|l|g|kg)$/D', $tokens[0], $match)) {
            return ['amount'=>$match[1], 'unit'=>$match[2]];
        }
        return null;
    }
}
