<?php

declare(strict_types=1);

namespace AskoEducation\Cbm\Service\Creation;

use AskoEducation\Cbm\Service\BackendPreview\BackendPreviewService;
use AskoEducation\Cbm\Service\ContentBlockReloader;
use AskoEducation\Cbm\Service\Database\TableUpdater;
use AskoEducation\Cbm\Service\LabelMigration\ConfigYamlLabelStripper;
use Symfony\Component\Yaml\Yaml;
use TYPO3\CMS\ContentBlocks\Builder\ConfigBuilder;
use TYPO3\CMS\ContentBlocks\Builder\ContentBlockBuilder;
use TYPO3\CMS\ContentBlocks\Definition\ContentType\ContentType;
use TYPO3\CMS\ContentBlocks\Definition\ContentType\ContentTypeIcon;
use TYPO3\CMS\ContentBlocks\Loader\LoadedContentBlock;
use TYPO3\CMS\ContentBlocks\Registry\ContentBlockRegistry;
use TYPO3\CMS\ContentBlocks\Service\PackageResolver;
use TYPO3\CMS\ContentBlocks\Utility\ContentBlockPathUtility;
use TYPO3\CMS\ContentBlocks\Validation\ContentBlockNameValidator;
use TYPO3\CMS\ContentBlocks\Validation\PageTypeNameValidator;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Creates a Content Block like content-blocks:create does – ConfigBuilder for config.yaml, ContentBlockBuilder
 * for the files – plus the fields entered in the backend form, with their labels only in labels.xlf. In a second
 * request, once TCA knows the new Content Block, its table is updated and the backend preview generated.
 */
final readonly class ContentBlockCreator
{
    public const CONTENT_TYPES = [ContentType::CONTENT_ELEMENT, ContentType::RECORD_TYPE, ContentType::PAGE_TYPE];

    public function __construct(
        private ConfigBuilder $configBuilder,
        private ContentBlockBuilder $contentBlockBuilder,
        private ContentBlockReloader $contentBlockReloader,
        private PackageResolver $packageResolver,
        private CacheManager $cacheManager,
        private ConfigYamlLabelStripper $configYamlLabelStripper,
        private BackendPreviewService $backendPreviewService,
        private FieldDefinitions $fieldDefinitions,
        private TableUpdater $tableUpdater,
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
        $errors = [...$errors, ...$this->fieldDefinitions->validateFields($new->fields, $identifiers)];
        if ($errors !== []) {
            return $errors;
        }
        [$yaml, $optionErrors] = $this->buildYaml($new);
        // Options that are no valid YAML are left out of $yaml, so the schema still checks all the others.
        return [...$optionErrors, ...$this->fieldDefinitions->validateAgainstSchema($this->createLoadedContentBlock($new, $yaml))];
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
        $this->tableUpdater->addMissing((string) $contentBlock->getYaml()['table']);
        foreach ($this->backendPreviewService->plan($contentBlockName, null, true) as $plan) {
            $this->backendPreviewService->apply($plan);
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
            [$yaml['fields'][], $fieldErrors] = $this->fieldDefinitions->toYaml($new->contentType, $field);
            $errors = [...$errors, ...$fieldErrors];
        }
        return [$yaml, $errors];
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
