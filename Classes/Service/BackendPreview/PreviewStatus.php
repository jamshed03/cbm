<?php

declare(strict_types=1);

namespace AskoEducation\Cbm\Service\BackendPreview;

/**
 * State of a Content Block's backend preview before generating.
 */
enum PreviewStatus: string
{
    case Missing = 'missing';
    case Placeholder = 'placeholder';
    case Current = 'current';
    case Custom = 'custom';
    case Unsupported = 'unsupported';
}
