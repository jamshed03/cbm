<?php

declare(strict_types=1);

namespace AskoEducation\Cbm\Service\LabelMigration;

use Symfony\Component\Translation\MessageCatalogue;
use Symfony\Component\Yaml\Yaml;
use TYPO3\CMS\ContentBlocks\Definition\Factory\ContentBlockCompiler;
use TYPO3\CMS\ContentBlocks\FieldType\FieldTypeRegistry;
use TYPO3\CMS\ContentBlocks\Generator\LanguageFileGenerator;
use TYPO3\CMS\ContentBlocks\Loader\LoadedContentBlock;
use TYPO3\CMS\ContentBlocks\Registry\AutomaticLanguageKeysRegistry;
use TYPO3\CMS\ContentBlocks\Registry\ContentBlockRegistry;
use TYPO3\CMS\ContentBlocks\Registry\LanguageFileRegistry;
use TYPO3\CMS\ContentBlocks\Schema\SimpleTcaSchemaFactory;
use TYPO3\CMS\ContentBlocks\Utility\ContentBlockPathUtility;
use TYPO3\CMS\Core\Localization\Loader\XliffLoader;
use TYPO3\CMS\Core\Localization\TranslationDomainMapper;
use TYPO3\CMS\Core\Localization\TranslationDomainResolver;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Moves the labels of Content Blocks from config.yaml into language/labels.xlf.
 */
