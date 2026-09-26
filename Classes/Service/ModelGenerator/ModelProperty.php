<?php

declare(strict_types=1);

namespace AskoEducation\Cbm\Service\ModelGenerator;

/**
 * A property of a generated Extbase model.
 */
final readonly class ModelProperty
{
    /**
     * @param string $column the database column, e.g. "portfolio_hero_kicker"
     * @param string $type the PHP type, e.g. "string", "?\DateTime" or "ObjectStorage"
     * @param string $default PHP code of the default value; '' for ObjectStorage (set in the constructor)
     * @param string $storageOf class name of the objects in an ObjectStorage
     * @param list<string> $imports fully qualified class names the type needs
     */
    public function __construct(
        public string $name,
        public string $column,
        public string $type,
        public string $default = '',
        public string $storageOf = '',
        public array $imports = [],
        public string $comment = '',
    ) {
    }

    public function isObjectStorage(): bool
    {
        return $this->type === 'ObjectStorage';
    }
}
