<?php

declare(strict_types=1);

namespace AskoEducation\Cbm\Service\LanguageFile;

use Symfony\Component\Translation\MessageCatalogue;
use TYPO3\CMS\ContentBlocks\Definition\Factory\ContentBlockCompiler;
use TYPO3\CMS\ContentBlocks\FieldType\FieldTypeRegistry;
use TYPO3\CMS\ContentBlocks\Generator\LanguageFileGenerator;
use TYPO3\CMS\ContentBlocks\Loader\LoadedContentBlock;
use TYPO3\CMS\ContentBlocks\Registry\AutomaticLanguageKeysRegistry;
use TYPO3\CMS\ContentBlocks\Registry\AutomaticLanguageSource;
use TYPO3\CMS\ContentBlocks\Registry\ContentBlockRegistry;
use TYPO3\CMS\ContentBlocks\Registry\LanguageFileRegistry;
use TYPO3\CMS\ContentBlocks\Registry\LanguageFileRegistryFactory;
use TYPO3\CMS\ContentBlocks\Schema\SimpleTcaSchemaFactory;
use TYPO3\CMS\ContentBlocks\Utility\ContentBlockPathUtility;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Localization\Loader\XliffLoader;
use TYPO3\CMS\Core\Localization\TranslationDomainMapper;
use TYPO3\CMS\Core\Localization\TranslationDomainResolver;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Reads and writes a Content Block's labels.xlf with Content Blocks' own pieces: the compiler for the language keys
 * of its fields, XliffLoader to read (as LanguageFileRegistryFactory does) and LanguageFileGenerator to write.
 */
final readonly class LanguageFileService
{
    public function __construct(
        private ContentBlockCompiler $contentBlockCompiler,
        private FieldTypeRegistry $fieldTypeRegistry,
        private SimpleTcaSchemaFactory $simpleTcaSchemaFactory,
        private TranslationDomainMapper $translationDomainMapper,
        private TranslationDomainResolver $translationDomainResolver,
        private XliffLoader $xliffLoader,
        private CacheManager $cacheManager,
    ) {
    }

    /**
     * The language keys Content Blocks derives from the fields of all Content Blocks in $registry.
     */
    public function compile(ContentBlockRegistry $registry): AutomaticLanguageKeysRegistry
    {
        return $this->contentBlockCompiler
            ->compile($registry, $this->fieldTypeRegistry, $this->simpleTcaSchemaFactory)
            ->getAutomaticLanguageKeys();
    }

    /**
     * The language keys of $contentBlock only, compiled with just what their compilation depends on.
     */
    public function compileFor(ContentBlockRegistry $registry, LoadedContentBlock $contentBlock): AutomaticLanguageKeysRegistry
    {
        return $this->compile($this->registryWith($registry, $contentBlock));
    }

    /**
     * A registry holding $contentBlock plus what its compilation depends on: record types without
     * an own typeField take it over from Content Blocks compiled before them on the same table.
     */
    public function registryWith(ContentBlockRegistry $registry, LoadedContentBlock $contentBlock): ContentBlockRegistry
    {
        $yaml = $contentBlock->getYaml();
        $result = new ContentBlockRegistry($this->simpleTcaSchemaFactory);
        foreach ($registry->getAll() as $existing) {
            if ($existing->getName() === $contentBlock->getName()) {
                $result->register($contentBlock);
            } elseif (!isset($yaml['typeField']) && $existing->getYaml()['table'] === $yaml['table']) {
                $result->register($existing);
            }
        }
        return $result;
    }

    /**
     * Per Content Block, how many of its field language keys labels.xlf does not contain yet.
     *
     * @return array<string, int> by Content Block name
     */
    public function countKeysMissingInXlf(ContentBlockRegistry $registry): array
    {
        $automaticLanguageKeys = $this->compile($registry);
        $languageFileRegistry = (new LanguageFileRegistryFactory($registry, $this->xliffLoader))->create();
        $missing = [];
        foreach ($registry->getAll() as $contentBlock) {
            $missing[$contentBlock->getName()] = count(array_filter(
                $automaticLanguageKeys->getByContentBlock($contentBlock),
                static fn(AutomaticLanguageSource $source): bool => $source->value !== ''
                    && !$languageFileRegistry->isset($contentBlock->getName(), $source->key),
            ));
        }
        return $missing;
    }

    private function getPath(LoadedContentBlock $contentBlock): string
    {
        return GeneralUtility::getFileAbsFileName($contentBlock->getExtPath()) . '/' . ContentBlockPathUtility::getLanguageFilePath();
    }

    /**
     * @return array<string, string>
     */
    public function readFile(LoadedContentBlock $contentBlock): array
    {
        $path = $this->getPath($contentBlock);
        return is_file($path) ? $this->read((string)file_get_contents($path)) : [];
    }

    /**
     * Reads labels.xlf the way Content Blocks does (LanguageFileRegistryFactory).
     *
     * @return array<string, string>
     */
    public function read(string $xlf): array
    {
        return trim($xlf) === '' ? [] : $this->xliffLoader->load($xlf, 'en')->all('messages');
    }

    /**
     * labels.xlf as Content Blocks generates it: the field keys, then all other keys of $labels.
     *
     * @param array<string, string> $labels content of labels.xlf, which takes precedence over config.yaml
     * @param ?string $date null for now
     */
    public function generate(
        LoadedContentBlock $contentBlock,
        AutomaticLanguageKeysRegistry $automaticLanguageKeys,
        array $labels,
        ?string $date = null,
    ): string {
        $languageFileRegistry = new LanguageFileRegistry();
        $languageFileRegistry->register($contentBlock, new MessageCatalogue('en', ['messages' => $labels]));
        $generator = new LanguageFileGenerator(
            $automaticLanguageKeys,
            $languageFileRegistry,
            $this->translationDomainMapper,
            $this->translationDomainResolver,
        );
        return $generator->generate($contentBlock, $date);
    }

    /**
     * Same file handling as GenerateLanguageFileCommand::writeLabelsXlf() of Content Blocks.
     */
    public function write(string $contentBlockPath, string $xlf): void
    {
        GeneralUtility::mkdir_deep($contentBlockPath . '/' . ContentBlockPathUtility::getLanguageFolder());
        GeneralUtility::writeFile($contentBlockPath . '/' . ContentBlockPathUtility::getLanguageFilePath(), $xlf);
    }

    /**
     * To be called after writing: changed labels need the translation cache flushed. Added or removed keys and
     * changes of config.yaml also need TCA rebuilt, as Content Blocks only links a label in TCA when labels.xlf
     * has its key: the "system" group (which includes the translation cache), like content-blocks:create does.
     */
    public function flushCaches(bool $rebuildTca): void
    {
        if ($rebuildTca) {
            $this->cacheManager->flushCachesInGroup('system');
        } else {
            $this->cacheManager->getCache('l10n')->flush();
        }
    }

    public function now(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('c');
    }
}