final readonly class LabelMigrationService
{
    private const MARKER = '__cbm_label_%d__';

    public function __construct(
        private ContentBlockReloader $contentBlockLoader,
        private ContentBlockCompiler $contentBlockCompiler,
        private FieldTypeRegistry $fieldTypeRegistry,
        private SimpleTcaSchemaFactory $simpleTcaSchemaFactory,
        private TranslationDomainMapper $translationDomainMapper,
        private TranslationDomainResolver $translationDomainResolver,
        private XliffLoader $xliffLoader,
        private ConfigYamlLabelStripper $stripper,
    ) {
    }

    /**
     * @return list<MigrationPlan>
     */
    public function plan(?string $contentBlockName, ?string $extension, MigrationOptions $options): array
    {
        $registry = $this->contentBlockLoader->loadRegistry();
        $contentBlocks = $this->selectContentBlocks($registry, $contentBlockName, $extension);
        $automaticLanguageKeys = $this->compile($registry);
        return array_map(
            fn(LoadedContentBlock $contentBlock): MigrationPlan => $this->planContentBlock($contentBlock, $registry, $automaticLanguageKeys, $options),
            $contentBlocks,
        );
    }

    public function apply(MigrationPlan $plan): void
    {
        // Same file handling as GenerateLanguageFileCommand::writeLabelsXlf() of Content Blocks.
        if ($plan->newXlf !== null) {
            GeneralUtility::mkdir_deep($plan->contentBlockPath . '/' . ContentBlockPathUtility::getLanguageFolder());
            GeneralUtility::writeFile($plan->contentBlockPath . '/' . ContentBlockPathUtility::getLanguageFilePath(), $plan->newXlf);
        }
        if ($plan->newYaml !== null) {
            GeneralUtility::writeFile($plan->contentBlockPath . '/' . ContentBlockPathUtility::getContentBlockDefinitionFileName(), $plan->newYaml);
        }
    }

    /**
     * @return list<LoadedContentBlock>
     */
    private function selectContentBlocks(ContentBlockRegistry $registry, ?string $contentBlockName, ?string $extension): array
    {
        if ($contentBlockName !== null) {
            if (!$registry->hasContentBlock($contentBlockName)) {
                throw new \InvalidArgumentException('Content Block "' . $contentBlockName . '" does not exist.', 1758700003);
            }
            return [$registry->getContentBlock($contentBlockName)];
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

    private function planContentBlock(
        LoadedContentBlock $contentBlock,
        ContentBlockRegistry $registry,
        AutomaticLanguageKeysRegistry $automaticLanguageKeys,
        MigrationOptions $options,
    ): MigrationPlan {
        $path = GeneralUtility::getFileAbsFileName($contentBlock->getExtPath());
        $xlfPath = $path . '/' . ContentBlockPathUtility::getLanguageFilePath();
        $oldYaml = (string) file_get_contents($path . '/' . ContentBlockPathUtility::getContentBlockDefinitionFileName());
        $oldXlf = is_file($xlfPath) ? (string) file_get_contents($xlfPath) : '';
        $oldLabels = $this->readXlf($oldXlf);
        $labelLines = $this->stripper->findLabels($oldYaml);

        try {
            $labelsByKey = $this->traceLabels($contentBlock, $registry, $automaticLanguageKeys, $oldYaml, $labelLines);
        } catch (\Throwable $e) {
            return new MigrationPlan($contentBlock->getName(), $path, null, null, error: 'Labels could not be traced: ' . $e->getMessage());
        }

        // Content Blocks lets labels.xlf win over config.yaml, which hides later edits in config.yaml.
        $conflicts = [];
        $labels = $oldLabels;
        foreach ($labelsByKey as $key => $labelLine) {
            if (!isset($oldLabels[$key]) || $oldLabels[$key] === $labelLine->value) {
                continue;
            }
            $kept = $options->prefer === Prefer::Yaml ? $labelLine->value : $oldLabels[$key];
            $conflicts[$key] = ['yaml' => $labelLine->value, 'xlf' => $oldLabels[$key], 'kept' => $kept];
            $labels[$key] = $kept;
        }

        $date = $this->extractDate($oldXlf) ?? $this->now();
        $newXlf = $this->generateXlf($contentBlock, $automaticLanguageKeys, $labels, $date);
        $newLabels = $this->readXlf($newXlf);
        // Compare labels, not text: a reformatted labels.xlf is no reason to rewrite it.
        $xlfChanged = $this->sorted($newLabels) !== $this->sorted($oldLabels);
        if ($xlfChanged) {
            $date = $this->now();
            $newXlf = (string) preg_replace('/ date="[^"]*"/', ' date="' . $date . '"', $newXlf, 1);
        }

        $removable = array_values(array_filter(
            $labelsByKey,
            static fn(LabelLine $labelLine, string $key): bool => ($newLabels[$key] ?? null) === $labelLine->value || isset($conflicts[$key]),
            ARRAY_FILTER_USE_BOTH,
        ));
        usort($removable, static fn(LabelLine $a, LabelLine $b): int => $a->index <=> $b->index);

        $stripped = null;
        $error = null;
        if (!$options->keepYaml && $removable !== []) {
            $stripped = $this->stripper->remove($oldYaml, $removable);
            $error = $stripped->removed === [] ? null : $this->verify($contentBlock, $registry, $oldYaml, $stripped->content, $newXlf, $newLabels, $date);
        }
        $yamlIsStripped = $stripped !== null && $stripped->removed !== [] && $error === null;

        return new MigrationPlan(
            contentBlock: $contentBlock->getName(),
            contentBlockPath: $path,
            newXlf: $xlfChanged ? $newXlf : null,
            newYaml: $yamlIsStripped ? $stripped->content : null,
            addedKeys: array_diff_key($newLabels, $oldLabels),
            conflicts: $conflicts,
            removed: $yamlIsStripped ? $stripped->removed : [],
            skipped: $stripped?->skipped ?? [],
            error: $error,
        );
    }

    /**
     * Finds out which config.yaml line feeds which language key: every candidate value is replaced by a
     * unique marker and the compiler reports under which key each marker ends up. Candidates that are no
     * labels, labels coming from Basics and fallbacks (identifier, name) thereby drop out by themselves.
     *
     * @param list<LabelLine> $labelLines
     * @return array<string, LabelLine> by language key
     */
    private function traceLabels(
        LoadedContentBlock $contentBlock,
        ContentBlockRegistry $registry,
        AutomaticLanguageKeysRegistry $automaticLanguageKeys,
        string $yaml,
        array $labelLines,
    ): array {
        if ($labelLines === []) {
            return [];
        }
        $markers = [];
        $labelLinesByMarker = [];
        foreach ($labelLines as $labelLine) {
            $marker = sprintf(self::MARKER, $labelLine->index);
            $markers[$labelLine->index] = $marker;
            $labelLinesByMarker[$marker] = $labelLine;
        }
        $markedYaml = Yaml::parse($this->stripper->replaceValues($yaml, $labelLines, $markers));
        $marked = $this->contentBlockLoader->reload($contentBlock, $markedYaml);

        // Only literal labels reach the automatic sources; LLL references are skipped by Content Blocks.
        $values = [];
        foreach ($automaticLanguageKeys->getByContentBlock($contentBlock) as $source) {
            $values[$source->key] = $source->value;
        }
        $labelsByKey = [];
        foreach ($this->compile($this->registryWith($registry, $marked))->getByContentBlock($marked) as $source) {
            $labelLine = $labelLinesByMarker[$source->value] ?? null;
            if ($labelLine !== null && ($values[$source->key] ?? null) === $labelLine->value) {
                $labelsByKey[$source->key] = $labelLine;
            }
        }
        return $labelsByKey;
    }

    /**
     * Proves that the stripped config.yaml differs only in labels and still resolves to the same labels.xlf.
     *
     * @param array<string, string> $newLabels
     */
    private function verify(
        LoadedContentBlock $contentBlock,
        ContentBlockRegistry $registry,
        string $oldYaml,
        string $newYaml,
        string $newXlf,
        array $newLabels,
        string $date,
    ): ?string {
        try {
            $newRawYaml = Yaml::parse($newYaml);
            if ($this->stripper->withoutLabels(Yaml::parse($oldYaml)) !== $this->stripper->withoutLabels($newRawYaml)) {
                return 'Stripping labels would change more than labels in config.yaml.';
            }
            $reloaded = $this->contentBlockLoader->reload($contentBlock, $newRawYaml);
            $regeneratedXlf = $this->generateXlf($reloaded, $this->compile($this->registryWith($registry, $reloaded)), $newLabels, $date);
        } catch (\Throwable $e) {
            return 'Stripped config.yaml could not be compiled: ' . $e->getMessage();
        }
        if ($regeneratedXlf !== $newXlf) {
            return 'Stripped config.yaml would not resolve to the same labels.xlf.';
        }
        return null;
    }

    /**
     * A registry holding $contentBlock plus what its compilation depends on: record types without
     * an own typeField take it over from Content Blocks compiled before them on the same table.
     */
    private function registryWith(ContentBlockRegistry $registry, LoadedContentBlock $contentBlock): ContentBlockRegistry
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

    private function compile(ContentBlockRegistry $registry): AutomaticLanguageKeysRegistry
    {
        return $this->contentBlockCompiler
            ->compile($registry, $this->fieldTypeRegistry, $this->simpleTcaSchemaFactory)
            ->getAutomaticLanguageKeys();
    }

    /**
     * @param array<string, string> $labels content of labels.xlf, which takes precedence over config.yaml
     */
    private function generateXlf(
        LoadedContentBlock $contentBlock,
        AutomaticLanguageKeysRegistry $automaticLanguageKeys,
        array $labels,
        string $date,
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
     * Reads labels.xlf the way Content Blocks does (LanguageFileRegistryFactory).
     *
     * @return array<string, string>
     */
    private function readXlf(string $xlf): array
    {
        return trim($xlf) === '' ? [] : $this->xliffLoader->load($xlf, 'en')->all('messages');
    }

    /**
     * @param array<string, string> $labels
     */
    private function sorted(array $labels): array
    {
        ksort($labels);
        return $labels;
    }

    private function extractDate(string $xlf): ?string
    {
        return preg_match('/ date="([^"]*)"/', $xlf, $match) ? $match[1] : null;
    }

    private function now(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('c');
    }
}
