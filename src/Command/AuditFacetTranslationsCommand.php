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

#[AsCommand(name: 'shopware-meilisearch:search:audit-facet-translations', description: 'Find missing manufacturer and category labels in index languages.')]
final class AuditFacetTranslationsCommand extends Command
{
    public function __construct(private readonly Connection $connection, private readonly AbstractSalesChannelContextFactory $contexts,
        private readonly SalesChannelRepository $products, private readonly ParentTranslations $parents)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('output', null, InputOption::VALUE_REQUIRED, 'Write missing labels as JSON lines to a new private file');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $file = null;
        $path = $input->getOption('output');
        if ($path !== null) {
            if (!is_string($path) || $path === '') throw new \InvalidArgumentException('Invalid output path.');
            $mask = umask(0077);
            try { $file = @fopen($path, 'x'); } finally { umask($mask); }
            if ($file === false) throw new \RuntimeException('Cannot create a new audit report.');
        }
        try {
            $locales = $this->connection->fetchAllKeyValue('SELECT LOWER(HEX(l.id)), o.code FROM language l JOIN locale o ON o.id=l.locale_id');
            $domains = $this->connection->fetchAllAssociative('SELECT DISTINCT LOWER(HEX(d.sales_channel_id)) channel_id, LOWER(HEX(d.language_id)) language_id FROM sales_channel_domain d JOIN sales_channel c ON c.id=d.sales_channel_id WHERE c.active=1');
            foreach ($domains as $domain) {
                $context = $this->contexts->create(Uuid::randomHex(), $domain['channel_id'], [SalesChannelContextService::LANGUAGE_ID => $domain['language_id']]);
                $missing = ['manufacturer' => [], 'category' => []];
                $offset = 0;
                do {
                    $criteria = (new Criteria())->setLimit(200)->setOffset($offset);
                    $criteria->addFilter(new ProductAvailableFilter($context->getSalesChannelId(), ProductVisibilityDefinition::VISIBILITY_SEARCH));
                    $criteria->addSorting(new FieldSorting('id'));
                    foreach (['translations', 'manufacturer.translations', 'categoriesRo.translations'] as $association) $criteria->addAssociation($association);
                    $entities = $this->products->search($criteria, $context)->getEntities();
                    $this->parents->load($entities, $context);
                    foreach ($entities as $product) {
                        $productName = LanguageText::field($product, $domain['language_id'], 'name');
                        if (!is_string($productName) || trim($productName) === '') continue;
                        $manufacturer = $product->getManufacturer();
                        $facets = ['manufacturer' => $manufacturer ? [$manufacturer] : [], 'category' => $product->getCategoriesRo() ?? []];
                        foreach ($facets as $kind => $values) foreach ($values as $entity) {
                            if (method_exists($entity, 'getActive') && !$entity->getActive()) continue;
                            $name = LanguageText::field($entity, $domain['language_id'], 'name');
                            if (is_string($name) && trim($name) !== '') continue;
                            $id = $entity->getId();
                            if (!isset($missing[$kind][$id])) {
                                $reference = null;
                                foreach ($entity->getTranslations() ?? [] as $translation) {
                                    $candidate = $translation->getName();
                                    if (is_string($candidate) && trim($candidate) !== '') {
                                        $reference = ['locale' => $locales[$translation->getLanguageId()] ?? null, 'name' => $candidate];
                                        break;
                                    }
                                }
                                $missing[$kind][$id] = ['salesChannelId' => $domain['channel_id'], 'languageId' => $domain['language_id'],
                                    'locale' => $context->getLanguageInfo()->localeCode, 'kind' => $kind, 'id' => $id,
                                    'referenceOnly' => $reference, 'affectedProducts' => 0];
                            }
                            ++$missing[$kind][$id]['affectedProducts'];
                        }
                    }
                    $count = $entities->count();
                    $offset += $count;
                } while ($count === 200);
                foreach ($missing as $items) foreach ($items as $record) if ($file !== null) {
                    $line = json_encode($record, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE) . "\n";
                    if (fwrite($file, $line) !== strlen($line)) throw new \RuntimeException('Cannot write audit report.');
                }
                $output->writeln(sprintf('%s / %s (%s): %d manufacturers and %d categories lack a label', $domain['channel_id'], $domain['language_id'], $context->getLanguageInfo()->localeCode, count($missing['manufacturer']), count($missing['category'])));
            }
        } finally {
            if (is_resource($file)) fclose($file);
        }
        return self::SUCCESS;
    }
}
