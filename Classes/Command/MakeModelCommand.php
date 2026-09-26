<?php

declare(strict_types=1);

namespace AskoEducation\Cbm\Command;

use AskoEducation\Cbm\Service\ModelGenerator\ModelGenerator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Attribute\AsNonSchedulableCommand;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Utility\GeneralUtility;

#[AsCommand('cbm:make:model', 'Generate an Extbase model (with getters and setters) from the fields of a Content Block')]
#[AsNonSchedulableCommand]
final class MakeModelCommand extends Command
{
    public function __construct(
        private readonly ModelGenerator $modelGenerator,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('content-block', InputArgument::REQUIRED, 'Content Block name, e.g. "vendor/name", or just the name, e.g. "Hero"')
            ->addOption('repository', 'r', InputOption::VALUE_NONE, 'Also generate an (empty) repository')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Overwrite existing classes')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Print the generated code instead of writing it');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        try {
            $generated = $this->modelGenerator->generate((string) $input->getArgument('content-block'), (bool) $input->getOption('repository'));
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            $io->error($e->getMessage());
            return Command::INVALID;
        }
        $dryRun = (bool) $input->getOption('dry-run');
        $force = (bool) $input->getOption('force');

        foreach ($generated->files as $path => $content) {
            $shown = $this->relative($path);
            if ($dryRun) {
                $io->section($shown);
                $io->writeln($content, OutputInterface::OUTPUT_RAW);
            } elseif (is_file($path) && !$force) {
                $io->writeln(sprintf('<comment>kept</comment>    %s (exists, use --force to overwrite)', $shown));
            } else {
                $this->write($io, $path, $content);
            }
        }

        if ($generated->mappings === []) {
            return Command::SUCCESS;
        }
        // Classes.php is PHP written by hand: it is only created, never changed.
        if (!is_file($generated->persistenceFile) && !$dryRun) {
            $this->write($io, $generated->persistenceFile, $generated->renderPersistenceFile());
            $io->note('Flush the caches so that Extbase picks up the new persistence mapping.');
            return Command::SUCCESS;
        }
        $io->section('Add to ' . $this->relative($generated->persistenceFile));
        $io->writeln($generated->renderMappingEntries(), OutputInterface::OUTPUT_RAW);
        $io->note('Extbase needs these entries: the table or the column names differ from its conventions.');
        return Command::SUCCESS;
    }

    private function write(SymfonyStyle $io, string $path, string $content): void
    {
        GeneralUtility::mkdir_deep(dirname($path));
        GeneralUtility::writeFile($path, $content);
        $io->writeln(sprintf('<info>written</info> %s', $this->relative($path)));
    }

    private function relative(string $path): string
    {
        $path = (string) (realpath(dirname($path)) ?: dirname($path)) . '/' . basename($path);
        return str_replace(Environment::getProjectPath() . '/', '', $path);
    }
}
