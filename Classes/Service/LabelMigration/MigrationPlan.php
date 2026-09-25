<?php

declare(strict_types=1);

namespace AskoEducation\Cbm\Service\LabelMigration;

/**
 * What migrating one Content Block would change. Nothing is written until it is applied.
 */
final readonly class MigrationPlan
{
    /**
     * @param string $contentBlockPath absolute path of the Content Block folder
     * @param ?string $newXlf null if labels.xlf stays as it is
     * @param ?string $newYaml null if config.yaml stays as it is
     * @param array<string, string> $addedKeys keys that are new in labels.xlf
     * @param array<string, array{yaml: string, xlf: string, kept: string}> $conflicts by language key
     * @param list<LabelLine> $removed
     * @param list<LabelLine> $skipped
     * @param ?string $error why config.yaml is left untouched although it has labels
     */
    public function __construct(
        public string $contentBlock,
        public string $contentBlockPath,
        public ?string $newXlf,
        public ?string $newYaml,
        public array $addedKeys = [],
        public array $conflicts = [],
        public array $removed = [],
        public array $skipped = [],
        public ?string $error = null,
    ) {}

    public function hasChanges(): bool
    {
        return $this->newXlf !== null || $this->newYaml !== null;
    }

    /**
     * Whether labels are still in config.yaml (or in conflict with labels.xlf): editing labels.xlf before migrating
     * them would hide them for good. Labels that cannot be removed from config.yaml ($skipped) do not count.
     */
    public function isPending(): bool
    {
        return $this->hasChanges() || $this->conflicts !== [] || $this->error !== null;
    }

    public function hasFindings(): bool
    {
        return $this->conflicts !== [] || $this->skipped !== [] || $this->error !== null;
    }
}
