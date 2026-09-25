<?php

declare(strict_types=1);

namespace AskoEducation\Cbm\Service\Creation;

use TYPO3\CMS\ContentBlocks\Definition\ContentType\ContentType;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * The options of each field type, read from the JSON schema Content Blocks validates config.yaml against
 * (content-blocks:lint), and their conversion from form input into config.yaml values.
 *
 * Options with a single value (boolean, number, string, enum), lists and objects of such values (e.g. "range" with
 * "lower" and "upper") can be entered in the form; deeper structures (e.g. fieldControl, treeConfig) cannot.
 */
final class FieldOptionSchema
{
    /**
     * Keys the form covers itself, or that make no sense for a new field.
     */
    private const SKIPPED = [
        'identifier', 'alias', 'label', 'description', 'useExistingField', 'prefixField', 'prefixType', 'type',
        'items', 'required', 'fields',
    ];

    /**
     * @var array<string, array<mixed>> parsed schema by content type
     */
    private array $schemas = [];

    /**
     * @return list<FieldOption>
     */
    public function getOptions(ContentType $contentType, string $fieldType): array
    {
        $schema = $this->getSchema($contentType);
        $fieldSchema = $schema['allOf'][1]['properties']['fields']['items'] ?? [];
        $properties = [];
        foreach ($fieldSchema['allOf'] ?? [] as $branch) {
            if (($branch['if']['properties']['type']['const'] ?? null) === $fieldType) {
                $properties = $branch['then']['properties'] ?? [];
                break;
            }
        }
        $options = [];
        foreach ($properties as $name => $property) {
            // An empty definition refers to the options common to all field types, e.g. onChange.
            $property = $property === [] ? ($fieldSchema['properties'][$name] ?? []) : $property;
            $property = $this->resolveReference($schema, $property);
            if (in_array($name, self::SKIPPED, true)) {
                continue;
            }
            if (($property['type'] ?? null) === 'object') {
                foreach ($property['properties'] ?? [] as $childName => $child) {
                    $option = $this->createOption($name . '.' . $childName, $this->resolveReference($schema, $child));
                    if ($option !== null) {
                        $options[] = $option;
                    }
                }
                continue;
            }
            $option = $this->createOption((string) $name, $property);
            if ($option !== null) {
                $options[] = $option;
            }
        }
        usort($options, static fn(FieldOption $a, FieldOption $b): int => strnatcasecmp($a->name, $b->name));
        return $options;
    }

    /**
     * The options of $fieldType entered in the form, as config.yaml values; empty inputs are left out.
     *
     * @param array<string, mixed> $input as submitted below fields[i][options]
     * @return array{0: array<string, mixed>, 1: list<string>} the options and what could not be converted
     */
    public function convert(ContentType $contentType, string $fieldType, array $input): array
    {
        $options = [];
        $errors = [];
        foreach ($this->getOptions($contentType, $fieldType) as $option) {
            $path = explode('.', $option->name);
            $raw = $input;
            foreach ($path as $segment) {
                $raw = is_array($raw) ? ($raw[$segment] ?? null) : null;
            }
            if ($raw === null || $raw === '' || $raw === []) {
                continue;
            }
            $value = $this->convertValue($option, $raw);
            if ($value === null) {
                $errors[] = sprintf('%s: "%s" is not a valid %s.', $option->name, is_array($raw) ? implode(',', $raw) : $raw, $option->kind);
                continue;
            }
            if (count($path) === 2) {
                $options[$path[0]][$path[1]] = $value;
            } else {
                $options[$option->name] = $value;
            }
        }
        return [$options, $errors];
    }

    private function convertValue(FieldOption $option, mixed $raw): mixed
    {
        return match ($option->kind) {
            FieldOption::KIND_BOOLEAN => match ((string) $raw) {
                'true' => true,
                'false' => false,
                default => null,
            },
            FieldOption::KIND_INTEGER => is_numeric($raw) && (string) (int) $raw === trim((string) $raw) ? (int) $raw : null,
            FieldOption::KIND_NUMBER => is_numeric($raw) ? $raw + 0 : null,
            FieldOption::KIND_ENUM => $this->toEnumValue($option, (string) $raw),
            FieldOption::KIND_MULTI_ENUM => array_values(array_map('strval', (array) $raw)),
            FieldOption::KIND_LIST => GeneralUtility::trimExplode(',', (string) $raw, true),
            default => is_numeric($raw) && in_array('integer', $option->values, true) ? (int) $raw : (string) $raw,
        };
    }

    private function toEnumValue(FieldOption $option, string $raw): string|int|null
    {
        foreach ($option->values as $value) {
            if ((string) $value === $raw) {
                return $value;
            }
        }
        return null;
    }

    private function createOption(string $name, array $property): ?FieldOption
    {
        // displayCond and similar: a string or a structure; the form offers the string.
        foreach ($property['oneOf'] ?? $property['anyOf'] ?? [] as $variant) {
            if (in_array($variant['type'] ?? null, ['string', 'integer', 'number', 'boolean'], true)) {
                $property = $variant + $property;
                break;
            }
        }
        $types = (array) ($property['type'] ?? []);
        $description = (string) ($property['description'] ?? '');
        $default = isset($property['default']) && is_scalar($property['default'])
            ? (is_bool($property['default']) ? ($property['default'] ? 'true' : 'false') : (string) $property['default'])
            : '';
        $minimum = isset($property['minimum']) ? (int) $property['minimum'] : null;
        $maximum = isset($property['maximum']) ? (int) $property['maximum'] : null;
        if (isset($property['enum'])) {
            return new FieldOption($name, FieldOption::KIND_ENUM, $description, $default, array_values($property['enum']));
        }
        if ($types === ['array']) {
            $itemValues = $property['items']['enum'] ?? null;
            if (is_array($itemValues)) {
                return new FieldOption($name, FieldOption::KIND_MULTI_ENUM, $description, '', array_values($itemValues));
            }
            return in_array($property['items']['type'] ?? null, ['string', 'integer'], true)
                ? new FieldOption($name, FieldOption::KIND_LIST, $description)
                : null;
        }
        return match (true) {
            $types === ['boolean'] => new FieldOption($name, FieldOption::KIND_BOOLEAN, $description, $default),
            $types === ['integer'] => new FieldOption($name, FieldOption::KIND_INTEGER, $description, $default, [], $minimum, $maximum),
            $types === ['number'] => new FieldOption($name, FieldOption::KIND_NUMBER, $description, $default, [], $minimum, $maximum),
            // "string", or several scalar types such as string|integer: entered as text; "values" keeps the types.
            $types !== [] && array_diff($types, ['string', 'integer', 'number']) === []
                => new FieldOption($name, FieldOption::KIND_STRING, $description, $default, $types),
            default => null,
        };
    }

    /**
     * Follows a "$ref" to a place in the same schema, e.g. "#/allOf/1/properties/fields/items/...".
     */
    private function resolveReference(array $schema, array $property): array
    {
        $reference = $property['$ref'] ?? null;
        if (!is_string($reference) || !str_starts_with($reference, '#/')) {
            return $property;
        }
        $node = $schema;
        foreach (explode('/', substr($reference, 2)) as $segment) {
            $node = $node[$segment] ?? null;
            if (!is_array($node)) {
                return [];
            }
        }
        return $node;
    }

    private function getSchema(ContentType $contentType): array
    {
        return $this->schemas[$contentType->value] ??= json_decode(
            (string) file_get_contents(ExtensionManagementUtility::extPath('content_blocks') . 'JsonSchema/' . $contentType->value . '.schema.json'),
            true,
        ) ?? [];
    }
}
