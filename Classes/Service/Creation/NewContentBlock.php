<?php

declare(strict_types=1);

namespace AskoEducation\Cbm\Service\Creation;

use TYPO3\CMS\ContentBlocks\Definition\ContentType\ContentType;

/**
 * What the backend form asks for a new Content Block.
 */
final readonly class NewContentBlock
{
    /**
     * @param list<NewField> $fields
     * @param bool $confirmed when editing: the warnings about renamed, retyped or removed fields were confirmed
     */
    public function __construct(
        public ContentType $contentType = ContentType::CONTENT_ELEMENT,
        public string $vendor = '',
        public string $name = '',
        public string $title = '',
        public string $description = '',
        public string $group = 'default',
        public string $typeName = '',
        public string $extension = '',
        public array $fields = [],
        public bool $confirmed = false,
    ) {
    }

    public static function fromFormData(array $data): self
    {
        return new self(
            contentType: ContentType::tryFrom((string) ($data['contentType'] ?? '')) ?? ContentType::CONTENT_ELEMENT,
            vendor: strtolower(trim((string) ($data['vendor'] ?? ''))),
            name: strtolower(trim((string) ($data['name'] ?? ''))),
            title: trim((string) ($data['title'] ?? '')),
            description: trim((string) ($data['description'] ?? '')),
            group: trim((string) ($data['group'] ?? '')) ?: 'default',
            typeName: trim((string) ($data['typeName'] ?? '')),
            extension: (string) ($data['extension'] ?? ''),
            fields: array_values(array_map(NewField::fromFormData(...), array_filter((array) ($data['fields'] ?? []), 'is_array'))),
            confirmed: (bool) ($data['confirmed'] ?? false),
        );
    }

    public function getFullName(): string
    {
        return $this->vendor . '/' . $this->name;
    }
}
