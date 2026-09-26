<?php

declare(strict_types=1);

namespace AskoEducation\Cbm\Controller;

use AskoEducation\Cbm\Service\BackendPreview\BackendPreviewService;
use AskoEducation\Cbm\Service\BackendPreview\PreviewPlan;
use AskoEducation\Cbm\Service\ContentBlockReloader;
use AskoEducation\Cbm\Service\Creation\ContentBlockCreator;
use AskoEducation\Cbm\Service\Creation\FieldDefinitions;
use AskoEducation\Cbm\Service\Creation\NewContentBlock;
use AskoEducation\Cbm\Service\Creation\NewField;
use AskoEducation\Cbm\Service\Editing\ContentBlockEditor;
use AskoEducation\Cbm\Service\Export\ContentBlockFiles;
use AskoEducation\Cbm\Service\LabelEditor\LabelEditorService;
use AskoEducation\Cbm\Service\LabelMigration\LabelLine;
use AskoEducation\Cbm\Service\LabelMigration\LabelMigrationService;
use AskoEducation\Cbm\Service\LabelMigration\MigrationOptions;
use AskoEducation\Cbm\Service\LabelMigration\Prefer;
use AskoEducation\Cbm\Service\LanguageFile\LanguageFileService;
use AskoEducation\Cbm\Service\WriteAccess;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Template\Components\ButtonBar;
use TYPO3\CMS\Backend\Template\Components\ComponentFactory;
use TYPO3\CMS\Backend\Template\ModuleTemplate;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\ContentBlocks\Loader\LoadedContentBlock;
use TYPO3\CMS\ContentBlocks\Registry\ContentBlockRegistry;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Http\RedirectResponse;
use TYPO3\CMS\Core\Http\Response;
use TYPO3\CMS\Core\Imaging\IconFactory;
use TYPO3\CMS\Core\Imaging\IconSize;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Messaging\FlashMessage;
use TYPO3\CMS\Core\Messaging\FlashMessageService;
use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;

/**
 * Backend module: lists all Content Blocks with the state of their labels and backend preview, edits their labels
 * and runs the label migration and preview generation per Content Block – the same plan()/apply() as the cbm:*
 * commands.
 * Files are only written where WriteAccess allows it; exports are always possible.
 */
