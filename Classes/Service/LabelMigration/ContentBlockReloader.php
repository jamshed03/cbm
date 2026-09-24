<?php

declare(strict_types=1);

namespace AskoEducation\Cbm\Service\LabelMigration;

use TYPO3\CMS\ContentBlocks\Loader\ContentBlockLoader;
use TYPO3\CMS\ContentBlocks\Loader\LoadedContentBlock;
use TYPO3\CMS\ContentBlocks\Registry\ContentBlockRegistry;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Reaches into ContentBlockLoader (@internal) to load Content Blocks without its side effects
 * and to run a modified, not yet written config.yaml through the regular loading pipeline.
 */
final class ContentBlockReloader extends ContentBlockLoader
{
    /**
     * Like loadUncached(), but without publishing assets and processing icons – labels need neither.
     */
    public function loadRegistry(): ContentBlockRegistry
    {
        $loadedContentBlocks = [];
        foreach ($this->packageManager->getActivePackages() as $package) {
            $loadedContentBlocks[] = $this->loadContentBlocks($package);
        }
        $loadedContentBlocks = array_merge([], ...$loadedContentBlocks);
        usort(
            $loadedContentBlocks,
            static fn(LoadedContentBlock $a, LoadedContentBlock $b): int => (int)($b->getYaml()['priority'] ?? 0) <=> (int)($a->getYaml()['priority'] ?? 0),
        );
        return $this->fillContentBlockRegistry($loadedContentBlocks);
    }

    public function reload(LoadedContentBlock $contentBlock, array $rawYaml): LoadedContentBlock
    {
        return $this->loadSingleContentBlock(
            $contentBlock->getName(),
            $contentBlock->getContentType(),
            GeneralUtility::getFileAbsFileName($contentBlock->getExtPath()),
            $contentBlock->getHostExtension(),
            $contentBlock->getExtPath(),
            $rawYaml,
        );
    }
}
