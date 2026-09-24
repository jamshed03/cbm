<?php

declare(strict_types=1);

namespace AskoEducation\Cbm\Command;

use AskoEducation\Cbm\Service\BackendPreview\BackendPreviewService;
use AskoEducation\Cbm\Service\BackendPreview\PreviewPlan;
use AskoEducation\Cbm\Service\BackendPreview\PreviewStatus;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Attribute\AsNonSchedulableCommand;

#[AsCommand('cbm:preview:generate', 'Generate backend previews of Content Blocks that have none or only a placeholder')]
#[AsNonSchedulableCommand]
final class GeneratePreviewCommand extends Command
{
    public function __construct(
        private readonly BackendPreviewService $backendPreviewService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('content-block', InputArgument::OPTIONAL, 'Content Block name, e.g. "vendor/name"')
            ->addOption('extension', 'e', InputOption::VALUE_REQUIRED, 'Generate for all Content Blocks of this extension')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Also overwrite previews written by hand')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show what would change without writing files');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $contentBlock = $input->getArgument('content-block');
        $extension = $input->getOption('extension');
        try {
            $plans = $this->backendPreviewService->plan($contentBlock, $extension, (bool)$input->getOption('force'));
        } catch (\InvalidArgumentException $e) {
            $io->error($e->getMessage());
            return Command::INVALID;
        }

        $dryRun = (bool)$input->getOption('dry-run');
        foreach ($plans as $plan) {
            if (!$dryRun) {
                $this->backendPreviewService->apply($plan);
            } elseif ($plan->newContent !== null && $output->isVerbose()) {
                $io->section($plan->contentBlock);
                $io->writeln($plan->newContent, OutputInterface::OUTPUT_RAW);
            }
        }
        $io->table(
            ['Content Block', 'Preview', 'Action'],
            array_map(fn(PreviewPlan $plan): array => [$plan->contentBlock, $plan->status->value, $this->describe($plan)], $plans),
        );
        if ($dryRun) {
            $io->note('Dry run: no files were written. Add -v to see the generated templates.');
        }
        return Command::SUCCESS;
    }

    private function describe(PreviewPlan $plan): string
    {
        if ($plan->newContent !== null) {
            return $plan->status === PreviewStatus::Custom ? '<comment>overwritten</comment>' : '<info>generated</info>';
        }
        return match ($plan->status) {
            PreviewStatus::Custom => 'kept (written by hand, use --force)',
            PreviewStatus::Current => 'up to date',
            PreviewStatus::Unsupported => 'no backend preview for this content type',
            // Missing and Placeholder previews are always written.
            default => throw new \LogicException('Unexpected preview status ' . $plan->status->value, 1758700006),
        };
    }
}
