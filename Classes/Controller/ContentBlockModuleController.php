<?php

declare(strict_types=1);

namespace AskoEducation\Cbm\Controller;

use AskoEducation\Cbm\Service\BackendPreview\BackendPreviewService;
use AskoEducation\Cbm\Service\BackendPreview\PreviewPlan;
use AskoEducation\Cbm\Service\ContentBlockReloader;
use AskoEducation\Cbm\Service\LabelMigration\LabelLine;
use AskoEducation\Cbm\Service\LabelMigration\LabelMigrationService;
use AskoEducation\Cbm\Service\LabelMigration\MigrationOptions;
use AskoEducation\Cbm\Service\LabelMigration\Prefer;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\ContentBlocks\Loader\LoadedContentBlock;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Http\RedirectResponse;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Messaging\FlashMessage;
use TYPO3\CMS\Core\Messaging\FlashMessageService;
use TYPO3\CMS\Core\Package\PackageManager;
use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;

/**
 * Backend module: lists all Content Blocks with the state of their labels and backend preview, and runs the
 * label migration and preview generation per Content Block – the same plan()/apply() as the cbm:* commands.
 * Files are only written in the Development context and never in packages installed into vendor/.
 */
#[AsController]
final readonly class ContentBlockModuleController
{
    private const LANGUAGE_FILE = 'LLL:EXT:cbm/Resources/Private/Language/module.xlf:';

    public function __construct(
        private ModuleTemplateFactory $moduleTemplateFactory,
        private UriBuilder $uriBuilder,
        private FlashMessageService $flashMessageService,
        private PackageManager $packageManager,
        private ContentBlockReloader $contentBlockReloader,
        private LabelMigrationService $labelMigrationService,
        private BackendPreviewService $backendPreviewService,
    ) {
    }

    public function overviewAction(ServerRequestInterface $request): ResponseInterface
    {
        $registry = $this->contentBlockReloader->loadRegistry();
        $extensions = [];
        foreach ($registry->getAll() as $contentBlock) {
            $extensions[$contentBlock->getHostExtension()][] = $contentBlock;
        }
        ksort($extensions);
        $keysMissingInXlf = $this->labelMigrationService->countKeysMissingInXlf($registry);
        $groups = [];
        foreach ($extensions as $extension => $contentBlocks) {
            $previewStatus = [];
            foreach ($this->backendPreviewService->plan(null, (string)$extension) as $plan) {
                $previewStatus[$plan->contentBlock] = $plan->status;
            }
            $groups[] = [
                'extension' => $extension,
                'writable' => $this->isWritable((string)$extension),
                'contentBlocks' => array_map(
                    fn(LoadedContentBlock $contentBlock): array => [
                        'name' => $contentBlock->getName(),
                        'type' => $contentBlock->getContentType()->value,
                        'keysMissingInXlf' => $keysMissingInXlf[$contentBlock->getName()],
                        'previewStatus' => $previewStatus[$contentBlock->getName()]->value,
                    ],
                    $contentBlocks,
                ),
            ];
        }
        return $this->moduleTemplateFactory->create($request)
            ->assignMultiple([
                'groups' => $groups,
                'isDevelopment' => Environment::getContext()->isDevelopment(),
            ])
            ->renderResponse('Module/Overview');
    }

    public function labelsAction(ServerRequestInterface $request): ResponseInterface
    {
        $contentBlock = (string)($request->getQueryParams()['contentBlock'] ?? '');
        $prefer = Prefer::tryFrom((string)($request->getQueryParams()['prefer'] ?? '')) ?? Prefer::Xlf;
        $plan = $this->labelMigrationService->plan($contentBlock, null, new MigrationOptions(prefer: $prefer))[0];
        return $this->moduleTemplateFactory->create($request)
            ->assignMultiple([
                'plan' => $plan,
                'hasChanges' => $plan->hasChanges(),
                'removed' => array_map($this->describeLabelLine(...), $plan->removed),
                'skipped' => array_map($this->describeLabelLine(...), $plan->skipped),
                'prefer' => $prefer->value,
                'writable' => $this->isWritableContentBlock($contentBlock),
            ])
            ->renderResponse('Module/Labels');
    }

    public function labelsApplyAction(ServerRequestInterface $request): ResponseInterface
    {
        $body = (array)$request->getParsedBody();
        $contentBlock = (string)($body['contentBlock'] ?? '');
        if (!$this->isWritableContentBlock($contentBlock)) {
            return $this->redirectWithMessage('message.readOnly', $contentBlock, ContextualFeedbackSeverity::ERROR);
        }
        $prefer = Prefer::tryFrom((string)($body['prefer'] ?? '')) ?? Prefer::Xlf;
        $plan = $this->labelMigrationService->plan($contentBlock, null, new MigrationOptions(prefer: $prefer))[0];
        $this->labelMigrationService->apply($plan);
        return $plan->hasChanges()
            ? $this->redirectWithMessage('message.labelsApplied', $contentBlock, ContextualFeedbackSeverity::OK)
            : $this->redirectWithMessage('message.nothingWritten', $contentBlock, ContextualFeedbackSeverity::INFO);
    }

    public function previewAction(ServerRequestInterface $request): ResponseInterface
    {
        $contentBlock = (string)($request->getQueryParams()['contentBlock'] ?? '');
        $plan = $this->backendPreviewService->plan($contentBlock, null, true)[0];
        return $this->moduleTemplateFactory->create($request)
            ->assignMultiple([
                'plan' => $plan,
                'path' => $this->relativePath($plan),
                'writable' => $this->isWritableContentBlock($contentBlock),
            ])
            ->renderResponse('Module/Preview');
    }

    public function previewApplyAction(ServerRequestInterface $request): ResponseInterface
    {
        $body = (array)$request->getParsedBody();
        $contentBlock = (string)($body['contentBlock'] ?? '');
        if (!$this->isWritableContentBlock($contentBlock)) {
            return $this->redirectWithMessage('message.readOnly', $contentBlock, ContextualFeedbackSeverity::ERROR);
        }
        $plan = $this->backendPreviewService->plan($contentBlock, null, (bool)($body['force'] ?? false))[0];
        $this->backendPreviewService->apply($plan);
        return $plan->newContent !== null
            ? $this->redirectWithMessage('message.previewApplied', $contentBlock, ContextualFeedbackSeverity::OK)
            : $this->redirectWithMessage('message.nothingWritten', $contentBlock, ContextualFeedbackSeverity::INFO);
    }

    /**
     * @return array{line: int, key: string, value: string}
     */
    private function describeLabelLine(LabelLine $labelLine): array
    {
        return ['line' => $labelLine->lineNumber(), 'key' => $labelLine->key, 'value' => $labelLine->value];
    }

    private function isWritableContentBlock(string $contentBlock): bool
    {
        $registry = $this->contentBlockReloader->loadRegistry();
        return $registry->hasContentBlock($contentBlock)
            && $this->isWritable($registry->getContentBlock($contentBlock)->getHostExtension());
    }

    /**
     * Files are only written in the Development context, and not into packages installed into vendor/,
     * which the next composer install would overwrite.
     */
    private function isWritable(string $extension): bool
    {
        $packagePath = (string)realpath($this->packageManager->getPackage($extension)->getPackagePath());
        return Environment::getContext()->isDevelopment()
            && !str_starts_with($packagePath, Environment::getProjectPath() . '/vendor/');
    }

    private function relativePath(PreviewPlan $plan): string
    {
        return str_replace(Environment::getProjectPath() . '/', '', (string)realpath(dirname($plan->path)) . '/' . basename($plan->path));
    }

    private function redirectWithMessage(string $key, string $contentBlock, ContextualFeedbackSeverity $severity): ResponseInterface
    {
        $message = sprintf($this->getLanguageService()->sL(self::LANGUAGE_FILE . $key), $contentBlock);
        $this->flashMessageService->getMessageQueueByIdentifier()->enqueue(
            new FlashMessage($message, '', $severity, true),
        );
        return new RedirectResponse((string)$this->uriBuilder->buildUriFromRoute('content_cbm'));
    }

    private function getLanguageService(): LanguageService
    {
        return $GLOBALS['LANG'];
    }
}
