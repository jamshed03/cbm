<?php

declare(strict_types=1);

namespace AskoEducation\Cbm\Service\Creation;

use AskoEducation\Cbm\Service\BackendPreview\BackendPreviewService;
use AskoEducation\Cbm\Service\ContentBlockReloader;
use AskoEducation\Cbm\Service\LabelMigration\ConfigYamlLabelStripper;
use Symfony\Component\Yaml\Yaml;
use TYPO3\CMS\ContentBlocks\Builder\ConfigBuilder;
use TYPO3\CMS\ContentBlocks\Builder\ContentBlockBuilder;
use TYPO3\CMS\ContentBlocks\Definition\ContentType\ContentType;
use TYPO3\CMS\ContentBlocks\Definition\ContentType\ContentTypeIcon;
use TYPO3\CMS\ContentBlocks\FieldType\FieldTypeRegistry;
use TYPO3\CMS\ContentBlocks\JsonSchemaValidation\ContentBlockValidator;
use TYPO3\CMS\ContentBlocks\JsonSchemaValidation\JsonSchemaErrorFormatter;
use TYPO3\CMS\ContentBlocks\Loader\LoadedContentBlock;
use TYPO3\CMS\ContentBlocks\Registry\ContentBlockRegistry;
use TYPO3\CMS\ContentBlocks\Service\PackageResolver;
use TYPO3\CMS\ContentBlocks\Utility\ContentBlockPathUtility;
use TYPO3\CMS\ContentBlocks\Validation\ContentBlockNameValidator;
use TYPO3\CMS\ContentBlocks\Validation\PageTypeNameValidator;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Database\Schema\SchemaMigrator;
use TYPO3\CMS\Core\Database\Schema\SqlReader;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Creates a Content Block like content-blocks:create does – ConfigBuilder for config.yaml, ContentBlockBuilder
 * for the files – plus the fields entered in the backend form, with their labels only in labels.xlf. In a second
 * request, once TCA knows the new Content Block, its table is updated and the backend preview generated.
 */
