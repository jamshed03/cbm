<?php

declare(strict_types=1);

namespace AskoEducation\Cbm\Service\BackendPreview;

/**
 * What generating the backend preview of one Content Block would change. Nothing is written until it is applied.
 */
final readonly class PreviewPlan
{
    /**
     * @param string $path absolute path of the (existing or new) backend preview template
     * @param ?string $newContent null if the template stays as it is
     */
    public function __construct(
        public string $contentBlock,
        public PreviewStatus $status,
        public string $path,
        public ?string $newContent,
    ) {}
}
