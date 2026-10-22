<?php

namespace App\Enums;

enum TemporalAccuracy: string
{
    case Exact = 'exact';
    case Approximate = 'approximate';
    case DateOnly = 'date_only';
    case RelativeParsed = 'relative_parsed';
    case Unknown = 'unknown';
}
