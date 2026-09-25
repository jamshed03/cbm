<?php

declare(strict_types=1);

namespace AskoEducation\Cbm\Service\LabelEditor;

/**
 * One label of a Content Block's labels.xlf.
 */
final readonly class LabelEntry
{
    /**
     * @param bool $field the label of a field in config.yaml: Content Blocks writes it into labels.xlf
     *                    by itself, so it can be changed but not deleted
     * @param bool $optional a description: it may stay empty ($value is '' while labels.xlf has none)
     */
    public function __construct(
        public string $key,
        public string $value,
        public bool $field,
        public bool $optional,
    ) {
    }
}
