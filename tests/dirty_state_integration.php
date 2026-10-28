<?php declare(strict_types=1);

// This test mutates its state table and is restricted to a disposable database.
require (getenv('SHOPWARE_ROOT') ?: dirname(__DIR__, 4)) . '/vendor/autoload.php';
require dirname(__DIR__) . '/src/Migration/Migration1793120000SearchIndexState.php';
require dirname(__DIR__) . '/src/Search/DirtyState.php';
require dirname(__DIR__) . '/src/Subscriber/IndexInvalidationSubscriber.php';

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use ShopwareMeilisearch\Migration\Migration1793120000SearchIndexState;
use ShopwareMeilisearch\Search\DirtyState;
use ShopwareMeilisearch\Subscriber\IndexInvalidationSubscriber;
use Psr\Log\NullLogger;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityWriteResult;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenContainerEvent;
use Shopware\Core\System\SystemConfig\Event\SystemConfigMultipleChangedEvent;

$url = getenv('DATABASE_URL') ?: '';
$parts = parse_url($url);
if (!is_array($parts) || !preg_match('/^\/el_search_test_[a-f0-9]+$/D', $parts['path'] ?? '')) {
    throw new RuntimeException('Use an explicitly isolated el_search_test_* database.');
}
$connection = DriverManager::getConnection((new DsnParser(['mysql' => 'pdo_mysql']))->parse($url));
(new Migration1793120000SearchIndexState())->update($connection);
$connection->executeStatement('UPDATE shopware_meilisearch_state SET revision=1,indexed_revision=0,indexed_at=NULL WHERE id=1');
$state = new DirtyState($connection);
if ($state->revisionToBuild() !== 1) throw new RuntimeException('Initial index must be dirty.');
$state->markDirty();
$state->completed(1); // A write arrived while revision 1 was being rebuilt.
if ($state->revisionToBuild() !== 2) throw new RuntimeException('A concurrent change was lost.');
$state->completed(2);
if ($state->revisionToBuild() !== null) throw new RuntimeException('A completed index should be clean.');
$state->completed(1); // An older concurrent job must not lower the watermark.
if ($state->revisionToBuild() !== null) throw new RuntimeException('An older job regressed the watermark.');
$connection->executeStatement('UPDATE shopware_meilisearch_state SET indexed_at=DATE_SUB(UTC_TIMESTAMP(3), INTERVAL 16 MINUTE) WHERE id=1');
if ($state->revisionToBuild() !== 2) throw new RuntimeException('Periodic reconciliation did not trigger.');
$state->completed(2);
$subscriber = new IndexInvalidationSubscriber($state, new NullLogger());
$context = Context::createDefaultContext();
$write = static fn (string $entity): EntityWriteResult => new EntityWriteResult(str_repeat('a', 32), [], $entity, EntityWriteResult::OPERATION_UPDATE);
$subscriber->written(EntityWrittenContainerEvent::createWithWrittenEvents(['customer' => [$write('customer')]], $context, []));
if ($state->revisionToBuild() !== null) throw new RuntimeException('Unrelated customer write invalidated the index.');
$subscriber->written(EntityWrittenContainerEvent::createWithWrittenEvents(['product' => [$write('product')]], $context, []));
if ($state->revisionToBuild() !== 3) throw new RuntimeException('Product write did not invalidate the index.');
$state->completed(3);
$subscriber->written(EntityWrittenContainerEvent::createWithDeletedEvents(['category' => [$write('category')]], $context, []));
if ($state->revisionToBuild() !== 4) throw new RuntimeException('Category deletion did not invalidate the index.');
$state->completed(4);
$subscriber->configChanged(new SystemConfigMultipleChangedEvent(['core.test' => true], null));
if ($state->revisionToBuild() !== null) throw new RuntimeException('Unrelated configuration invalidated the index.');
$subscriber->configChanged(new SystemConfigMultipleChangedEvent(['ShopwareMeilisearch.config.popularQueries' => '{}'], null));
if ($state->revisionToBuild() !== null) throw new RuntimeException('Presentation settings invalidated the index.');
$subscriber->configChanged(new SystemConfigMultipleChangedEvent(['ShopwareMeilisearch.config.synonyms' => '{}'], null));
if ($state->revisionToBuild() !== 5) throw new RuntimeException('Search settings did not invalidate the index.');
$state->completed(5);
foreach (['unit', 'unit_translation', 'delivery_time', 'delivery_time_translation'] as $offset => $entity) {
    $subscriber->written(EntityWrittenContainerEvent::createWithWrittenEvents([$entity => [$write($entity)]], $context, []));
    $revision = 6 + $offset;
    if ($state->revisionToBuild() !== $revision) throw new RuntimeException($entity . ' did not invalidate the index.');
    $state->completed($revision);
}
$state->markDirty();
if ($state->revisionToBuild() !== 10) throw new RuntimeException('Revision did not advance past nine.');
$state->completed(10);
if ($state->revisionToBuild() !== null) throw new RuntimeException('Two-digit revision did not complete numerically.');
$state->completed(9);
if ($state->revisionToBuild() !== null) throw new RuntimeException('Older revision regressed a two-digit watermark.');
$connection->executeStatement('UPDATE shopware_meilisearch_state SET revision=100,indexed_revision=99 WHERE id=1');
$state->completed(100);
if ($state->revisionToBuild() !== null) throw new RuntimeException('Three-digit revision did not complete numerically.');
$state->completed(99);
if ($state->revisionToBuild() !== null) throw new RuntimeException('Older revision regressed a three-digit watermark.');
$subscriber->configChanged(new SystemConfigMultipleChangedEvent(['ShopwareMeilisearch.config.quantityUnits' => '{}'], null));
if ($state->revisionToBuild() !== 101) throw new RuntimeException('Quantity unit changes did not invalidate the index.');
$state->completed(101);

echo "Dirty-state integration checks passed, including writes during indexing.\n";
