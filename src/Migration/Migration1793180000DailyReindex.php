<?php declare(strict_types=1);
namespace ShopwareMeilisearch\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

final class Migration1793180000DailyReindex extends MigrationStep
{
    public function getCreationTimestamp(): int { return 1793180000; }
    public function update(Connection $connection): void
    {
        $columns = $connection->createSchemaManager()->listTableColumns('shopware_meilisearch_state');
        foreach (['requested_revision' => 'BIGINT UNSIGNED NOT NULL DEFAULT 0', 'completed_request_revision' => 'BIGINT UNSIGNED NOT NULL DEFAULT 0', 'attempted_at' => 'DATETIME(3) NULL'] as $name => $type) {
            if (!isset($columns[$name])) $connection->executeStatement('ALTER TABLE shopware_meilisearch_state ADD ' . $name . ' ' . $type);
        }
    }
    public function updateDestructive(Connection $connection): void {}
}
