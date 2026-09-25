<?php

declare(strict_types=1);

namespace AskoEducation\Cbm\Service\Creation;

use Opis\JsonSchema\JsonPointer;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;
use TYPO3\CMS\ContentBlocks\Definition\ContentType\ContentType;
use TYPO3\CMS\Core\Utility\ArrayUtility;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;
use TYPO3\CMS\Core\Utility\MathUtility;

/**
 * The options of each field type, read from the JSON schema Content Blocks validates config.yaml against
 * (content-blocks:lint), and the typing of form input into config.yaml values.
 *
 * Options with a single value (boolean, number, string, enum), lists and objects of such values (e.g. "range" with
 * "lower" and "upper") can be entered in the form; deeper structures (e.g. fieldControl, treeConfig) cannot.
 */
final class FieldOptionSchema
{
    /**
     * Keys the form covers itself (see NewField::toYaml()).
     */
    private const COVERED_BY_FORM = ['identifier', 'type', 'label', 'description', 'required', 'items'];

    /**
     * Keys that make no sense for a new field (alias, existing fields, prefixing) or need structures (fields).
     */
    private const NOT_OFFERED = ['alias', 'useExistingField', 'prefixField', 'prefixType', 'fields'];

    /**
     * @var array<string, array<mixed>> parsed schema by content type
     */
    private array $schemas = [];

    /**
     * @var array<string, array<string, list<FieldOption>>> by content type and field type
     */
    private array $options = [];

    /**
     * @return list<FieldOption>
     */
    public function getOptions(ContentType $contentType, string $fieldType): array
    {
        return $this->options[$contentType->value][$fieldType] ??= $this->readOptions($contentType, $fieldType);
    }

    /**
     * The options of $fieldType entered in the form, typed as the same text would be in config.yaml; empty inputs
     * are left out. Whether a value is valid is left to the schema validation (ContentBlockValidator).
     *
     * @param array<string, mixed> $input as submitted below fields[i][options]
     * @return array{0: array<string, mixed>, 1: list<string>} the options and the inputs that are no valid YAML
     */
    public function convert(ContentType $contentType, string $fieldType, array $input): array
    {
        $options = [];
        $errors = [];
        foreach ($this->getOptions($contentType, $fieldType) as $option) {
            if (!ArrayUtility::isValidPath($input, $option->name, '.')) {
                continue;
            }
            $raw = ArrayUtility::getValueByPath($input, $option->name, '.');
            if ($raw === null || $raw === '' || $raw === []) {
                continue;
            }
            try {
                $options = ArrayUtility::setValueByPath($options, $option->name, $this->toValue($option, $raw), '.');
            } catch (ParseException $e) {
                $errors[] = sprintf('%s: %s', $option->name, $e->getMessage());
            }
        }
        return [$options, $errors];
    }

    private function toValue(FieldOption $option, mixed $raw): mixed
    {
        return match ($option->kind) {
            FieldOption::KIND_MULTI_ENUM => array_values(array_map('strval', (array) $raw)),
            FieldOption::KIND_LIST => Yaml::parse('[' . $raw . ']'),
            FieldOption::KIND_ENUM => $this->toEnumValue($option, (string) $raw),
            FieldOption::KIND_STRING => $option->acceptsInteger && MathUtility::canBeInterpretedAsInteger($raw) ? (int) $raw : (string) $raw,
            default => Yaml::parse((string) $raw),
        };
    }

    /**
     * The value as the schema lists it (e.g. an integer), else the input as it is.
     */
    private function toEnumValue(FieldOption $option, string $raw): string|int
    {
        foreach ($option->values as $value) {
            if ((string) $value === $raw) {
                return $value;
            }
        }
        return $raw;
    }

