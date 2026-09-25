<?php

declare(strict_types=1);

namespace AskoEducation\Cbm\Service\Creation;

use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * A field of a new Content Block, as entered in the backend form.
 */
final readonly class NewField
{
    /**
     * Field types with items, entered as one "value = Label" per line.
     */
    public const TYPES_WITH_ITEMS = ['Select', 'Radio'];

    public function __construct(
        public string $identifier = '',
        public string $type = '',
        public string $label = '',
        public string $description = '',
        public bool $required = false,
        public string $items = '',
    ) {
    }

    public static function fromFormData(array $data): self
    {
        return new self(
            identifier: trim((string) ($data['identifier'] ?? '')),
            type: (string) ($data['type'] ?? ''),
            label: trim((string) ($data['label'] ?? '')),
            description: trim((string) ($data['description'] ?? '')),
            required: (bool) ($data['required'] ?? false),
            items: (string) ($data['items'] ?? ''),
        );
    }

    public function hasItems(): bool
    {
        return in_array($this->type, self::TYPES_WITH_ITEMS, true);
    }

    /**
     * @return list<array{label: string, value: string}> from one "value = Label" (or just "value") per line
     */
    public function getItems(): array
    {
        $items = [];
        foreach (GeneralUtility::trimExplode("\n", $this->items, true) as $line) {
            [$value, $label] = array_pad(GeneralUtility::trimExplode('=', $line, false, 2), 2, '');
            $items[] = ['label' => $label !== '' ? $label : $value, 'value' => $value];
        }
        return $items;
    }

    /**
     * @return array<string, mixed> the field as in config.yaml
     */
    public function toYaml(): array
    {
        $field = ['identifier' => $this->identifier, 'type' => $this->type];
        if ($this->label !== '') {
            $field['label'] = $this->label;
        }
        if ($this->description !== '') {
            $field['description'] = $this->description;
        }
        if ($this->required) {
            $field['required'] = true;
        }
        if ($this->type === 'Select') {
            $field['renderType'] = 'selectSingle';
        }
        if ($this->hasItems()) {
            $field['items'] = $this->getItems();
        }
        return $field;
    }
}
