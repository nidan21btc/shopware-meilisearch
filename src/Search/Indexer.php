<?php declare(strict_types=1);

namespace ShopwareMeilisearch\Search;

use Shopware\Core\Content\Product\Aggregate\ProductVisibility\ProductVisibilityDefinition;
use Shopware\Core\Content\Product\SalesChannel\ProductAvailableFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Shopware\Core\System\SalesChannel\Entity\SalesChannelRepository;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SystemConfig\SystemConfigService;

final class Indexer
{
    public function __construct(
        private readonly Engine $engine,
        private readonly SalesChannelRepository $products,
        private readonly DocumentBuilder $builder,
        private readonly ParentTranslations $parents,
        private readonly SystemConfigService $config,
        private readonly string $lockDirectory,
        private readonly ?PublishedSearchFields $publishedFields = null,
    ) {
    }

    public function rebuild(SalesChannelContext $context): int
    {
        $this->engine->ensureCompatible();
        $index = Engine::indexName($context->getSalesChannelId(), $context->getLanguageId());
        if (!is_dir($this->lockDirectory) && !mkdir($this->lockDirectory, 0700, true) && !is_dir($this->lockDirectory)) {
            throw new \RuntimeException('Cannot create search lock directory.');
        }
        $lock = fopen($this->lockDirectory . '/' . $index . '.lock', 'c');
        if ($lock === false) {
            throw new \RuntimeException('Cannot open search index lock.');
        }
        if (!flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);
            throw new \RuntimeException('An indexing run is already active for ' . $index);
        }

        try {
            return $this->build($context, $index);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function build(SalesChannelContext $context, string $index): int
    {
        $fields = IndexFields::forLocale(
            $this->config->getString('ShopwareMeilisearch.config.indexFields', $context->getSalesChannelId()),
            $this->config->getString('ShopwareMeilisearch.config.indexFieldsByLanguage', $context->getSalesChannelId()),
            $context->getLanguageInfo()->localeCode,
        );
        $quantityUnits = QuantityUnits::parse($this->config->getString('ShopwareMeilisearch.config.quantityUnits', $context->getSalesChannelId()));
        $json = $this->config->getString('ShopwareMeilisearch.config.synonyms', $context->getSalesChannelId());
        $byLanguage = $json === '' ? [] : json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($byLanguage)) {
            throw new \InvalidArgumentException('Synonyms must be a JSON object keyed by locale.');
        }
        $synonyms = $byLanguage[$context->getLanguageInfo()->localeCode] ?? [];
        if (!is_array($synonyms)) {
            throw new \InvalidArgumentException('Synonyms for a locale must be an object.');
        }
        foreach ($synonyms as $word => $alternatives) {
            if (!is_string($word) || !is_array($alternatives) || array_filter($alternatives, static fn ($v) => !is_string($v))) {
                throw new \InvalidArgumentException('Each synonym must map a word to an array of words.');
            }
        }

        // Fixed temporary name under an exclusive lock: interrupted builds can be recovered.
        $temporary = $index . '_building';
        if ($this->engine->request('GET', '/indexes/' . $temporary, allowMissing: true) !== null) {
            $this->engine->mutate('DELETE', '/indexes/' . $temporary);
        }
        $this->engine->mutate('POST', '/indexes', ['uid' => $temporary, 'primaryKey' => 'id']);
        $this->engine->mutate('PATCH', '/indexes/' . $temporary . '/settings', DocumentBuilder::settings($synonyms, $fields, $context->getLanguageInfo()->localeCode));

        $offset = 0;
        $indexed = 0;
        do {
            $criteria = (new Criteria())->setLimit(200)->setOffset($offset);
            $criteria->addFilter(new ProductAvailableFilter($context->getSalesChannelId(), ProductVisibilityDefinition::VISIBILITY_SEARCH));
            $criteria->addSorting(new FieldSorting('id'));
            foreach (['translations', 'manufacturer.translations', 'categories.translations', 'categoriesRo.translations', 'properties.translations', 'options.translations', 'unit.translations', 'deliveryTime.translations'] as $association) {
                $criteria->addAssociation($association);
            }
            $products = $this->products->search($criteria, $context);
            $this->parents->load($products->getEntities(), $context);
            $documents = [];
            foreach ($products->getEntities() as $product) {
                $document = $this->builder->build($product, $context->getLanguageId(), $fields, $quantityUnits);
                if ($document !== null) {
                    $documents[] = $document;
                }
            }
            if ($documents !== []) {
                $this->engine->mutate('POST', '/indexes/' . $temporary . '/documents', $documents);
            }
            $offset += $products->getEntities()->count();
            $indexed += count($documents);
        } while ($products->getEntities()->count() === 200);

        if ($this->engine->request('GET', '/indexes/' . $index, allowMissing: true) === null) {
            $this->engine->mutate('POST', '/indexes', ['uid' => $index, 'primaryKey' => 'id']);
        }
        $this->engine->mutate('POST', '/swap-indexes', [['indexes' => [$index, $temporary]]]);
        // Discard the old published settings immediately after the atomic swap.
        try { $this->publishedFields?->invalidate($index); } catch (\Throwable) { /* Cache expiry and request retry cover transient failures. */ }
        // After the atomic swap, this is the previous generation.
        $this->engine->mutate('DELETE', '/indexes/' . $temporary);

        return $indexed;
    }
}