final readonly class ContentBlockCreator
{
    public const CONTENT_TYPES = [ContentType::CONTENT_ELEMENT, ContentType::RECORD_TYPE, ContentType::PAGE_TYPE];

    /**
     * Field types the form offers: those that need no further options beyond label, description, required and items.
     */
    private const FIELD_TYPES = [
        'Text',
        'Textarea',
        'Number',
        'Email',
        'Link',
        'DateTime',
        'Color',
        'Checkbox',
        'Select',
        'Radio',
        'File',
        'Category',
    ];

    private const IDENTIFIER_PATTERN = '/^[a-z][a-z0-9_]*$/';

    public function __construct(
        private ConfigBuilder $configBuilder,
        private ContentBlockBuilder $contentBlockBuilder,
        private ContentBlockReloader $contentBlockReloader,
        private FieldTypeRegistry $fieldTypeRegistry,
        private PackageResolver $packageResolver,
        private CacheManager $cacheManager,
        private SqlReader $sqlReader,
        private SchemaMigrator $schemaMigrator,
        private ConfigYamlLabelStripper $configYamlLabelStripper,
        private BackendPreviewService $backendPreviewService,
        private FieldOptionSchema $fieldOptionSchema,
        private ContentBlockValidator $contentBlockValidator,
        private JsonSchemaErrorFormatter $jsonSchemaErrorFormatter,
    ) {
    }

    /**
     * Extensions a Content Block can be created in: local packages, as content-blocks:create offers them.
     *
     * @return list<string>
     */
    public function getExtensions(): array
    {
        return array_map('strval', array_keys($this->packageResolver->getAvailablePackagesForDisplay()));
    }

    /**
     * @return list<string>
     */
    public function getFieldTypes(): array
    {
        return array_values(array_filter(self::FIELD_TYPES, $this->fieldTypeRegistry->has(...)));
    }

    /**
     * The options of each offered field type. They are the same for all content types, so they come from the
     * content element schema; validation uses the schema of the chosen content type.
     *
     * @return array<string, list<FieldOption>> by field type
     */
    public function getFieldOptions(): array
    {
        $options = [];
        foreach ($this->getFieldTypes() as $fieldType) {
            $options[$fieldType] = $this->fieldOptionSchema->getOptions(ContentType::CONTENT_ELEMENT, $fieldType);
        }
        return $options;
    }

    /**
     * A form prefilled with the vendor and extension most existing Content Blocks in $extensions use, falling back
     * like content-blocks:create to the project's composer vendor, and the current time as doktype.
     *
     * @param list<string> $extensions
     */
    public function suggest(ContentBlockRegistry $registry, array $extensions): NewContentBlock
    {
        $vendors = [];
        $hosts = [];
        foreach ($registry->getAll() as $contentBlock) {
            if (in_array($contentBlock->getHostExtension(), $extensions, true)) {
                $vendors[] = $contentBlock->getVendor();
                $hosts[] = $contentBlock->getHostExtension();
            }
        }
        return new NewContentBlock(
            vendor: $this->mostCommon($vendors) ?? $this->packageResolver->getComposerProjectVendor(),
            typeName: (string) time(),
            extension: $this->mostCommon($hosts) ?? ($extensions[0] ?? ''),
        );
    }

    /**
     * The groups of the new content element wizard: those of core (TCA) and those existing Content Blocks use.
     *
     * @return list<string>
     */
    public function getGroups(ContentBlockRegistry $registry): array
    {
        $groups = array_keys($GLOBALS['TCA']['tt_content']['columns']['CType']['config']['itemGroups'] ?? []);
        foreach ($registry->getAll() as $contentBlock) {
            if ($contentBlock->getContentType() === ContentType::CONTENT_ELEMENT) {
                $groups[] = $contentBlock->getYaml()['group'] ?? 'default';
            }
        }
        $groups = array_values(array_unique(array_map('strval', $groups)));
        sort($groups);
        return $groups;
    }

    /**
     * @param list<string> $extensions the extensions it may be created in
     * @return list<string> what is wrong with the input; empty if it can be created
     */
    public function validate(NewContentBlock $new, ContentBlockRegistry $registry, array $extensions): array
    {
        $errors = [];
        if (!in_array($new->contentType, self::CONTENT_TYPES, true)) {
            $errors[] = 'This content type cannot be created here.';
        }
        if (!ContentBlockNameValidator::isValid($new->vendor) || !ContentBlockNameValidator::isValid($new->name)) {
            $errors[] = 'Vendor and name must be lowercase words separated by "-", e.g. "my-vendor/teaser-box".';
        } elseif ($registry->hasContentBlock($new->getFullName())) {
            $errors[] = sprintf('A Content Block "%s" already exists.', $new->getFullName());
        }
        if (!in_array($new->extension, $extensions, true)) {
            $errors[] = 'Choose an extension to store the Content Block in.';
        }
        if ($new->contentType === ContentType::PAGE_TYPE) {
            try {
                PageTypeNameValidator::validate($new->typeName, $new->getFullName());
                if ((int) $new->typeName === 0) {
                    $errors[] = 'Page type number: enter a positive number, e.g. the current timestamp.';
                }
            } catch (\InvalidArgumentException $e) {
                $errors[] = 'Page type number: ' . $e->getMessage();
            }
        }
        // The fields ConfigBuilder starts with do not depend on vendor and name, which may be invalid here.
        $identifiers = array_column($this->buildConfig($new, 'vendor', 'name')['fields'] ?? [], 'identifier');
        $fieldTypes = $this->getFieldTypes();
        foreach ($new->fields as $position => $field) {
            $row = sprintf('Field %d', $position + 1);
            if (!preg_match(self::IDENTIFIER_PATTERN, $field->identifier)) {
                $errors[] = $row . ': the identifier must start with a letter and contain only a-z, 0-9 and "_".';
            } elseif (in_array($field->identifier, $identifiers, true)) {
                $errors[] = sprintf('%s: the identifier "%s" is used twice.', $row, $field->identifier);
            }
            $identifiers[] = $field->identifier;
            if (!in_array($field->type, $fieldTypes, true)) {
                $errors[] = $row . ': choose a field type.';
            }
            if ($field->needsItems() && $field->parseItems() === []) {
                $errors[] = $row . ': add at least one item ("value = Label" per line).';
            }
        }
        if ($errors !== []) {
            return $errors;
        }
        [$yaml, $optionErrors] = $this->buildYaml($new);
        // Options that are no valid YAML are left out of $yaml, so the schema still checks all the others.
        return [...$optionErrors, ...$this->validateAgainstSchema($new, $yaml)];
    }

    /**
     * Writes the new Content Block (config.yaml, labels.xlf, templates, icon). ContentBlockBuilder already writes all
     * labels into labels.xlf; they are only removed from config.yaml, as the label migration would do.
     */
    public function create(NewContentBlock $new): void
    {
        // Built again rather than handed over from validate(), which has to have passed before.
        $contentBlock = $this->createLoadedContentBlock($new, $this->buildYaml($new)[0]);
        $this->contentBlockBuilder->create($contentBlock);

        $configPath = GeneralUtility::getFileAbsFileName($contentBlock->getExtPath()) . '/' . $contentBlock->getPackage()
            . '/' . ContentBlockPathUtility::getContentBlockDefinitionFileName();
        $config = $this->configYamlLabelStripper->withoutLabels(Yaml::parseFile($configPath));
        // Same dump settings as ContentBlockBuilder::createConfigYaml().
        GeneralUtility::writeFile($configPath, Yaml::dump($config, 10, 2));

        // Like content-blocks:create: system caches for TCA, TypoScript for the new CType in the frontend.
        $this->cacheManager->flushCachesInGroup('system');
        $this->cacheManager->getCache('typoscript')->flush();
    }

    /**
     * To be run in a request after create(), once TCA and the database definitions know the new Content Block:
     * adds its table or columns and generates the backend preview.
     */
    public function finish(string $contentBlockName): void
    {
        $contentBlock = $this->contentBlockReloader->loadRegistry()->getContentBlock($contentBlockName);
        $this->updateTable((string) $contentBlock->getYaml()['table']);
        foreach ($this->backendPreviewService->plan($contentBlockName, null, true) as $plan) {
            $this->backendPreviewService->apply($plan);
        }
    }

    /**
     * Creates the table or adds the missing columns, like extension:setup does for the whole database – limited to
     * $table and to additions, so that no pending change of another extension is applied on the way.
     */
    private function updateTable(string $table): void
    {
        $statements = $this->sqlReader->getCreateTableStatementArray($this->sqlReader->getTablesDefinitionString());
        $suggestions = array_merge_recursive(...array_values($this->schemaMigrator->getUpdateSuggestions($statements)));
        $selected = [];
        foreach (['create_table', 'add'] as $action) {
            foreach ($suggestions[$action] ?? [] as $hash => $statement) {
                if (preg_match('/^(CREATE|ALTER) TABLE `?' . preg_quote($table, '/') . '`?\s/i', (string) $statement)) {
                    $selected[$hash] = true;
                }
            }
        }
        $errors = $this->schemaMigrator->migrate($statements, $selected);
        if ($errors !== []) {
            throw new \RuntimeException(implode(' ', $errors), 1758700020);
        }
    }

    /**
     * config.yaml of the new Content Block, before ContentBlockBuilder moves title and description into labels.xlf.
     *
     * @return array{0: array<string, mixed>, 1: list<string>} the configuration and options that could not be converted
     */
    private function buildYaml(NewContentBlock $new): array
    {
        $yaml = $this->buildConfig($new, $new->vendor, $new->name);
        if ($new->contentType === ContentType::CONTENT_ELEMENT) {
            $yaml['group'] = $new->group;
            if ($new->description !== '') {
                $yaml['description'] = $new->description;
            }
        }
        $errors = [];
        foreach ($new->fields as $field) {
            [$options, $optionErrors] = $this->fieldOptionSchema->convert($new->contentType, $field->type, $field->options);
            foreach ($optionErrors as $optionError) {
                $errors[] = sprintf('Field "%s": %s', $field->identifier, $optionError);
            }
            $yaml['fields'][] = $field->toYaml($options);
        }
        return [$yaml, $errors];
    }

    /**
     * Validates the new configuration like content-blocks:lint does.
     *
     * @param array<string, mixed> $yaml
     * @return list<string>
     */
    private function validateAgainstSchema(NewContentBlock $new, array $yaml): array
    {
        $result = $this->contentBlockValidator->validateContentBlock($this->createLoadedContentBlock($new, $yaml));
        $errorsByPath = $this->jsonSchemaErrorFormatter->format($result);
        $errors = [];
        foreach ($errorsByPath as $path => $messages) {
            // Like content-blocks:lint: an error on a field is a false positive if one of its options has an error
            // (https://github.com/opis/json-schema/issues/148).
            foreach (array_keys($errorsByPath) as $otherPath) {
                if (str_starts_with((string) $otherPath, $path . '/')) {
                    continue 2;
                }
            }
            // "/fields/3/cols" is shown as 'Field "kicker": cols', resolved like content-blocks:lint does.
            $where = trim((string) $path, '/');
            $segments = explode('/', $where);
            if ($segments[0] === 'fields' && isset($segments[1], $yaml['fields'][(int) $segments[1]])) {
                $option = implode('.', array_slice($segments, 2));
                $where = sprintf('Field "%s"', $yaml['fields'][(int) $segments[1]]['identifier'] ?? $segments[1]) . ($option !== '' ? ': ' . $option : '');
            }
            foreach ((array) $messages as $message) {
                $errors[] = $where . ': ' . $message;
            }
        }
        return $errors;
    }

    /**
     * @param array<string, mixed> $yaml
     */
    private function createLoadedContentBlock(NewContentBlock $new, array $yaml): LoadedContentBlock
    {
        return new LoadedContentBlock(
            name: $new->getFullName(),
            yaml: $yaml,
            icon: new ContentTypeIcon(),
            hostExtension: $new->extension,
            extPath: $this->getExtPath($new),
            contentType: $new->contentType,
        );
    }

    /**
     * config.yaml as ConfigBuilder starts it: with the header field of content elements, the title of records.
     *
     * @return array<string, mixed>
     */
    private function buildConfig(NewContentBlock $new, string $vendor, string $name): array
    {
        $typeName = $new->contentType === ContentType::PAGE_TYPE ? (int) $new->typeName : null;
        return $this->configBuilder->build($new->contentType, $vendor, $name, $new->title ?: null, $typeName, []);
    }

    /**
     * @param list<string> $values
     */
    private function mostCommon(array $values): ?string
    {
        if ($values === []) {
            return null;
        }
        $counts = array_count_values($values);
        arsort($counts);
        return (string) array_key_first($counts);
    }

    /**
     * Where ContentBlockBuilder creates the folder, like CreateContentBlockCommand::getExtPath().
     */
    private function getExtPath(NewContentBlock $new): string
    {
        $base = 'EXT:' . $new->extension . '/';
        return match ($new->contentType) {
            ContentType::PAGE_TYPE => $base . ContentBlockPathUtility::getRelativePageTypesPath(),
            ContentType::RECORD_TYPE => $base . ContentBlockPathUtility::getRelativeRecordTypesPath(),
            default => $base . ContentBlockPathUtility::getRelativeContentElementsPath(),
        };
    }
}
