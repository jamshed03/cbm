<?php

declare(strict_types=1);

namespace AskoEducation\Cbm\Command;

use AskoEducation\Cbm\Service\LabelMigration\LabelMigrationService;
use AskoEducation\Cbm\Service\LabelMigration\MigrationOptions;
use AskoEducation\Cbm\Service\LabelMigration\MigrationPlan;
use AskoEducation\Cbm\Service\LabelMigration\Prefer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Attribute\AsNonSchedulableCommand;

#[AsCommand('cbm:labels:migrate', 'Move labels of Content Blocks from config.yaml into language/labels.xlf')]
#[AsNonSchedulableCommand]
final class MigrateLabelsCommand extends Command
{
    public function __construct(
        private readonly LabelMigrationService $labelMigrationService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('content-block', InputArgument::OPTIONAL, 'Content Block name, e.g. "vendor/name"')
            ->addOption('extension', 'e', InputOption::VALUE_REQUIRED, 'Migrate all Content Blocks of this extension')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show what would change without writing files')
            ->addOption('keep-yaml', null, InputOption::VALUE_NONE, 'Only write labels.xlf, leave config.yaml untouched')
            ->addOption('prefer', null, InputOption::VALUE_REQUIRED, 'Which value wins if config.yaml and labels.xlf differ: "xlf" or "yaml"', Prefer::Xlf->value);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $contentBlock = $input->getArgument('content-block');
        $extension = $input->getOption('extension');
        if (($contentBlock === null) === ($extension === null)) {
            $io->error('Pass either a Content Block name or --extension.');
            return Command::INVALID;
        }
        $prefer = Prefer::tryFrom((string)$input->getOption('prefer'));
        if ($prefer === null) {
            $io->error('--prefer must be "xlf" or "yaml".');
            return Command::INVALID;
        }
        try {
            $plans = $this->labelMigrationService->plan(
                $contentBlock,
                $extension,
                new MigrationOptions(keepYaml: (bool)$input->getOption('keep-yaml'), prefer: $prefer),
            );
        } catch (\InvalidArgumentException $e) {
            $io->error($e->getMessage());
            return Command::INVALID;
        }

        $dryRun = (bool)$input->getOption('dry-run');
        $verbose = $dryRun || $output->isVerbose();
        foreach ($plans as $plan) {
            if (!$dryRun) {
                $this->labelMigrationService->apply($plan);
            }
            $this->report($io, $plan, $verbose);
        }
        $io->table(
            ['Content Block', 'labels.xlf', 'config.yaml', 'Conflicts', 'Status'],
            array_map(static fn(MigrationPlan $plan): array => [
                $plan->contentBlock,
                $plan->newXlf !== null ? '+' . count($plan->addedKeys) . ' keys' : '-',
                $plan->newYaml !== null ? count($plan->removed) . ' removed' : '-',
                count($plan->conflicts) ?: '-',
                $plan->error !== null ? '<error>failed</error>' : ($plan->hasChanges() ? 'migrated' : 'nothing to do'),
            ], $plans),
        );
        if ($dryRun) {
            $io->note('Dry run: no files were written.');
        }
        $failed = array_filter($plans, static fn(MigrationPlan $plan): bool => $plan->error !== null);
        return $failed === [] ? Command::SUCCESS : Command::FAILURE;
    }

    private function report(SymfonyStyle $io, MigrationPlan $plan, bool $verbose): void
    {
        if (!$plan->hasFindings() && !($verbose && $plan->hasChanges())) {
            return;
        }
        $io->section($plan->contentBlock);
        if ($plan->error !== null) {
            $io->error($plan->error . ' config.yaml is left untouched' . ($plan->newXlf !== null ? ', labels.xlf is updated.' : '.'));
        }
        foreach ($plan->conflicts as $key => $conflict) {
            $io->warning(sprintf('%s: config.yaml says "%s", labels.xlf says "%s" – keeping "%s".', $key, $conflict['yaml'], $conflict['xlf'], $conflict['kept']));
        }
        foreach ($plan->skipped as $label) {
            $io->writeln(sprintf('  <comment>kept</comment> config.yaml:%d %s: %s (list item would become empty)', $label->lineNumber(), $label->key, $label->value));
        }
        if (!$verbose) {
            return;
        }
        foreach ($plan->addedKeys as $key => $value) {
            $io->writeln(sprintf('  <info>+ labels.xlf</info> %s = "%s"', $key, $value));
        }
        foreach ($plan->removed as $label) {
            $io->writeln(sprintf('  <fg=red>- config.yaml:%d</> %s: %s', $label->lineNumber(), $label->key, $label->value));
        }
    }
}
