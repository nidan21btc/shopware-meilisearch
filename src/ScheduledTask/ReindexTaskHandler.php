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
    public function __construct(EntityRepository $repository, LoggerInterface $logger, private readonly IndexCommand $command, private readonly DirtyState $state)
    {
        parent::__construct($repository, $logger);
    }

    public function run(): void
    {
        $revision = $this->state->revisionToBuild();
        if ($revision === null) {
            return;
        }
        $output = new BufferedOutput();
        $status = $this->command->run(new ArrayInput(['--enabled-only' => true], $this->command->getDefinition()), $output);
        if ($status !== Command::SUCCESS) {
            // The command emits only UUIDs and exception classes, never secrets.
            throw new \RuntimeException('Local search index rebuild failed: ' . trim($output->fetch()));
        }
        $this->state->completed($revision);
    }
}
