<?php

declare(strict_types=1);

namespace AskoEducation\Cbm\Service\LabelMigration;

final readonly class StrippedYaml
{
    /**
     * @param list<LabelLine> $removed
     * @param list<LabelLine> $skipped labels that could not be removed without emptying their list item
     */
    public function __construct(public string $content, public array $removed, public array $skipped)
    {
    }
}
