<?php

namespace App\Enums;

enum SourceRole: string
{
    case Origin = 'origin';
    case Primary = 'primary';
    case Secondary = 'secondary';
    case Corroborating = 'corroborating';
    case Update = 'update';
    case Discovery = 'discovery';
    case Community = 'community';
}
