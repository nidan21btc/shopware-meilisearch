<?php declare(strict_types=1);

namespace ShopwareMeilisearch;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Plugin;
use Shopware\Core\Framework\Plugin\Context\UninstallContext;

class ShopwareMeilisearch extends Plugin
{
    public function uninstall(UninstallContext $uninstallContext): void
    {
        parent::uninstall($uninstallContext);
        if (!$uninstallContext->keepUserData()) {
            $this->container->get(Connection::class)->executeStatement('DROP TABLE IF EXISTS `shopware_meilisearch_state`');
        }
    }
}
