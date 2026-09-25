<?php

declare(strict_types=1);

namespace AskoEducation\Cbm\Service\Editing;

use AskoEducation\Cbm\Service\BackendPreview\BackendPreviewService;
use AskoEducation\Cbm\Service\BackendPreview\PreviewStatus;
use AskoEducation\Cbm\Service\ContentBlockReloader;
use AskoEducation\Cbm\Service\Creation\FieldDefinitions;
use AskoEducation\Cbm\Service\Creation\FieldOption;
use AskoEducation\Cbm\Service\Creation\FieldOptionSchema;
use AskoEducation\Cbm\Service\Creation\NewContentBlock;
use AskoEducation\Cbm\Service\Creation\NewField;
use AskoEducation\Cbm\Service\Database\TableUpdater;
use AskoEducation\Cbm\Service\LabelMigration\LabelMigrationService;
use AskoEducation\Cbm\Service\LabelMigration\MigrationOptions;
use AskoEducation\Cbm\Service\LanguageFile\LanguageFileService;
use Symfony\Component\Yaml\Yaml;
use TYPO3\CMS\ContentBlocks\Definition\ContentType\ContentType;
use TYPO3\CMS\ContentBlocks\Loader\LoadedContentBlock;
use TYPO3\CMS\ContentBlocks\Utility\ContentBlockPathUtility;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Utility\ArrayUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Edits an existing Content Block with the fields of the create form: labels, descriptions and item labels go into
 * labels.xlf, everything else into config.yaml. Of a field, only the keys the form sets are replaced; all others
 * (e.g. fieldControl) are kept, and fields the form cannot show (e.g. Collections) are kept as they are.
 */
