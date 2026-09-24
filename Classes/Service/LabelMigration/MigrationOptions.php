<?php

declare(strict_types=1);

namespace AskoEducation\Cbm\Service\LabelMigration;

final readonly class MigrationOptions
{
    public function __construct(
        public bool $keepYaml = false,
        public Prefer $prefer = Prefer::Xlf,
    ) {}
}
