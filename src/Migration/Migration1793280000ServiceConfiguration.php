<?php declare(strict_types=1);

namespace ShopwareMeilisearch\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

final class Migration1793280000ServiceConfiguration extends MigrationStep
{
    public function getCreationTimestamp(): int { return 1793280000; }

    public function update(Connection $connection): void
    {
        $connection->executeStatement('CREATE TABLE IF NOT EXISTS `shopware_meilisearch_connection` (
            `id` TINYINT UNSIGNED NOT NULL PRIMARY KEY,
            `instance` CHAR(64) NOT NULL,
            `configuration` LONGTEXT NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    }
}
