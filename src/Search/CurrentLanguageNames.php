<?php declare(strict_types=1);

namespace ShopwareMeilisearch\Search;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

/** Check current names and language-neutral fallback labels against Shopware. */
final class CurrentLanguageNames
{
    public function __construct(private readonly Connection $connection)
    {
    }

    /** @param list<string> $ids @return list<string> */
    public function keep(array $ids, SalesChannelContext $context): array
    {
        $names = $this->names($ids, $context);

        // Preserve the search engine's order and language-local inheritance.
        return array_values(array_filter($ids, static fn (string $id): bool => isset($names[$id])));
    }

    /** @param list<string> $ids @return array<string, string> */
    public function missingCodes(array $ids, SalesChannelContext $context): array
    {
        $ids = array_values(array_diff($ids, array_keys($this->names($ids, $context))));
        $codes = [];
        foreach (array_chunk($ids, 1000) as $batch) {
            $rows = $this->connection->fetchAllAssociative(
                'SELECT LOWER(HEX(id)) id, product_number FROM product WHERE version_id=:version AND id IN (:ids)',
                ['version' => Uuid::fromHexToBytes($context->getContext()->getVersionId()), 'ids' => Uuid::fromHexToBytesList($batch)],
                ['ids' => ArrayParameterType::BINARY],
            );
            foreach ($rows as $row) {
                if (is_string($row['product_number']) && trim($row['product_number']) !== '') $codes[$row['id']] = $row['product_number'];
            }
        }
        return $codes;
    }

    /** @param list<string> $ids @return array<string, string> */
    public function names(array $ids, SalesChannelContext $context): array
    {
        if ($ids === []) return [];
        $names = [];
        foreach (array_chunk($ids, 1000) as $batch) {
            $rows = $this->connection->fetchAllAssociative(
                'SELECT LOWER(HEX(p.id)) id, COALESCE(child.name, parent.name) name
                 FROM product p
                 LEFT JOIN product_translation child ON child.product_id=p.id AND child.product_version_id=p.version_id AND child.language_id=:childLanguage
                 LEFT JOIN product_translation parent ON parent.product_id=p.parent_id AND parent.product_version_id=p.parent_version_id AND parent.language_id=:parentLanguage
                 WHERE p.version_id=:version AND p.id IN (:ids)',
                ['childLanguage' => Uuid::fromHexToBytes($context->getLanguageId()), 'parentLanguage' => Uuid::fromHexToBytes($context->getLanguageId()), 'version' => Uuid::fromHexToBytes($context->getContext()->getVersionId()), 'ids' => Uuid::fromHexToBytesList($batch)],
                ['ids' => ArrayParameterType::BINARY],
            );
            foreach ($rows as $row) {
                if (is_string($row['name']) && trim($row['name']) !== '') $names[$row['id']] = $row['name'];
            }
        }

        return $names;
    }
}
