<?php

declare(strict_types=1);

namespace AskoEducation\Cbm\Service\LabelMigration;

/**
 * Which value wins when config.yaml and labels.xlf define the same label differently.
 */
enum Prefer: string
{
    case Xlf = 'xlf';
    case Yaml = 'yaml';
}
