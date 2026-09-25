<?php

declare(strict_types=1);

namespace AskoEducation\Cbm\Service\LabelEditor;

use AskoEducation\Cbm\Service\ContentBlockReloader;
use AskoEducation\Cbm\Service\LabelMigration\LabelMigrationService;
use AskoEducation\Cbm\Service\LabelMigration\MigrationOptions;
use AskoEducation\Cbm\Service\LanguageFile\LanguageFileService;
use TYPO3\CMS\ContentBlocks\Loader\LoadedContentBlock;
use TYPO3\CMS\ContentBlocks\Registry\AutomaticLanguageKeysRegistry;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Lists, changes and deletes the labels in a Content Block's labels.xlf (source language). New labels come from
 * new fields in config.yaml, via the label migration.
 * The file is always written by Content Blocks' LanguageFileGenerator (via LanguageFileService).
 */
final readonly class LabelEditorService
{
    public function __construct(
        private ContentBlockReloader $contentBlockReloader,
        private LanguageFileService $languageFileService,
        private LabelMigrationService $labelMigrationService,
    ) {
    }

    /**
     * The labels in the order Content Blocks writes them: the field keys, then the custom ones. Descriptions
     * that labels.xlf does not have yet are listed empty, so that they can be filled in.
     *
     * @return list<LabelEntry>
     */
    public function getLabels(string $contentBlockName): array
    {
        [$contentBlock, $automaticLanguageKeys] = $this->load($contentBlockName);
        $labels = $this->languageFileService->readFile($contentBlock);
        $fieldKeys = $this->getFieldKeys($contentBlock, $automaticLanguageKeys);
        $entries = [];
        foreach ($fieldKeys as $key => $optional) {
            if (array_key_exists($key, $labels)) {
                $entries[] = new LabelEntry($key, $labels[$key], true, $optional);
            } elseif ($optional && $this->isDescription($key)) {
                $entries[] = new LabelEntry($key, '', true, true);
            }
        }
        foreach (array_diff_key($labels, $fieldKeys) as $key => $value) {
            $entries[] = new LabelEntry((string)$key, $value, false, false);
        }
        return $entries;
    }

    /**
     * @param array<string, string> $values new text by key: existing labels or missing field keys; an emptied
     *                                      optional label is removed, all others must not be empty
     * @return bool whether anything changed
     */
    public function update(string $contentBlockName, array $values): bool
    {
        $this->assertNoPendingMigration($contentBlockName);
        [$contentBlock, $automaticLanguageKeys] = $this->load($contentBlockName);
        $labels = $this->languageFileService->readFile($contentBlock);
        $fieldKeys = $this->getFieldKeys($contentBlock, $automaticLanguageKeys);
        $newLabels = $labels;
        foreach ($values as $key => $value) {
            $value = trim($value);
            if (!array_key_exists($key, $labels) && !isset($fieldKeys[$key])) {
                throw new \InvalidArgumentException(sprintf('Label "%s" does not exist.', $key), 1758700010);
            }
            if ($value !== '') {
                $newLabels[$key] = $value;
            } elseif ($fieldKeys[$key] ?? false) {
                unset($newLabels[$key]);
            } else {
                throw new \InvalidArgumentException(sprintf('Label "%s" must not be empty.', $key), 1758700011);
            }
        }
        return $this->save($contentBlock, $automaticLanguageKeys, $labels, $newLabels);
    }

    /**
     * @return bool whether anything changed
     */
    public function delete(string $contentBlockName, string $key): bool
    {
        $this->assertNoPendingMigration($contentBlockName);
        [$contentBlock, $automaticLanguageKeys] = $this->load($contentBlockName);
        $labels = $this->languageFileService->readFile($contentBlock);
        if (!array_key_exists($key, $labels)) {
            throw new \InvalidArgumentException(sprintf('Label "%s" does not exist.', $key), 1758700010);
        }
        if (array_key_exists($key, $this->getFieldKeys($contentBlock, $automaticLanguageKeys))) {
            throw new \InvalidArgumentException(sprintf('Label "%s" belongs to a field and cannot be deleted.', $key), 1758700014);
        }
        $newLabels = $labels;
        unset($newLabels[$key]);
        return $this->save($contentBlock, $automaticLanguageKeys, $labels, $newLabels);
    }

    /**
     * @param array<string, string> $labels
     * @param array<string, string> $newLabels
     */
    private function save(LoadedContentBlock $contentBlock, AutomaticLanguageKeysRegistry $automaticLanguageKeys, array $labels, array $newLabels): bool
    {
        if ($newLabels === $labels) {
            return false;
        }
        $xlf = $this->languageFileService->generate($contentBlock, $automaticLanguageKeys, $newLabels);
        $this->languageFileService->write(GeneralUtility::getFileAbsFileName($contentBlock->getExtPath()), $xlf);
        $keysChanged = array_diff_key($newLabels, $labels) !== [] || array_diff_key($labels, $newLabels) !== [];
        $this->languageFileService->flushCaches($keysChanged);
        return true;
    }

    /**
     * Editing labels.xlf while labels are still in config.yaml would hide those labels for good.
     */
    private function assertNoPendingMigration(string $contentBlockName): void
    {
        if ($this->labelMigrationService->plan($contentBlockName, null, new MigrationOptions())[0]->isPending()) {
            throw new \InvalidArgumentException(sprintf('Labels of %s are still in config.yaml: migrate them first.', $contentBlockName), 1758700015);
        }
    }

    /**
     * @return array{LoadedContentBlock, AutomaticLanguageKeysRegistry}
     */
    private function load(string $contentBlockName): array
    {
        $registry = $this->contentBlockReloader->loadRegistry();
        $contentBlock = $this->contentBlockReloader->select($registry, $contentBlockName, null)[0];
        return [$contentBlock, $this->languageFileService->compileFor($registry, $contentBlock)];
    }

    /**
     * The language keys Content Blocks derives from the fields, in the order it writes them, each telling whether
     * it may be empty: Content Blocks only writes a key that has a value, e.g. a description, or the label of an
     * existing field, which otherwise keeps its core label. Item labels are the exception: without a label in
     * labels.xlf the item would have none at all (config.yaml no longer holds it after the migration).
     *
     * @return array<string, bool> optional by key
     */
    private function getFieldKeys(LoadedContentBlock $contentBlock, AutomaticLanguageKeysRegistry $automaticLanguageKeys): array
    {
        $keys = [];
        foreach ($automaticLanguageKeys->getByContentBlock($contentBlock) as $source) {
            $keys[$source->key] = $source->value === '' && !str_contains($source->key, '.items.');
        }
        return $keys;
    }

    /**
     * The description of the element ("description") or of a field ("<field>.description"), shown as help text:
     * the only optional labels listed empty, as placeholders are rarely used.
     */
    private function isDescription(string $key): bool
    {
        return $key === 'description' || str_ends_with($key, '.description');
    }
}
