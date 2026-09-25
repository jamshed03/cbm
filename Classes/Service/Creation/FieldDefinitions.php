<?php

declare(strict_types=1);

namespace AskoEducation\Cbm\Service\Creation;

use TYPO3\CMS\ContentBlocks\Definition\ContentType\ContentType;
use TYPO3\CMS\ContentBlocks\FieldType\FieldTypeRegistry;
use TYPO3\CMS\ContentBlocks\JsonSchemaValidation\ContentBlockValidator;
use TYPO3\CMS\ContentBlocks\JsonSchemaValidation\JsonSchemaErrorFormatter;
use TYPO3\CMS\ContentBlocks\Loader\LoadedContentBlock;

/**
 * The fields the backend form can create and edit: which types it offers, their options, the checks of the entered
 * fields, their config.yaml, and the validation of a whole configuration like content-blocks:lint does.
 */
final readonly class FieldDefinitions
{
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
        private FieldTypeRegistry $fieldTypeRegistry,
        private FieldOptionSchema $fieldOptionSchema,
        private ContentBlockValidator $contentBlockValidator,
        private JsonSchemaErrorFormatter $jsonSchemaErrorFormatter,
    ) {
    }

    /**
     * @return list<string>
     */
    public function getFieldTypes(): array
    {
        return array_values(array_filter(self::FIELD_TYPES, $this->fieldTypeRegistry->has(...)));
    }

    public function isEditable(string $fieldType): bool
    {
        return in_array($fieldType, $this->getFieldTypes(), true);
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
     * The keys of a field in config.yaml the form sets: when editing, all others are kept as they are.
     *
     * @return list<string>
     */
    public function getKeysSetByForm(string $fieldType): array
    {
        $keys = ['identifier', 'type', 'label', 'description', 'required', 'renderType'];
        if (in_array($fieldType, NewField::TYPES_WITH_ITEMS, true)) {
            $keys[] = 'items';
        }
        foreach ($this->fieldOptionSchema->getOptions(ContentType::CONTENT_ELEMENT, $fieldType) as $option) {
            $keys[] = explode('.', $option->name)[0];
        }
        return array_values(array_unique($keys));
    }

    /**
     * Checks what the form can check by itself: identifiers, types and items of the entered fields.
     *
     * @param list<NewField> $fields
     * @param list<string> $identifiers identifiers already taken by other fields
     * @return list<string>
     */
    public function validateFields(array $fields, array $identifiers): array
    {
        $errors = [];
        $fieldTypes = $this->getFieldTypes();
        foreach ($fields as $position => $field) {
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
        return $errors;
    }

    /**
     * The field as in config.yaml, with its options typed from the form input.
     *
     * @return array{0: array<string, mixed>, 1: list<string>} the field and the options that are no valid YAML
     */
    public function toYaml(ContentType $contentType, NewField $field): array
    {
        [$options, $optionErrors] = $this->fieldOptionSchema->convert($contentType, $field->type, $field->options);
        $errors = array_map(static fn(string $error): string => sprintf('Field "%s": %s', $field->identifier, $error), $optionErrors);
        return [$field->toYaml($options), $errors];
    }

    /**
     * Validates the configuration of $contentBlock like content-blocks:lint does.
     *
     * @return list<string>
     */
    public function validateAgainstSchema(LoadedContentBlock $contentBlock): array
    {
        $yaml = $contentBlock->getYaml();
        $errorsByPath = $this->jsonSchemaErrorFormatter->format($this->contentBlockValidator->validateContentBlock($contentBlock));
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
}