final readonly class ContentBlockEditor
{
    public function __construct(
        private ContentBlockReloader $contentBlockReloader,
        private FieldDefinitions $fieldDefinitions,
        private FieldOptionSchema $fieldOptionSchema,
        private LanguageFileService $languageFileService,
        private LabelMigrationService $labelMigrationService,
        private TableUpdater $tableUpdater,
        private BackendPreviewService $backendPreviewService,
        private CacheManager $cacheManager,
    ) {
    }

    /**
     * Labels still in config.yaml have to be migrated first: the form edits the ones in labels.xlf.
     */
    public function isMigrationPending(string $contentBlockName): bool
    {
        return $this->labelMigrationService->plan($contentBlockName, null, new MigrationOptions())[0]->isPending();
    }

    /**
     * The Content Block as the edit form shows it.
     */
    public function load(string $contentBlockName): NewContentBlock
    {
        $contentBlock = $this->contentBlockReloader->loadRegistry()->getContentBlock($contentBlockName);
        $config = $this->readConfig($contentBlock);
        $labels = $this->languageFileService->readFile($contentBlock);
        $fields = [];
        foreach ($this->getFieldsByKey($config) as $key => $rawField) {
            $identifier = (string) ($rawField['identifier'] ?? '');
            $type = (string) ($rawField['type'] ?? '');
            if (!$this->fieldDefinitions->isEditable($type) || ($rawField['useExistingField'] ?? false)) {
                $fields[] = new NewField(identifier: $identifier, type: $type, original: $key, preserved: true);
                continue;
            }
            $fields[] = new NewField(
                identifier: $identifier,
                type: $type,
                label: $labels[$identifier . '.label'] ?? '',
                description: $labels[$identifier . '.description'] ?? '',
                required: (bool) ($rawField['required'] ?? false),
                items: $this->itemsToText($identifier, (array) ($rawField['items'] ?? []), $labels),
                options: $this->optionsToForm($type, $rawField),
                original: $key,
            );
        }
        return new NewContentBlock(
            contentType: $contentBlock->getContentType(),
            vendor: $contentBlock->getVendor(),
            name: $contentBlock->getPackage(),
            title: $labels['title'] ?? $contentBlock->getName(),
            description: $labels['description'] ?? '',
            group: (string) ($config['group'] ?? 'default'),
            typeName: (string) ($config['typeName'] ?? ''),
            extension: $contentBlock->getHostExtension(),
            fields: $fields,
        );
    }

    /**
     * What happens to the content of renamed, retyped or removed fields; to be confirmed before saving.
     *
     * @return list<string>
     */
    public function getWarnings(string $contentBlockName, NewContentBlock $edited): array
    {
        $fieldsByKey = $this->getFieldsByKey($this->readConfig($this->getContentBlock($contentBlockName)));
        $warnings = [];
        $kept = [];
        foreach ($edited->fields as $field) {
            $old = $fieldsByKey[$field->original] ?? null;
            if ($old === null) {
                continue;
            }
            $kept[] = $field->original;
            if ($field->preserved) {
                continue;
            }
            if ($field->identifier !== $field->original) {
                $warnings[] = sprintf('Field "%s" is renamed to "%s": a new database column is used, the content of "%1$s" stays in its old column and is no longer shown.', $field->original, $field->identifier);
            }
            if ($field->type !== ($old['type'] ?? '')) {
                $warnings[] = sprintf('Field "%s" changes from %s to %s: its database column keeps its type until you update it with the Database Analyzer, and existing content may not fit.', $field->identifier, $old['type'] ?? '?', $field->type);
            }
        }
        foreach (array_diff(array_keys($fieldsByKey), $kept) as $removed) {
            $warnings[] = sprintf('Field "%s" is removed: its database column and content stay until you remove them with the Database Analyzer.', $removed);
        }
        return $warnings;
    }

    /**
     * @return list<string> what is wrong with the edited Content Block; empty if it can be saved
     */
    public function validate(string $contentBlockName, NewContentBlock $edited): array
    {
        $registry = $this->contentBlockReloader->loadRegistry();
        $contentBlock = $registry->getContentBlock($contentBlockName);
        $errors = $edited->title === '' ? ['Enter a title.'] : [];
        $preserved = array_filter($edited->fields, static fn(NewField $field): bool => $field->preserved);
        $editable = array_values(array_filter($edited->fields, static fn(NewField $field): bool => !$field->preserved));
        $errors = [...$errors, ...$this->fieldDefinitions->validateFields($editable, array_column($preserved, 'identifier'))];
        if ($errors !== []) {
            return $errors;
        }
        [$config, $optionErrors] = $this->buildConfig($contentBlock, $edited);
        try {
            $schemaErrors = $this->fieldDefinitions->validateAgainstSchema($this->contentBlockReloader->reload($contentBlock, $config));
        } catch (\Throwable $e) {
            $schemaErrors = [$e->getMessage()];
        }
        // Options that are no valid YAML are left out of $config, so the schema still checks all the others.
        return [...$optionErrors, ...$schemaErrors];
    }

    /**
     * Writes config.yaml and labels.xlf. validate() has to have passed.
     */
    public function save(string $contentBlockName, NewContentBlock $edited): void
    {
        $registry = $this->contentBlockReloader->loadRegistry();
        $contentBlock = $registry->getContentBlock($contentBlockName);
        [$config] = $this->buildConfig($contentBlock, $edited);
        $labels = $this->buildLabels($contentBlock, $edited);

        $path = GeneralUtility::getFileAbsFileName($contentBlock->getExtPath());
        GeneralUtility::writeFile($path . '/' . ContentBlockPathUtility::getContentBlockDefinitionFileName(), Yaml::dump($config, 10, 4));
        $reloaded = $this->contentBlockReloader->reload($contentBlock, $config);
        $xlf = $this->languageFileService->generate($reloaded, $this->languageFileService->compileFor($registry, $reloaded), $labels);
        $this->languageFileService->write($path, $xlf);

        // Like content-blocks:create: system caches for TCA, TypoScript for the frontend.
        $this->cacheManager->flushCachesInGroup('system');
        $this->cacheManager->getCache('typoscript')->flush();
    }

    /**
     * To be run in a request after save(), once TCA and the database definitions know the changes: adds new columns
     * and regenerates the backend preview, unless it was written by hand.
     *
     * @return bool whether the backend preview is up to date
     */
    public function finish(string $contentBlockName): bool
    {
        $this->tableUpdater->addMissing((string) $this->getContentBlock($contentBlockName)->getYaml()['table']);
        $plan = $this->backendPreviewService->plan($contentBlockName, null, false)[0];
        $this->backendPreviewService->apply($plan);
        return $plan->status !== PreviewStatus::Custom;
    }

    /**
     * config.yaml with the edited fields: of each field, the keys the form sets are replaced and the others kept
     * (all dropped if the type changes); labels are left to labels.xlf.
     *
     * @return array{0: array<string, mixed>, 1: list<string>} the configuration and the options that are no valid YAML
     */
    private function buildConfig(LoadedContentBlock $contentBlock, NewContentBlock $edited): array
    {
        $config = $this->readConfig($contentBlock);
        $fieldsByKey = $this->getFieldsByKey($config);
        $fields = [];
        $errors = [];
        foreach ($edited->fields as $field) {
            $old = $fieldsByKey[$field->original] ?? [];
            if ($field->preserved) {
                if ($old !== []) {
                    $fields[] = $old;
                }
                continue;
            }
            [$new, $fieldErrors] = $this->fieldDefinitions->toYaml($contentBlock->getContentType(), $field);
            $errors = [...$errors, ...$fieldErrors];
            unset($new['label'], $new['description']);
            if (isset($new['items'])) {
                $new['items'] = array_map(static fn(array $item): array => ['value' => $item['value']], $new['items']);
            }
            $sameType = ($old['type'] ?? null) === $field->type;
            $kept = $sameType ? array_diff_key($old, array_flip($this->fieldDefinitions->getKeysSetByForm($field->type))) : [];
            // An empty input means "not set" – except for a value that was explicitly empty, e.g. default: ''.
            $explicitlyEmpty = $sameType ? array_filter($old, static fn(mixed $value): bool => $value === '') : [];
            $fields[] = $new + $kept + $explicitlyEmpty;
        }
        $config['fields'] = $fields;
        if ($contentBlock->getContentType() === ContentType::CONTENT_ELEMENT) {
            $config['group'] = $edited->group;
        }
        return [$config, $errors];
    }

    /**
     * labels.xlf with the edited title, description and field labels; labels of removed or renamed fields are dropped.
     *
     * @return array<string, string>
     */
    private function buildLabels(LoadedContentBlock $contentBlock, NewContentBlock $edited): array
    {
        $labels = $this->languageFileService->readFile($contentBlock);
        $labels['title'] = $edited->title;
        $labels = $this->setOrRemove($labels, 'description', $edited->description);
        $keptKeys = array_column($edited->fields, 'original');
        foreach (array_keys($this->getFieldsByKey($this->readConfig($contentBlock))) as $key) {
            if (!in_array($key, $keptKeys, true)) {
                $labels = $this->withoutKeysOf($labels, (string) $key);
            }
        }
        foreach ($edited->fields as $field) {
            if ($field->preserved) {
                continue;
            }
            if ($field->original !== '' && $field->original !== $field->identifier) {
                $labels = $this->withoutKeysOf($labels, $field->original);
            }
            $labels = $this->withoutKeysOf($labels, $field->identifier . '.items');
            $labels = $this->setOrRemove($labels, $field->identifier . '.label', $field->label);
            $labels = $this->setOrRemove($labels, $field->identifier . '.description', $field->description);
            foreach ($field->needsItems() ? $field->parseItems() : [] as $item) {
                $labels[$this->getItemKey($field->identifier, $item['value'])] = $item['label'];
            }
        }
        return $labels;
    }

    /**
     * @param array<string, string> $labels
     * @return array<string, string>
     */
    private function setOrRemove(array $labels, string $key, string $value): array
    {
        if ($value === '') {
            unset($labels[$key]);
        } else {
            $labels[$key] = $value;
        }
        return $labels;
    }

    /**
     * @param array<string, string> $labels
     * @return array<string, string> without the keys of $prefix, e.g. "image" drops "image.label" but not "image_caption.label"
     */
    private function withoutKeysOf(array $labels, string $prefix): array
    {
        return array_filter($labels, static fn(string $key): bool => !str_starts_with($key, $prefix . '.'), ARRAY_FILTER_USE_KEY);
    }

    /**
     * The language key of an item label, as Content Blocks builds it (ContentBlockCompiler::resolveItemPath()).
     */
    private function getItemKey(string $identifier, string $value): string
    {
        return $value === '' ? $identifier . '.items.label' : $identifier . '.items.' . $value . '.label';
    }

    /**
     * @param array<array<string, mixed>> $items
     * @param array<string, string> $labels
     */
    private function itemsToText(string $identifier, array $items, array $labels): string
    {
        $lines = [];
        foreach ($items as $item) {
            $value = (string) ($item['value'] ?? '');
            $label = $labels[$this->getItemKey($identifier, $value)] ?? (string) ($item['label'] ?? $value);
            $lines[] = $label === $value ? $value : $value . ' = ' . $label;
        }
        return implode("\n", $lines);
    }

    /**
     * The options of a field in config.yaml as the form inputs show them.
     *
     * @param array<string, mixed> $field
     * @return array<string, mixed>
     */
    private function optionsToForm(string $fieldType, array $field): array
    {
        $options = [];
        foreach ($this->fieldOptionSchema->getOptions(ContentType::CONTENT_ELEMENT, $fieldType) as $option) {
            if (!ArrayUtility::isValidPath($field, $option->name, '.')) {
                continue;
            }
            $value = ArrayUtility::getValueByPath($field, $option->name, '.');
            $formValue = match (true) {
                is_bool($value) => var_export($value, true),
                $option->kind === FieldOption::KIND_MULTI_ENUM => array_map('strval', (array) $value),
                is_array($value) => Yaml::dump($value, 0),
                default => (string) $value,
            };
            // Lists are entered without the brackets of their inline YAML form, see FieldOptionSchema::convert().
            if ($option->kind === FieldOption::KIND_LIST && is_string($formValue)) {
                $formValue = trim($formValue, '[]');
            }
            $options = ArrayUtility::setValueByPath($options, $option->name, $formValue, '.');
        }
        return $options;
    }

    /**
     * The fields of config.yaml by identifier; fields without one (e.g. a Linebreak) by "#position".
     *
     * @param array<string, mixed> $config
     * @return array<string, array<string, mixed>>
     */
    private function getFieldsByKey(array $config): array
    {
        $fields = [];
        foreach (array_values((array) ($config['fields'] ?? [])) as $position => $field) {
            $fields[(string) ($field['identifier'] ?? '#' . $position)] = (array) $field;
        }
        return $fields;
    }

    /**
     * config.yaml as written, i.e. without what the loader adds (table, typeName, Basics, ...).
     *
     * @return array<string, mixed>
     */
    private function readConfig(LoadedContentBlock $contentBlock): array
    {
        return (array) Yaml::parseFile(
            GeneralUtility::getFileAbsFileName($contentBlock->getExtPath()) . '/' . ContentBlockPathUtility::getContentBlockDefinitionFileName()
        );
    }

    private function getContentBlock(string $contentBlockName): LoadedContentBlock
    {
        return $this->contentBlockReloader->loadRegistry()->getContentBlock($contentBlockName);
    }
}
