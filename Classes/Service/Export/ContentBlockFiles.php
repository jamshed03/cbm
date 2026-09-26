<?php

declare(strict_types=1);

namespace AskoEducation\Cbm\Service\Export;

use TYPO3\CMS\ContentBlocks\Loader\LoadedContentBlock;
use TYPO3\CMS\ContentBlocks\Utility\ContentBlockPathUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\PathUtility;

/**
 * Exports of a Content Block, e.g. to carry changes made on staging into the repository: the whole folder as ZIP,
 * or config.yaml and the language files (labels.xlf and its translations, e.g. de.labels.xlf) one by one.
 */
final readonly class ContentBlockFiles
{
    private const LANGUAGE_FILE_PATTERN = '/^([a-zA-Z_-]+\.)?labels\.xlf$/';

    /**
     * @return list<string> relative to the Content Block folder: config.yaml, then the language files
     */
    public function getExportableFiles(LoadedContentBlock $contentBlock): array
    {
        $definitionFile = ContentBlockPathUtility::getContentBlockDefinitionFileName();
        $files = is_file($this->getBasePath($contentBlock) . '/' . $definitionFile) ? [$definitionFile] : [];
        return [...$files, ...$this->getLanguageFiles($contentBlock)];
    }

    /**
     * @return list<string> relative to the Content Block folder, labels.xlf first, then its translations
     */
    public function getLanguageFiles(LoadedContentBlock $contentBlock): array
    {
        $languageFolder = ContentBlockPathUtility::getLanguageFolder();
        $files = array_filter(
            array_map('basename', glob($this->getBasePath($contentBlock) . '/' . $languageFolder . '/*.xlf') ?: []),
            static fn(string $file): bool => preg_match(self::LANGUAGE_FILE_PATTERN, $file) === 1,
        );
        usort($files, static fn(string $a, string $b): int => [$a !== 'labels.xlf', $a] <=> [$b !== 'labels.xlf', $b]);
        return array_map(static fn(string $file): string => $languageFolder . '/' . $file, $files);
    }

    /**
     * @param string $file one of getExportableFiles()
     */
    public function getContent(LoadedContentBlock $contentBlock, string $file): string
    {
        if (!in_array($file, $this->getExportableFiles($contentBlock), true)) {
            throw new \InvalidArgumentException(sprintf('"%s" cannot be exported.', $file), 1758700040);
        }
        return (string) file_get_contents($this->getBasePath($contentBlock) . '/' . $file);
    }

    /**
     * The whole Content Block folder (config.yaml, language, templates, assets) as ZIP, inside a folder named like it.
     * Files are collected like the extension manager packs extensions: without excludeForPackaging (e.g. .DS_Store).
     */
    public function createZip(LoadedContentBlock $contentBlock): string
    {
        $basePath = PathUtility::sanitizeTrailingSeparator($this->getBasePath($contentBlock));
        $files = GeneralUtility::getAllFilesAndFoldersInPath(
            [],
            $basePath,
            '',
            false,
            99,
            (string) ($GLOBALS['TYPO3_CONF_VARS']['EXT']['excludeForPackaging'] ?? ''),
        );
        $zipPath = GeneralUtility::tempnam('cbm_export_', '.zip');
        $zip = new \ZipArchive();
        if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('The ZIP file could not be created.', 1758700041);
        }
        foreach (GeneralUtility::removePrefixPathFromList($files, $basePath) as $file) {
            $zip->addFile($basePath . $file, $contentBlock->getPackage() . '/' . $file);
        }
        $zip->close();
        $content = (string) file_get_contents($zipPath);
        GeneralUtility::unlink_tempfile($zipPath);
        return $content;
    }

    public function getZipName(LoadedContentBlock $contentBlock): string
    {
        return $contentBlock->getPackage() . '.zip';
    }

    /**
     * E.g. "hero-config.yaml", "hero-labels.xlf" or "hero-de.labels.xlf": the Content Block name tells where it belongs.
     */
    public function getDownloadName(LoadedContentBlock $contentBlock, string $file): string
    {
        return $contentBlock->getPackage() . '-' . basename($file);
    }

    private function getBasePath(LoadedContentBlock $contentBlock): string
    {
        return GeneralUtility::getFileAbsFileName($contentBlock->getExtPath());
    }
}
