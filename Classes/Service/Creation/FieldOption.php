<?php

declare(strict_types=1);

namespace AskoEducation\Cbm\Service\Creation;

/**
 * An option of a field type, read from Content Blocks' JSON schema, as the create form shows it.
 */
final readonly class FieldOption
{
    public const KIND_BOOLEAN = 'boolean';
    public const KIND_INTEGER = 'integer';
    public const KIND_NUMBER = 'number';
    public const KIND_STRING = 'string';
    public const KIND_ENUM = 'enum';
    public const KIND_MULTI_ENUM = 'multiEnum';
    public const KIND_LIST = 'list';

    /**
     * @param string $name the option key, "parent.child" for an option inside an object option (e.g. "range.lower")
     * @param list<string|int> $values the values to choose from (enum kinds; "true" and "false" for booleans)
     * @param bool $acceptsInteger a string option that may also be an integer, e.g. "default" of a Select
     */
    public function __construct(
        public string $name,
        public string $kind,
        public string $description = '',
        public string $default = '',
        public array $values = [],
        public ?int $minimum = null,
        public ?int $maximum = null,
        public bool $acceptsInteger = false,
    ) {
    }

    /**
     * The form input name below fields[i][options], e.g. "range][lower" for "range.lower".
     */
    public function getInputName(): string
    {
        return str_replace('.', '][', $this->name);
    }
}
