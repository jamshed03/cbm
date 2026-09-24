<?php

declare(strict_types=1);

namespace AskoEducation\Cbm\Service\BackendPreview;

use AskoEducation\Cbm\Service\ContentBlockReloader;
use TYPO3\CMS\ContentBlocks\Definition\ContentType\ContentType;
use TYPO3\CMS\ContentBlocks\Definition\Factory\TableDefinitionCollectionFactory;
use TYPO3\CMS\ContentBlocks\Definition\TableDefinitionCollection;
use TYPO3\CMS\ContentBlocks\FieldType\FieldTypeRegistry;
use TYPO3\CMS\ContentBlocks\Loader\LoadedContentBlock;
use TYPO3\CMS\ContentBlocks\Schema\SimpleTcaSchemaFactory;
use TYPO3\CMS\ContentBlocks\Utility\ContentBlockPathUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Generates backend previews with PreviewTemplateGenerator (Content Blocks' HtmlTemplateCodeGenerator with
 * one partial per field), for many Content Blocks at once, without touching previews written by hand.
 */
final readonly class BackendPreviewService
{
    public function __construct(
        private ContentBlockReloader $contentBlockReloader,
        private TableDefinitionCollectionFactory $tableDefinitionCollectionFactory,
        private FieldTypeRegistry $fieldTypeRegistry,
        private SimpleTcaSchemaFactory $simpleTcaSchemaFactory,
        private PreviewTemplateGenerator $previewTemplateGenerator,
    ) {}

    /**
     * @param bool $force also overwrite previews written by hand
     * @return list<PreviewPlan>
     */
    public function plan(?string $contentBlockName, ?string $extension, bool $force = false): array
    {
        $registry = $this->contentBlockReloader->loadRegistry();
        $contentBlocks = $this->contentBlockReloader->select($registry, $contentBlockName, $extension);
        $tableDefinitionCollection = $this->tableDefinitionCollectionFactory->createUncached(
            $registry,
            $this->fieldTypeRegistry,
            $this->simpleTcaSchemaFactory,
        );
        return array_map(
            fn(LoadedContentBlock $contentBlock): PreviewPlan => $this->planContentBlock($contentBlock, $tableDefinitionCollection, $force),
            $contentBlocks,
        );
    }

    public function apply(PreviewPlan $plan): void
    {
        if ($plan->newContent !== null) {
            GeneralUtility::mkdir_deep(dirname($plan->path));
            GeneralUtility::writeFile($plan->path, $plan->newContent);
        }
    }

    private function planContentBlock(LoadedContentBlock $contentBlock, TableDefinitionCollection $tableDefinitionCollection, bool $force): PreviewPlan
    {
        $path = $this->findPreviewPath($contentBlock);
        // Only these have a backend preview (page module), see HtmlTemplateCodeGenerator::generateEditorPreviewTemplate().
        if (!in_array($contentBlock->getContentType(), [ContentType::CONTENT_ELEMENT, ContentType::PAGE_TYPE], true)) {
            return new PreviewPlan($contentBlock->getName(), PreviewStatus::Unsupported, $path, null);
        }
        $generated = $this->previewTemplateGenerator->generateEditorPreviewTemplate($contentBlock, $tableDefinitionCollection);
        $current = is_file($path) ? (string)file_get_contents($path) : null;
        $status = match (true) {
            $current === null => PreviewStatus::Missing,
            trim($current) === trim($generated) => PreviewStatus::Current,
            $this->isPlaceholder($current, $contentBlock) => PreviewStatus::Placeholder,
            default => PreviewStatus::Custom,
        };
        $write = $status === PreviewStatus::Missing
            || $status === PreviewStatus::Placeholder
            || ($status === PreviewStatus::Custom && $force);
        return new PreviewPlan($contentBlock->getName(), $status, $path, $write ? $generated : null);
    }

    /**
     * The template PreviewRenderer would use: backend-preview.fluid.html, else the older backend-preview.html.
     * A new template gets the .fluid.html name, like content-blocks:generate:backend-preview does.
     */
    private function findPreviewPath(LoadedContentBlock $contentBlock): string
    {
        $basePath = GeneralUtility::getFileAbsFileName($contentBlock->getExtPath());
        $dotFluid = $basePath . '/' . ContentBlockPathUtility::getBackendPreviewPathDotFluid();
        $legacy = $basePath . '/' . ContentBlockPathUtility::getBackendPreviewPath();
        return !is_file($dotFluid) && is_file($legacy) ? $legacy : $dotFluid;
    }

    /**
     * The empty preview older Content Blocks versions created: "Preview for Content Block: vendor/name".
     */
    private function isPlaceholder(string $template, LoadedContentBlock $contentBlock): bool
    {
        return preg_match('#<f:section name="Content">(.*?)</f:section>#s', $template, $match) === 1
            && trim($match[1]) === 'Preview for Content Block: ' . $contentBlock->getName();
    }
}
