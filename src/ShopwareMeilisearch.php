<?php declare(strict_types=1);

namespace ShopwareMeilisearch;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Plugin;
use Shopware\Core\Framework\Plugin\Context\UninstallContext;
use Shopware\Core\Kernel;

class ShopwareMeilisearch extends Plugin
{
    public function removeMigrations(): void
    {
        // Shopware's generic guard reserves every namespace beginning with
        // "shopware". Remove only this plugin's own migration records.
        $namespace = $this->getMigrationNamespace();
        if ($namespace !== __NAMESPACE__ . '\\Migration') {
            throw new \LogicException('Unexpected plugin migration namespace.');
        }
        $pattern = addcslashes($namespace . '\\', '\\_%') . '%';
        Kernel::getConnection()->executeStatement('DELETE FROM migration WHERE class LIKE :class', ['class' => $pattern]);
    }

    public function uninstall(UninstallContext $uninstallContext): void
    {
        parent::uninstall($uninstallContext);
        if (!$uninstallContext->keepUserData()) {
            $this->container->get(Connection::class)->executeStatement('DROP TABLE IF EXISTS `shopware_meilisearch_state`');
            $this->container->get(Connection::class)->executeStatement('DROP TABLE IF EXISTS `shopware_meilisearch_connection`');
            $this->container->get(Connection::class)->executeStatement('DROP TABLE IF EXISTS `shopware_meilisearch_popular_query`');
            $this->container->get(Connection::class)->executeStatement('DROP TABLE IF EXISTS `shopware_meilisearch_popular_session`');
        }
    }
}
