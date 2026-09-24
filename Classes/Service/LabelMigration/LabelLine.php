<?php

declare(strict_types=1);

namespace AskoEducation\Cbm\Service\LabelMigration;

final readonly class LabelLine
{
    public function __construct(
        public int $index,
        public int $end,
        public int $keyIndent,
        public string $key,
        public string $value,
    ) {
    }

    public function lineNumber(): int
    {
        return $this->index + 1;
    }
}
