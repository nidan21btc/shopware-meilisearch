<?php declare(strict_types=1);

namespace ShopwareMeilisearch\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

final class Migration1793120000SearchIndexState extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1793120000;
    }

    public function update(Connection $connection): void
    {
        $connection->executeStatement('CREATE TABLE IF NOT EXISTS `shopware_meilisearch_state` (
            `id` TINYINT UNSIGNED NOT NULL PRIMARY KEY,
            `revision` BIGINT UNSIGNED NOT NULL DEFAULT 1,
            `indexed_revision` BIGINT UNSIGNED NOT NULL DEFAULT 0,
            `indexed_at` DATETIME(3) NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        $connection->executeStatement('INSERT IGNORE INTO `shopware_meilisearch_state` (`id`) VALUES (1)');
    }
}
