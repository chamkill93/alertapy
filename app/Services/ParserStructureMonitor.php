<?php

namespace App\Services;

use App\Models\Source;
use App\Models\SourceParserSnapshot;

class ParserStructureMonitor
{
    public function observe(Source $source, string $content, int $elementCount, string $parserVersion): SourceParserSnapshot
    {
        $signature = hash('sha256', preg_replace('/[\pL\pN]+/u', '#', strip_tags($content)) ?: '');
        $previous = $source->parserSnapshots()->latest('observed_at')->first();
        $changed = $previous !== null && (($previous->element_count >= 3 && $elementCount === 0) || ($previous->structure_signature !== $signature && abs($previous->content_length - strlen($content)) > max(500, $previous->content_length * .7)));
        $snapshot = $source->parserSnapshots()->create(['element_count' => $elementCount, 'content_length' => strlen($content), 'structure_signature' => $signature, 'page_hash' => hash('sha256', $content), 'parser_version' => $parserVersion, 'structure_changed' => $changed, 'observed_at' => now()]);
        if ($changed) $source->forceFill(['last_error' => 'PARSER_STRUCTURE_CHANGED', 'consecutive_errors' => max(1, $source->consecutive_errors)])->save();
        return $snapshot;
    }
}
