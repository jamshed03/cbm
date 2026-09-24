<?php

declare(strict_types=1);

namespace AskoEducation\Cbm\ViewHelpers;

use TYPO3\CMS\Core\Domain\RecordInterface;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Schema\SchemaLabelResolver;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3Fluid\Fluid\Core\ViewHelper\AbstractViewHelper;

/**
 * The labels of the item values stored in a Select or Radio field, resolved by core's SchemaLabelResolver
 * (TCA items, itemsProcFunc/itemsProcessors) and translated. A value without item is returned as it is.
 *
 *     <f:for each="{cbm:itemLabels(record: data, field: 'portfolio_hero_media_type')}" as="label">{label}</f:for>
 */
final class ItemLabelsViewHelper extends AbstractViewHelper
{
    public function __construct(
        private readonly SchemaLabelResolver $schemaLabelResolver,
    ) {}

    public function initializeArguments(): void
    {
        $this->registerArgument('record', RecordInterface::class, 'The record holding the field', true);
        $this->registerArgument('field', 'string', 'Column name of the field', true);
    }

    /**
     * @return list<string>
     */
    public function render(): array
    {
        /** @var RecordInterface $record */
        $record = $this->arguments['record'];
        $field = (string)$this->arguments['field'];
        $row = $record->getRawRecord()->toArray();
        $labels = [];
        foreach (GeneralUtility::trimExplode(',', (string)($row[$field] ?? ''), true) as $value) {
            $label = $this->schemaLabelResolver->getLabelForFieldValue($record->getMainType(), $field, $value, $row);
            $labels[] = $label !== '' ? $this->getLanguageService()->sL($label) : $value;
        }
        return $labels;
    }

    private function getLanguageService(): LanguageService
    {
        return $GLOBALS['LANG'];
    }
}
