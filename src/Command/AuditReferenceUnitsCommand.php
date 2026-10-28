<?php declare(strict_types=1);

namespace ShopwareMeilisearch\Command;

use Doctrine\DBAL\Connection;
use ShopwareMeilisearch\Search\ReferenceUnitLabels;
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

#[AsCommand(name: 'shopware-meilisearch:search:audit-reference-units', description: 'Count reference-price units that cannot be displayed in each language.')]
final class AuditReferenceUnitsCommand extends Command
{
    public function __construct(private readonly Connection $connection, private readonly AbstractSalesChannelContextFactory $contexts,
        private readonly SalesChannelRepository $products, private readonly ReferenceUnitLabels $labels)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('sales-channel', null, InputOption::VALUE_REQUIRED, 'Limit the report to one sales channel UUID');
        $this->addOption('require-complete', null, InputOption::VALUE_NONE, 'Return failure if a calculated reference price has no safe unit label');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $requested = $input->getOption('sales-channel');
        if ($requested !== null && (!is_string($requested) || !preg_match('/^[a-f0-9]{32}$/D', $requested))) {
            throw new \InvalidArgumentException('Expected a sales channel UUID.');
        }
        $domains = $this->connection->fetchAllAssociative('SELECT DISTINCT LOWER(HEX(d.sales_channel_id)) channel, LOWER(HEX(d.language_id)) language, o.code locale
            FROM sales_channel_domain d JOIN sales_channel s ON s.id=d.sales_channel_id JOIN language l ON l.id=d.language_id JOIN locale o ON o.id=l.locale_id
            WHERE s.active=1 ORDER BY channel, language');
        $report = []; $failed = false;
        foreach ($domains as $domain) {
            if ($requested !== null && $requested !== $domain['channel']) continue;
            try {
                $context = $this->contexts->create(Uuid::randomHex(), $domain['channel'], [SalesChannelContextService::LANGUAGE_ID=>$domain['language']]);
                $count = 0; $references = 0; $displayable = 0; $missing = []; $offset = 0;
                do {
                    $criteria = (new Criteria())->setLimit(200)->setOffset($offset);
                    $criteria->addFilter(new ProductAvailableFilter($domain['channel'], ProductVisibilityDefinition::VISIBILITY_SEARCH));
                    $criteria->addSorting(new FieldSorting('id'));
                    $entities = $this->products->search($criteria, $context)->getEntities();
                    $batch = array_values(iterator_to_array($entities));
                    $labels = $this->labels->forProducts($batch, $context);
                    foreach ($batch as $product) {
                        ++$count;
                        $reference = ReferenceUnitLabels::referenceForProduct($product);
                        if ($reference === null) continue;
                        ++$references;
                        if (isset($labels[$product->getId()])) ++$displayable;
                        else $missing[$reference['id']] = ($missing[$reference['id']] ?? 0) + 1;
                    }
                    $size = count($batch); $offset += $size;
                } while ($size === 200);
                ksort($missing);
                $report[] = ['salesChannelId'=>$domain['channel'], 'languageId'=>$domain['language'], 'locale'=>$domain['locale'],
                    'activeSearchVisibleProducts'=>$count, 'calculatedReferencePrices'=>$references, 'displayableReferenceUnits'=>$displayable,
                    'missingReferenceUnits'=>$references-$displayable, 'missingByUnitId'=>(object)$missing];
                if ($input->getOption('require-complete') && $missing !== []) $failed = true;
            } catch (\Throwable $error) {
                $failed = true;
                $report[] = ['salesChannelId'=>$domain['channel'], 'languageId'=>$domain['language'], 'locale'=>$domain['locale'], 'errorClass'=>$error::class];
            }
        }
        if ($input->getOption('require-complete') && $report === []) $failed = true;
        $output->writeln(json_encode($report, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
