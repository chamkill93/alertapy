<?php

namespace App\Exceptions;

use RuntimeException;

class SourceAccessBlockedException extends RuntimeException
{
    public static function antiBot(string $sourceName): self
    {
        return new self("{$sourceName} respondió con una verificación anti-bot; no se intentó evadirla.");
    }
}
