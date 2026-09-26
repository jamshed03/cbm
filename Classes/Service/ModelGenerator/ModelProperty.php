<?php

declare(strict_types=1);

namespace AskoEducation\Cbm\Service\ModelGenerator;

use TYPO3\CMS\Extbase\Persistence\ObjectStorage;

/**
 * A property of a generated Extbase model: a scalar, a single object or an ObjectStorage of objects.
 */
final readonly class ModelProperty
{
    /**
     * @param string $column the database column, e.g. "portfolio_hero_kicker"
     * @param string $type the PHP type, e.g. "string", "?\DateTime" or "ObjectStorage"
     * @param string $default PHP code of the default value; '' for ObjectStorage (set in the constructor)
     * @param ?string $class fully qualified class name of the object(s), null for scalars
     */
    private function __construct(
        public string $name,
        public string $column,
        public string $type,
        public string $default,
        public string $comment = '',
        public ?string $class = null,
        public bool $isObjectStorage = false,
    ) {
    }

    public static function scalar(string $name, string $column, string $type, string $default, string $comment = ''): self
    {
        return new self($name, $column, $type, $default, $comment);
    }

    public static function object(string $name, string $column, string $class): self
    {
        return new self($name, $column, '?' . self::shortName($class), 'null', class: $class);
    }

    public static function storage(string $name, string $column, string $class): self
    {
        return new self($name, $column, 'ObjectStorage', '', class: $class, isObjectStorage: true);
    }

    /**
     * @return list<string> fully qualified class names the type needs
     */
    public function getImports(): array
    {
        return match (true) {
            $this->class === null => [],
            $this->isObjectStorage => [ObjectStorage::class, $this->class],
            default => [$this->class],
        };
    }

    /**
     * Short class name of the objects in an ObjectStorage.
     */
    public function getStorageOf(): string
    {
        return self::shortName((string) $this->class);
    }

    private static function shortName(string $className): string
    {
        $parts = explode('\\', $className);
        return end($parts);
    }
}