#[AsController]
final readonly class ContentBlockModuleController
{
    public function __construct(
        private ModuleTemplateFactory $moduleTemplateFactory,
        private UriBuilder $uriBuilder,
        private FlashMessageService $flashMessageService,
        private WriteAccess $writeAccess,
        private ContentBlockFiles $contentBlockFiles,
        private ComponentFactory $componentFactory,
        private IconFactory $iconFactory,
        private ContentBlockReloader $contentBlockReloader,
        private LabelMigrationService $labelMigrationService,
        private LabelEditorService $labelEditorService,
        private LanguageFileService $languageFileService,
        private ContentBlockCreator $contentBlockCreator,
        private ContentBlockEditor $contentBlockEditor,
        private FieldDefinitions $fieldDefinitions,
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
        $keysMissingInXlf = $this->languageFileService->countKeysMissingInXlf($registry);
        $groups = [];
        foreach ($extensions as $extension => $contentBlocks) {
            $previewStatus = [];
            foreach ($this->backendPreviewService->plan(null, (string) $extension) as $plan) {
                $previewStatus[$plan->contentBlock] = $plan->status;
            }
            $groups[] = [
                'extension' => $extension,
                'writable' => $this->writeAccess->isExtensionWritable((string) $extension),
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
                'canWrite' => $this->writeAccess->isContextAllowed(),
            ])
            ->renderResponse('Module/Overview');
    }

    public function labelsAction(ServerRequestInterface $request): ResponseInterface
    {
        $contentBlock = (string) ($request->getQueryParams()['contentBlock'] ?? '');
        $prefer = Prefer::tryFrom((string) ($request->getQueryParams()['prefer'] ?? '')) ?? Prefer::Xlf;
        $plan = $this->labelMigrationService->plan($contentBlock, null, new MigrationOptions(prefer: $prefer))[0];
        $registry = $this->contentBlockReloader->loadRegistry();
        $view = $this->moduleTemplateFactory->create($request);
        if ($registry->hasContentBlock($contentBlock)) {
            $this->addExportButtons($view, $registry->getContentBlock($contentBlock), $this->contentBlockFiles->getLanguageFiles($registry->getContentBlock($contentBlock)));
        }
        return $view
            ->assignMultiple([
                'plan' => $plan,
                'hasChanges' => $plan->hasChanges(),
                'migrationPending' => $plan->isPending(),
                'labels' => $this->labelEditorService->getLabels($contentBlock),
                'removed' => array_map($this->describeLabelLine(...), $plan->removed),
                'skipped' => array_map($this->describeLabelLine(...), $plan->skipped),
                'prefer' => $prefer->value,
                'writable' => $this->isWritableContentBlock($contentBlock, $registry),
            ])
            ->renderResponse('Module/Labels');
    }

    public function labelsApplyAction(ServerRequestInterface $request): ResponseInterface
    {
        $body = (array) $request->getParsedBody();
        $contentBlock = (string) ($body['contentBlock'] ?? '');
        if (!$this->isWritableContentBlock($contentBlock)) {
            return $this->redirectWithMessage($this->translate('message.readOnly', $contentBlock), ContextualFeedbackSeverity::ERROR);
        }
        $prefer = Prefer::tryFrom((string) ($body['prefer'] ?? '')) ?? Prefer::Xlf;
        $plan = $this->labelMigrationService->plan($contentBlock, null, new MigrationOptions(prefer: $prefer))[0];
        $this->labelMigrationService->apply($plan);
        return $plan->hasChanges()
            ? $this->redirectWithMessage($this->translate('message.labelsApplied', $contentBlock), ContextualFeedbackSeverity::OK)
            : $this->redirectWithMessage($this->translate('message.nothingWritten', $contentBlock), ContextualFeedbackSeverity::INFO);
    }

    public function labelsSaveAction(ServerRequestInterface $request): ResponseInterface
    {
        $body = (array) $request->getParsedBody();
        $contentBlock = (string) ($body['contentBlock'] ?? '');
        return $this->editLabels(
            $contentBlock,
            fn(): bool => $this->labelEditorService->update($contentBlock, array_map('strval', (array) ($body['labels'] ?? []))),
            'message.labelsSaved',
        );
    }

    public function labelsDeleteAction(ServerRequestInterface $request): ResponseInterface
    {
        $body = (array) $request->getParsedBody();
        $contentBlock = (string) ($body['contentBlock'] ?? '');
        return $this->editLabels(
            $contentBlock,
            fn(): bool => $this->labelEditorService->delete($contentBlock, (string) ($body['delete'] ?? '')),
            'message.labelDeleted',
        );
    }

    public function createAction(ServerRequestInterface $request): ResponseInterface
    {
        $registry = $this->contentBlockReloader->loadRegistry();
        $extensions = $this->getWritableExtensions();
        return $this->renderForm($request, $this->contentBlockCreator->suggest($registry, $extensions), [], $registry, $extensions);
    }

    public function createSubmitAction(ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->writeAccess->isContextAllowed()) {
            return $this->redirectWithMessage($this->translate('message.createReadOnly', ''), ContextualFeedbackSeverity::ERROR);
        }
        $new = NewContentBlock::fromFormData((array) $request->getParsedBody());
        $registry = $this->contentBlockReloader->loadRegistry();
        $extensions = $this->getWritableExtensions();
        $errors = $this->contentBlockCreator->validate($new, $registry, $extensions);
        if ($errors === []) {
            try {
                $this->contentBlockCreator->create($new);
                // A new request, so that TCA and the database definitions know the new Content Block.
                return new RedirectResponse((string) $this->uriBuilder->buildUriFromRoute('content_cbm.createFinish', ['contentBlock' => $new->getFullName()]));
            } catch (\Throwable $e) {
                $errors = [$e->getMessage()];
            }
        }
        return $this->renderForm($request, $new, $errors, $registry, $extensions);
    }

    public function createFinishAction(ServerRequestInterface $request): ResponseInterface
    {
        $contentBlock = (string) ($request->getQueryParams()['contentBlock'] ?? '');
        if (!$this->isWritableContentBlock($contentBlock)) {
            return $this->redirectWithMessage($this->translate('message.readOnly', $contentBlock), ContextualFeedbackSeverity::ERROR);
        }
        try {
            $this->contentBlockCreator->finish($contentBlock);
        } catch (\Throwable $e) {
            return $this->redirectWithMessage(
                $this->translate('message.createdWithIssue', $contentBlock) . ' ' . $e->getMessage(),
                ContextualFeedbackSeverity::WARNING,
                $contentBlock,
            );
        }
        return $this->redirectWithMessage($this->translate('message.created', $contentBlock), ContextualFeedbackSeverity::OK, $contentBlock);
    }

    public function editAction(ServerRequestInterface $request): ResponseInterface
    {
        $contentBlock = (string) ($request->getQueryParams()['contentBlock'] ?? '');
        if (!$this->isWritableContentBlock($contentBlock)) {
            return $this->redirectWithMessage($this->translate('message.readOnly', $contentBlock), ContextualFeedbackSeverity::ERROR);
        }
        if ($this->contentBlockEditor->isMigrationPending($contentBlock)) {
            return $this->redirectWithMessage($this->translate('message.migrateFirst', $contentBlock), ContextualFeedbackSeverity::WARNING, $contentBlock);
        }
        $registry = $this->contentBlockReloader->loadRegistry();
        return $this->renderForm($request, $this->contentBlockEditor->load($contentBlock), [], $registry, [], $contentBlock);
    }

    public function editSubmitAction(ServerRequestInterface $request): ResponseInterface
    {
        $body = (array) $request->getParsedBody();
        $contentBlock = (string) ($body['contentBlock'] ?? '');
        if (!$this->isWritableContentBlock($contentBlock)) {
            return $this->redirectWithMessage($this->translate('message.readOnly', $contentBlock), ContextualFeedbackSeverity::ERROR);
        }
        // Only what the form may change; name, type and extension stay those of the Content Block.
        $loaded = $this->contentBlockEditor->load($contentBlock);
        $submitted = NewContentBlock::fromFormData($body);
        $edited = new NewContentBlock(
            contentType: $loaded->contentType,
            vendor: $loaded->vendor,
            name: $loaded->name,
            title: $submitted->title,
            description: $submitted->description,
            group: $submitted->group,
            typeName: $loaded->typeName,
            extension: $loaded->extension,
            fields: $submitted->fields,
            confirmed: $submitted->confirmed,
        );
        $registry = $this->contentBlockReloader->loadRegistry();
        $errors = $this->contentBlockEditor->validate($contentBlock, $edited);
        $warnings = $errors === [] ? $this->contentBlockEditor->getWarnings($contentBlock, $edited) : [];
        if ($errors === [] && ($warnings === [] || $edited->confirmed)) {
            try {
                $this->contentBlockEditor->save($contentBlock, $edited);
                // A new request, so that TCA and the database definitions know the changes.
                return new RedirectResponse((string) $this->uriBuilder->buildUriFromRoute('content_cbm.editFinish', ['contentBlock' => $contentBlock]));
            } catch (\Throwable $e) {
                $errors = [$e->getMessage()];
            }
        }
        return $this->renderForm($request, $edited, $errors, $registry, [], $contentBlock, $warnings);
    }

    public function editFinishAction(ServerRequestInterface $request): ResponseInterface
    {
        $contentBlock = (string) ($request->getQueryParams()['contentBlock'] ?? '');
        if (!$this->isWritableContentBlock($contentBlock)) {
            return $this->redirectWithMessage($this->translate('message.readOnly', $contentBlock), ContextualFeedbackSeverity::ERROR);
        }
        try {
            $previewUpToDate = $this->contentBlockEditor->finish($contentBlock);
        } catch (\Throwable $e) {
            return $this->redirectWithMessage(
                $this->translate('message.savedWithIssue', $contentBlock) . ' ' . $e->getMessage(),
                ContextualFeedbackSeverity::WARNING,
                $contentBlock,
            );
        }
        return $previewUpToDate
            ? $this->redirectWithMessage($this->translate('message.saved', $contentBlock), ContextualFeedbackSeverity::OK, $contentBlock)
            : $this->redirectWithMessage($this->translate('message.savedCustomPreview', $contentBlock), ContextualFeedbackSeverity::INFO, $contentBlock);
    }

    /**
     * Downloads a Content Block as ZIP, or with "file" one of its config.yaml and language files, e.g. to carry changes
     * made on staging into the repository. Possible in every context: it only reads.
     */
    public function exportAction(ServerRequestInterface $request): ResponseInterface
    {
        $contentBlockName = (string) ($request->getQueryParams()['contentBlock'] ?? '');
        $file = (string) ($request->getQueryParams()['file'] ?? '');
        $registry = $this->contentBlockReloader->loadRegistry();
        if (!$registry->hasContentBlock($contentBlockName)) {
            return $this->redirectWithMessage(sprintf('Content Block "%s" does not exist.', $contentBlockName), ContextualFeedbackSeverity::ERROR);
        }
        $contentBlock = $registry->getContentBlock($contentBlockName);
        try {
            if ($file === '') {
                return $this->download($this->contentBlockFiles->createZip($contentBlock), $this->contentBlockFiles->getZipName($contentBlock), 'application/zip');
            }
            return $this->download(
                $this->contentBlockFiles->getContent($contentBlock, $file),
                $this->contentBlockFiles->getDownloadName($contentBlock, $file),
                str_ends_with($file, '.xlf') ? 'application/xml; charset=utf-8' : 'application/yaml; charset=utf-8',
            );
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            return $this->redirectWithMessage($e->getMessage(), ContextualFeedbackSeverity::ERROR);
        }
    }

    private function download(string $content, string $fileName, string $contentType): ResponseInterface
    {
        $response = (new Response())
            ->withHeader('Content-Type', $contentType)
            ->withHeader('Content-Disposition', 'attachment; filename="' . $fileName . '"');
        $response->getBody()->write($content);
        return $response;
    }

    public function previewAction(ServerRequestInterface $request): ResponseInterface
    {
        $contentBlock = (string) ($request->getQueryParams()['contentBlock'] ?? '');
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
        $body = (array) $request->getParsedBody();
        $contentBlock = (string) ($body['contentBlock'] ?? '');
        if (!$this->isWritableContentBlock($contentBlock)) {
            return $this->redirectWithMessage($this->translate('message.readOnly', $contentBlock), ContextualFeedbackSeverity::ERROR);
        }
        $plan = $this->backendPreviewService->plan($contentBlock, null, (bool) ($body['force'] ?? false))[0];
        $this->backendPreviewService->apply($plan);
        return $plan->newContent !== null
            ? $this->redirectWithMessage($this->translate('message.previewApplied', $contentBlock), ContextualFeedbackSeverity::OK)
            : $this->redirectWithMessage($this->translate('message.nothingWritten', $contentBlock), ContextualFeedbackSeverity::INFO);
    }

    /**
     * The create form, or with $editing the edit form of that Content Block.
     *
     * @param list<string> $errors
     * @param list<string> $extensions the writable extensions
     * @param list<string> $warnings to be confirmed before an edit is saved
     */
    private function renderForm(
        ServerRequestInterface $request,
        NewContentBlock $new,
        array $errors,
        ContentBlockRegistry $registry,
        array $extensions,
        string $editing = '',
        array $warnings = [],
    ): ResponseInterface {
        $view = $this->moduleTemplateFactory->create($request);
        if ($editing !== '' && $registry->hasContentBlock($editing)) {
            $contentBlock = $registry->getContentBlock($editing);
            $files = array_diff($this->contentBlockFiles->getExportableFiles($contentBlock), $this->contentBlockFiles->getLanguageFiles($contentBlock));
            $this->addExportButtons($view, $contentBlock, array_values($files));
        }
        return $view
            ->assignMultiple([
                'new' => $new,
                'errors' => $errors,
                'warnings' => $warnings,
                'editing' => $editing,
                'contentTypes' => ContentBlockCreator::CONTENT_TYPES,
                'fieldTypes' => $this->fieldDefinitions->getFieldTypes(),
                'fieldOptions' => $this->fieldDefinitions->getFieldOptions(),
                'typesWithItems' => implode(',', NewField::TYPES_WITH_ITEMS),
                'groups' => $this->contentBlockCreator->getGroups($registry),
                'extensions' => $extensions,
                'canWrite' => $this->writeAccess->isContextAllowed(),
            ])
            ->renderResponse('Module/Create');
    }

    /**
     * @return list<string>
     */
    private function getWritableExtensions(): array
    {
        if (!$this->writeAccess->isContextAllowed()) {
            return [];
        }
        return array_values(array_filter($this->contentBlockCreator->getExtensions(), $this->writeAccess->isExtensionWritable(...)));
    }

    /**
     * Export buttons in the doc header, next to the reload button, for $files of $contentBlock.
     *
     * @param list<string> $files see ContentBlockFiles
     */
    private function addExportButtons(ModuleTemplate $view, LoadedContentBlock $contentBlock, array $files): void
    {
        foreach ($files as $file) {
            $button = $this->componentFactory->createLinkButton()
                ->setHref((string) $this->uriBuilder->buildUriFromRoute('content_cbm.export', ['contentBlock' => $contentBlock->getName(), 'file' => $file]))
                ->setTitle($this->translate('action.exportFile', basename($file)))
                ->setShowLabelText(true)
                ->setIcon($this->iconFactory->getIcon('actions-download', IconSize::SMALL));
            $view->getDocHeaderComponent()->getButtonBar()->addButton($button, ButtonBar::BUTTON_POSITION_RIGHT);
        }
    }

    /**
     * Runs a change of labels.xlf and returns to the label page with its outcome.
     *
     * @param \Closure(): bool $change returns whether anything was written
     */
    private function editLabels(string $contentBlock, \Closure $change, string $successKey): ResponseInterface
    {
        if (!$this->isWritableContentBlock($contentBlock)) {
            return $this->redirectWithMessage($this->translate('message.readOnly', $contentBlock), ContextualFeedbackSeverity::ERROR);
        }
        try {
            $changed = $change();
        } catch (\InvalidArgumentException $e) {
            return $this->redirectWithMessage($e->getMessage(), ContextualFeedbackSeverity::ERROR, $contentBlock);
        }
        return $changed
            ? $this->redirectWithMessage($this->translate($successKey, $contentBlock), ContextualFeedbackSeverity::OK, $contentBlock)
            : $this->redirectWithMessage($this->translate('message.nothingWritten', $contentBlock), ContextualFeedbackSeverity::INFO, $contentBlock);
    }

    /**
     * @return array{line: int, key: string, value: string}
     */
    private function describeLabelLine(LabelLine $labelLine): array
    {
        return ['line' => $labelLine->lineNumber(), 'key' => $labelLine->key, 'value' => $labelLine->value];
    }

    private function isWritableContentBlock(string $contentBlock, ?ContentBlockRegistry $registry = null): bool
    {
        $registry ??= $this->contentBlockReloader->loadRegistry();
        return $registry->hasContentBlock($contentBlock)
            && $this->writeAccess->isExtensionWritable($registry->getContentBlock($contentBlock)->getHostExtension());
    }

    private function relativePath(PreviewPlan $plan): string
    {
        return str_replace(Environment::getProjectPath() . '/', '', (string) realpath(dirname($plan->path)) . '/' . basename($plan->path));
    }

    /**
     * @param ?string $labelPageOf return to the label page of this Content Block instead of the overview
     */
    private function redirectWithMessage(string $message, ContextualFeedbackSeverity $severity, ?string $labelPageOf = null): ResponseInterface
    {
        $this->flashMessageService->getMessageQueueByIdentifier()->enqueue(
            new FlashMessage($message, '', $severity, true),
        );
        $uri = $labelPageOf !== null
            ? $this->uriBuilder->buildUriFromRoute('content_cbm.labels', ['contentBlock' => $labelPageOf])
            : $this->uriBuilder->buildUriFromRoute('content_cbm');
        return new RedirectResponse((string) $uri);
    }

    private function translate(string $key, string $contentBlock): string
    {
        return (string) $this->getLanguageService()->translate($key, 'cbm.module', [$contentBlock]);
    }

    private function getLanguageService(): LanguageService
    {
        return $GLOBALS['LANG'];
    }
}