    /**
     * @return list<FieldOption>
     */
    private function readOptions(ContentType $contentType, string $fieldType): array
    {
        $schema = $this->getSchema($contentType);
        $fieldSchema = $schema['allOf'][1]['properties']['fields']['items'] ?? null;
        if (!is_array($fieldSchema) || !is_array($fieldSchema['allOf'] ?? null)) {
            throw new \RuntimeException(
                sprintf('The Content Blocks schema of "%s" has an unknown layout: field options cannot be read.', $contentType->value),
                1758700030,
            );
        }
        $properties = [];
        foreach ($fieldSchema['allOf'] as $branch) {
            if (($branch['if']['properties']['type']['const'] ?? null) === $fieldType) {
                $properties = $branch['then']['properties'] ?? [];
                break;
            }
        }
        $candidates = [];
        foreach ($properties as $name => $property) {
            if (in_array($name, self::COVERED_BY_FORM, true) || in_array($name, self::NOT_OFFERED, true)) {
                continue;
            }
            // An empty definition refers to the options common to all field types, e.g. onChange.
            $property = $this->resolveReference($schema, $property === [] ? ($fieldSchema['properties'][$name] ?? []) : $property);
            if (($property['type'] ?? null) === 'object') {
                foreach ($property['properties'] ?? [] as $childName => $child) {
                    $candidates[$name . '.' . $childName] = $this->resolveReference($schema, $child);
                }
            } else {
                $candidates[(string) $name] = $property;
            }
        }
        $options = [];
        foreach ($candidates as $name => $property) {
            $option = $this->createOption((string) $name, $property);
            if ($option !== null) {
                $options[] = $option;
            }
        }
        usort($options, static fn(FieldOption $a, FieldOption $b): int => strnatcasecmp($a->name, $b->name));
        return $options;
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
            ? (is_bool($property['default']) ? var_export($property['default'], true) : (string) $property['default'])
            : '';
        if (isset($property['enum'])) {
            return new FieldOption($name, FieldOption::KIND_ENUM, $description, $default, array_values($property['enum']));
        }
        if ($types === ['boolean']) {
            return new FieldOption($name, FieldOption::KIND_BOOLEAN, $description, $default, ['true', 'false']);
        }
        if ($types === ['integer'] || $types === ['number']) {
            $minimum = isset($property['minimum']) ? (int) $property['minimum'] : null;
            $maximum = isset($property['maximum']) ? (int) $property['maximum'] : null;
            return new FieldOption($name, $types[0], $description, $default, [], $minimum, $maximum);
        }
        if ($types === ['array']) {
            if (is_array($property['items']['enum'] ?? null)) {
                return new FieldOption($name, FieldOption::KIND_MULTI_ENUM, $description, '', array_values($property['items']['enum']));
            }
            return in_array($property['items']['type'] ?? null, ['string', 'integer'], true)
                ? new FieldOption($name, FieldOption::KIND_LIST, $description)
                : null;
        }
        // "string", or several scalar types such as string|integer: entered as text.
        if ($types !== [] && array_diff($types, ['string', 'integer', 'number']) === []) {
            return new FieldOption($name, FieldOption::KIND_STRING, $description, $default, acceptsInteger: in_array('integer', $types, true));
        }
        return null;
    }

    /**
     * Follows a "$ref" to a place in the same schema, e.g. "#/allOf/1/properties/fields/items/...".
     */
    private function resolveReference(array $schema, array $property): array
    {
        $reference = $property['$ref'] ?? null;
        if (!is_string($reference) || !str_starts_with($reference, '#')) {
            return $property;
        }
        $resolved = JsonPointer::parse(substr($reference, 1))?->data($schema, null, []);
        return is_array($resolved) ? $resolved : [];
    }

    private function getSchema(ContentType $contentType): array
    {
        return $this->schemas[$contentType->value] ??= json_decode(
            (string) file_get_contents(ExtensionManagementUtility::extPath('content_blocks') . 'JsonSchema/' . $contentType->value . '.schema.json'),
            true,
        ) ?? [];
    }
}
