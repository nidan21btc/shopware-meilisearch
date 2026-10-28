<?php declare(strict_types=1);

namespace ShopwareMeilisearch\ScheduledTask;

use ShopwareMeilisearch\Command\IndexCommand;
use ShopwareMeilisearch\Search\DirtyState;
use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTaskHandler;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(handles: ReindexTask::class)]
final class ReindexTaskHandler extends ScheduledTaskHandler
{
    public function __construct(EntityRepository $repository, LoggerInterface $logger, private readonly IndexCommand $command, private readonly DirtyState $state, private readonly string $lockDirectory, private readonly \ShopwareMeilisearch\Search\PopularSearches $popularSearches)
    {
        parent::__construct($repository, $logger);
    }

    public function run(): void
    {
        try { $this->popularSearches->purge(); } catch (\Throwable $error) { $this->exceptionLogger->warning('Popular search cleanup failed', ['exceptionClass' => $error::class]); }
        if (!is_dir($this->lockDirectory) && !@mkdir($this->lockDirectory, 0700, true) && !is_dir($this->lockDirectory)) throw new \RuntimeException('Cannot create rebuild lock directory.');
        $lock = fopen($this->lockDirectory . '/rebuild-all.lock', 'c');
        if ($lock === false) throw new \RuntimeException('Cannot open rebuild lock.');
        if (!flock($lock, LOCK_EX | LOCK_NB)) { fclose($lock); return; }
        try {
            $requestRevision = $this->state->requestedRevision();
            $revision = $this->state->revisionToBuild();
            if ($revision === null) return;
            $this->state->started();
            $output = new BufferedOutput();
            $status = $this->command->run(new ArrayInput(['--enabled-only' => true], $this->command->getDefinition()), $output);
            if ($status !== Command::SUCCESS) throw new \RuntimeException('Local search index rebuild failed; inspect the private worker log.');
            $this->state->completed($revision, $requestRevision);
        } finally {
            fclose($lock);
        }
    }
}
