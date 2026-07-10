<?php

declare(strict_types=1);

namespace App\Command;

use App\Game\Core\Service\LobbyManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

// How to use, see
// docs/architecture.md
#[AsCommand(name: 'app:lobby:cleanup', description: 'Delete lobbies nobody is connected to anymore')]
final class LobbyCleanupCommand extends Command
{
    public function __construct(private readonly LobbyManager $lobbyManager)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $removed = $this->lobbyManager->pruneStale();

        (new SymfonyStyle($input, $output))->success(\sprintf('Removed %d stale lobbies.', $removed));

        return Command::SUCCESS;
    }
}
