<?php declare(strict_types=1);

namespace ShopwareMeilisearch\Command;

use Doctrine\DBAL\Connection;
use ShopwareMeilisearch\Search\LanguageText;
use ShopwareMeilisearch\Search\ParentTranslations;
use Shopware\Core\Content\Product\Aggregate\ProductVisibility\ProductVisibilityDefinition;
use Shopware\Core\Content\Product\SalesChannel\ProductAvailableFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SalesChannel\Context\AbstractSalesChannelContextFactory;
use Shopware\Core\System\SalesChannel\Context\SalesChannelContextService;
use Shopware\Core\System\SalesChannel\Entity\SalesChannelRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'shopware-meilisearch:search:audit-translations', description: 'Find visible products missing a name in each index language.')]
final class AuditTranslationsCommand extends Command
{
    public function __construct(
        private readonly Connection $connection,
        private readonly AbstractSalesChannelContextFactory $contexts,
        private readonly SalesChannelRepository $products,
        private readonly ParentTranslations $parents,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('output', null, InputOption::VALUE_REQUIRED, 'Write missing products as JSON lines to a new private file');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $file = null;
        $path = $input->getOption('output');
        if ($path !== null) {
            if (!is_string($path) || $path === '') {
                throw new \InvalidArgumentException('Invalid output path.');
            }
            // Do not overwrite an existing file, and create the report private
            // from the outset even when the caller uses a permissive umask.
            $previousMask = umask(0077);
            try {
                $file = @fopen($path, 'x');
            } finally {
                umask($previousMask);
            }
            if ($file === false) {
                throw new \RuntimeException('Cannot create a new audit report.');
            }
        }
        try {
            $domains = $this->connection->fetchAllAssociative(
                'SELECT DISTINCT LOWER(HEX(d.sales_channel_id)) channel_id, LOWER(HEX(d.language_id)) language_id
                 FROM sales_channel_domain d INNER JOIN sales_channel c ON c.id = d.sales_channel_id WHERE c.active = 1'
            );
            foreach ($domains as $domain) {
                $context = $this->contexts->create(Uuid::randomHex(), $domain['channel_id'], [SalesChannelContextService::LANGUAGE_ID => $domain['language_id']]);
                $offset = $missing = 0;
                do {
                    $criteria = (new Criteria())->setLimit(200)->setOffset($offset);
                    $criteria->addFilter(new ProductAvailableFilter($context->getSalesChannelId(), ProductVisibilityDefinition::VISIBILITY_SEARCH));
                    $criteria->addSorting(new FieldSorting('id'));
                    $criteria->addAssociation('translations');
                    $entities = $this->products->search($criteria, $context)->getEntities();
                    $this->parents->load($entities, $context);
                    foreach ($entities as $product) {
                        $name = LanguageText::field($product, $context->getLanguageId(), 'name');
                        if (is_string($name) && trim($name) !== '') {
                            continue;
                        }
                        ++$missing;
                        if ($file !== null) {
                            $line = json_encode(['salesChannelId' => $domain['channel_id'], 'languageId' => $domain['language_id'],
                                'locale' => $context->getLanguageInfo()->localeCode, 'productId' => $product->getId(),
                                'productNumber' => $product->getProductNumber()], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE) . "\n";
                            if (fwrite($file, $line) !== strlen($line)) {
                                throw new \RuntimeException('Cannot write audit report.');
                            }
                        }
                    }
                    $count = $entities->count();
                    $offset += $count;
                } while ($count === 200);
                $output->writeln(sprintf('%s / %s (%s): %d of %d products lack a name', $domain['channel_id'], $domain['language_id'], $context->getLanguageInfo()->localeCode, $missing, $offset));
            }
        } finally {
            if (is_resource($file)) {
                fclose($file);
            }
        }
        return self::SUCCESS;
    }
}
