<?php

declare(strict_types=1);

namespace AskoEducation\Cbm\Service;

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
     * Picks either the Content Block named $contentBlockName or all Content Blocks of $extension.
     *
     * @return list<LoadedContentBlock>
     */
    public function select(ContentBlockRegistry $registry, ?string $contentBlockName, ?string $extension): array
    {
        if (($contentBlockName === null) === ($extension === null)) {
            throw new \InvalidArgumentException('Select either a Content Block name or an extension.', 1758700005);
        }
        if ($contentBlockName !== null) {
            return [$this->find($registry, $contentBlockName)];
        }
        $contentBlocks = array_values(array_filter(
            $registry->getAll(),
            static fn(LoadedContentBlock $contentBlock): bool => $contentBlock->getHostExtension() === $extension,
        ));
        if ($contentBlocks === []) {
            throw new \InvalidArgumentException('Extension "' . $extension . '" contains no Content Blocks.', 1758700004);
        }
        return $contentBlocks;
    }

    /**
     * @param string $name "vendor/name", or just the name (case-insensitive), e.g. "Hero"
     */
    public function find(ContentBlockRegistry $registry, string $name): LoadedContentBlock
    {
        if ($registry->hasContentBlock($name)) {
            return $registry->getContentBlock($name);
        }
        $matches = array_values(array_filter(
            $registry->getAll(),
            static fn(LoadedContentBlock $contentBlock): bool => strcasecmp($contentBlock->getPackage(), $name) === 0,
        ));
        return match (count($matches)) {
            1 => $matches[0],
            0 => throw new \InvalidArgumentException('Content Block "' . $name . '" does not exist.', 1758700003),
            default => throw new \InvalidArgumentException(sprintf(
                '"%s" is ambiguous, use one of: %s',
                $name,
                implode(', ', array_map(static fn(LoadedContentBlock $contentBlock): string => $contentBlock->getName(), $matches)),
            ), 1758700051),
        };
    }

    /**
     * A copy of ContentBlockLoader::loadUncached() without publishing assets and processing icons,
     * which cbm does not need. Keep it in sync with loadUncached() when updating Content Blocks.
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
