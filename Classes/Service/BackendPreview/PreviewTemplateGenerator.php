<?php

declare(strict_types=1);

namespace AskoEducation\Cbm\Service\BackendPreview;

use TYPO3\CMS\ContentBlocks\Definition\TableDefinitionCollection;
use TYPO3\CMS\ContentBlocks\Definition\TcaFieldDefinition;
use TYPO3\CMS\ContentBlocks\Generator\HtmlTemplateCodeGenerator;
use TYPO3\CMS\ContentBlocks\Loader\LoadedContentBlock;
use TYPO3\CMS\Core\Schema\Capability\LabelCapability;
use TYPO3\CMS\Core\Schema\Capability\TcaSchemaCapability;
use TYPO3\CMS\Core\Schema\RelationshipType;
use TYPO3\CMS\Core\Schema\TcaSchema;
use TYPO3\CMS\Core\Schema\TcaSchemaFactory;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Content Blocks' preview generator
 */
readonly class PreviewTemplateGenerator extends HtmlTemplateCodeGenerator
{
    /**
     * Stops describing related records at this depth, e.g. for a Relation that points back to its own table.
     */
    private const MAX_DEPTH = 3;

    /**
     * Field types never shown, like in HtmlTemplateCodeGenerator::createFieldVariables().
     */
    private const HIDDEN_TYPES = [
        'Password',
        'Pass',
        'ImageManipulation',
    ];

    /**
     * Field types whose partial shows item labels; it needs the column name to look them up.
     */
    private const TYPES_WITH_ITEMS = [
        'Select',
        'SelectNumber',
        'SelectText',
        'Radio',
    ];

    public function __construct(
        private TcaSchemaFactory $tcaSchemaFactory,
    ) {
    }

    /**
     * Content Blocks' element preview with the lines of the "Content" section replaced by one
     * partial call per field; header and footer stay as Content Blocks generates them.
     *
     * @return array<string>
     */
    protected function createElementPreview(LoadedContentBlock $contentBlock, TableDefinitionCollection $tableDefinitionCollection): array
    {
        $lines = parent::createElementPreview($contentBlock, $tableDefinitionCollection);
        $start = array_search('<f:section name="Content">', $lines, true);
        $end = array_search('</f:section>', array_slice($lines, (int)$start, null, true), true);
        if ($start === false || $end === false) {
            return $lines;
        }
        $typeName = $contentBlock->getYaml()['typeName'];
        $schema = $this->getSchema('tt_content', (string)$typeName);
        $fieldLines = [];
        foreach ($tableDefinitionCollection->getContentElementDefinition($typeName)->getOverrideColumns() as $column) {
            $line = $this->createFieldLine($column, $contentBlock, $tableDefinitionCollection, $schema);
            if ($line !== null) {
                $fieldLines[] = '    ' . $line;
            }
        }
        array_splice($lines, $start + 1, $end - $start - 1, $fieldLines);
        return $lines;
    }

    private function createFieldLine(TcaFieldDefinition $column, LoadedContentBlock $contentBlock, TableDefinitionCollection $tableDefinitionCollection, ?TcaSchema $schema): ?string
    {
        $type = $column->fieldType->getName();
        if (in_array($type, self::HIDDEN_TYPES, true)) {
            return null;
        }
        if (in_array($type, ['Relation', 'Collection'], true) && $this->canRenderGrid($column, $contentBlock, $tableDefinitionCollection)) {
            return '<f:render partial="PageLayout/Grid" arguments="{data: data, identifier: \'' . $column->identifier . '\'}"/>';
        }
        $field = $this->describeField($column, $tableDefinitionCollection, $schema, 0);
        if ($field === null) {
            return '<f:comment>{data.' . $column->identifier . '}</f:comment>';
        }
        return '<f:render partial="Cbm/' . $field['type'] . '" arguments="{record: data, '
            . $this->toFluidEntries(array_diff_key($field, ['type' => true])) . '}"/>';
    }

    /**
     * Field type partial and its arguments, or null for field types without a partial.
     * The label comes from the TCA schema, i.e. what the backend form shows.
     *
     * @return ?array{type: string, field: string, multiple?: int, fields?: list<array<string, mixed>>, label?: string}
     */
    private function describeField(TcaFieldDefinition $column, TableDefinitionCollection $tableDefinitionCollection, ?TcaSchema $schema, int $depth): ?array
    {
        $type = $column->fieldType->getName();
        if (in_array($type, self::HIDDEN_TYPES, true) || !$this->hasPartial($type)) {
            return null;
        }
        $config = $column->getTca()['config'] ?? [];
        // f:render.text() and cbm:itemLabels need the column name, the others access the record by identifier.
        $usesColumnName = in_array($type, ['Text', 'Textarea', ...self::TYPES_WITH_ITEMS], true);
        $field = ['type' => $type, 'field' => $usesColumnName ? $column->uniqueIdentifier : $column->identifier];
        $field += match ($type) {
            'File' => ['multiple' => $this->holdsOne($config) ? 0 : 1],
            'Relation' => [
                'multiple' => $this->holdsOne($config) ? 0 : 1,
                'fields' => $this->describeRelatedFields($column, $tableDefinitionCollection, $depth + 1),
            ],
            'Collection' => ['fields' => $this->describeRelatedFields($column, $tableDefinitionCollection, $depth + 1)],
            default => [],
        };
        $label = $schema?->hasField($column->uniqueIdentifier) ? $schema->getField($column->uniqueIdentifier)->getLabel() : '';
        if ($label !== '') {
            $field['label'] = $label;
        }
        return $field;
    }

    /**
     * The fields shown of each related record, like HtmlTemplateCodeGenerator::createCollectionPreview():
     * all fields of a Content Blocks table, otherwise the table's label field (e.g. the page title), else the uid.
     *
     * @return list<array<string, mixed>>
     */
    private function describeRelatedFields(TcaFieldDefinition $column, TableDefinitionCollection $tableDefinitionCollection, int $depth): array
    {
        $tables = $this->relatedTables($column);
        $table = count($tables) === 1 ? $tables[0] : null;
        $schema = $table !== null ? $this->getSchema($table) : null;
        if ($table === null || $table === 'tt_content' || !$tableDefinitionCollection->hasTable($table) || $depth > self::MAX_DEPTH) {
            return [$this->describeTitle($schema)];
        }
        $fields = [];
        foreach ($tableDefinitionCollection->getTable($table)->getDefaultTypeDefinition()->getOverrideColumns() as $relatedColumn) {
            $field = $this->describeField($relatedColumn, $tableDefinitionCollection, $schema, $depth);
            if ($field !== null) {
                $fields[] = $field;
            }
        }
        return $fields;
    }

    /**
     * The label field of the related table (what the backend shows as record title), shown without field label.
     *
     * @return array{type: string, field: string}
     */
    private function describeTitle(?TcaSchema $schema): array
    {
        $labelCapability = $schema?->hasCapability(TcaSchemaCapability::Label) ? $schema->getCapability(TcaSchemaCapability::Label) : null;
        $labelField = $labelCapability instanceof LabelCapability ? $labelCapability->getPrimaryFieldName() : null;
        return $labelField !== null ? ['type' => 'Text', 'field' => $labelField] : ['type' => 'Number', 'field' => 'uid'];
    }

    /**
     * Whether Content Blocks' PageLayout/Grid can show the related records: StandardPreviewRendererResolver needs a
     * preview renderer for their table (only the table-wide one is checked; there is no record type to look up),
     * and Content Blocks' PreviewRenderer must not render them with this Content Block's own template.
     */
    private function canRenderGrid(TcaFieldDefinition $column, LoadedContentBlock $contentBlock, TableDefinitionCollection $tableDefinitionCollection): bool
    {
        foreach ($this->relatedTables($column) as $table) {
            $hasPreviewRenderer = (string)($this->getSchema($table)?->getRawConfiguration()['previewRenderer'] ?? '') !== '';
            $usesThisTemplate = $table !== 'tt_content'
                && $tableDefinitionCollection->hasTable($table)
                && $tableDefinitionCollection->getTable($table)->contentTypeDefinitionCollection->getFirst()->getName() === $contentBlock->getName();
            if (!$hasPreviewRenderer || $usesThisTemplate) {
                return false;
            }
        }
        return true;
    }

    private function hasPartial(string $type): bool
    {
        return is_file(ExtensionManagementUtility::extPath('cbm') . 'Resources/Private/Partials/Cbm/' . $type . '.fluid.html');
    }

    private function holdsOne(array $config): bool
    {
        return RelationshipType::fromTcaConfiguration($config)->hasOne();
    }

    private function getSchema(string $table, string $recordType = ''): ?TcaSchema
    {
        if (!$this->tcaSchemaFactory->has($table)) {
            return null;
        }
        $schema = $this->tcaSchemaFactory->get($table);
        return $recordType !== '' && $schema->hasSubSchema($recordType) ? $schema->getSubSchema($recordType) : $schema;
    }

    /**
     * @return list<string>
     */
    private function relatedTables(TcaFieldDefinition $column): array
    {
        $config = $column->getTca()['config'] ?? [];
        return GeneralUtility::trimExplode(',', (string)($config['foreign_table'] ?? $config['allowed'] ?? ''), true);
    }

    /**
     * Entries of a Fluid array literal, e.g. "field: 'stats', fields: {0: {type: 'Text', field: 'number'}}".
     */
    private function toFluidEntries(array $values): string
    {
        $entries = [];
        foreach ($values as $key => $value) {
            $entries[] = $key . ': ' . match (true) {
                is_array($value) => '{' . $this->toFluidEntries($value) . '}',
                is_int($value) => (string)$value,
                default => "'" . addcslashes((string)$value, "'\\") . "'",
            };
        }
        return implode(', ', $entries);
    }
}
