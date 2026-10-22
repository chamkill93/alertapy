<?php

namespace App\Collectors;

use App\Contracts\Collectors\CollectorInterface;
use App\DTOs\CandidateDTO;
use App\Models\Source;
use App\Services\ParaguayLocationExtractor;
use Carbon\CarbonImmutable;
use DOMDocument;
use DOMXPath;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class MeteorologyAdvisoryCollector implements CollectorInterface
{
    public function __construct(private readonly ParaguayLocationExtractor $locationExtractor) {}

    /** @return Collection<int, CandidateDTO> */
    public function collect(Source $source): Collection
    {
        $url = (string) config('alertapy.sources.meteorologia.advisory_url');
        $response = Http::accept('text/html, application/xhtml+xml;q=0.9')
            ->withUserAgent((string) config('alertapy.http.user_agent'))
            ->timeout((int) config('alertapy.http.timeout', 15))
            ->retry(2, 300)
            ->get($url);

        if (! $response->successful()) {
            throw new RuntimeException("Meteorología respondió HTTP {$response->status()} al consultar sus avisos.");
        }

        $text = $this->plainText($response->body());

        if ($text === '' || Str::contains(Str::lower(Str::ascii($text)), 'no hay aviso meteorologico vigente')) {
            return collect();
        }

        $locations = $this->locationExtractor->extractAll($text);

        if ($locations === []) {
            return collect();
        }

        $publishedAt = $this->publishedAt($text);

        if ($publishedAt === null) {
            throw new RuntimeException('La estructura del aviso meteorológico no permite verificar una fecha de emisión válida.');
        }

        $cutoff = CarbonImmutable::now((string) config('app.timezone'))
            ->subHours((int) config('alertapy.sources.meteorologia.max_age_hours', 12));

        if ($publishedAt->lt($cutoff) || $publishedAt->isFuture()) {
            return collect();
        }

        return collect($locations)->map(function (array $location) use ($source, $url, $text, $publishedAt): CandidateDTO {
            $title = "Aviso meteorológico vigente para {$location['text']}";

            return new CandidateDTO(
                sourceSlug: $source->slug,
                sourceType: $source->type,
                externalIdentifier: 'meteorologia-'.hash('sha256', $publishedAt->toIso8601String().'|'.$location['text'].'|'.$text),
                title: $title,
                originalUrl: $url,
                content: Str::limit($text, 4000, '…'),
                publishedAt: $publishedAt,
                detectedLocation: $location['query'],
                metadata: [
                    'category_slug' => 'weather_flood',
                    'location_text' => $location['text'],
                    'city' => $location['city'],
                    'department' => $location['department'],
                    'location_accuracy' => $location['accuracy'],
                    'original_title' => $title,
                    'official_advisory' => true,
                    'ends_at' => $publishedAt->addHours(6)->toIso8601String(),
                ],
            );
        });
    }

    private function plainText(string $html): string
    {
        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);

        try {
            $document->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        $xpath = new DOMXPath($document);
        foreach ($xpath->query('//script | //style | //noscript | //template | //comment()') ?: [] as $node) {
            $node->parentNode?->removeChild($node);
        }

        $content = $xpath->query('//article//*[contains(concat(" ", normalize-space(@class), " "), " entry-content ")]')->item(0)
            ?? $xpath->query('//article')->item(0)
            ?? $xpath->query('//main')->item(0)
            ?? $xpath->query('//body')->item(0)
            ?? $document;
        $text = $content->textContent;

        return trim(preg_replace('/\s+/u', ' ', $text) ?: $text);
    }

    private function publishedAt(string $text): ?CarbonImmutable
    {
        if (preg_match('/(?:fecha\s*[:\-]?\s*)?(\d{1,2})[\/\-](\d{1,2})[\/\-](\d{4}|\d{2})\b.{0,40}?(?:hora\s*[:\-]?\s*)?(\d{1,2}):(\d{2})(?::(\d{2}))?/iu', $text, $match) !== 1) {
            return null;
        }

        $day = (int) $match[1];
        $month = (int) $match[2];
        $year = strlen($match[3]) === 2 ? 2000 + (int) $match[3] : (int) $match[3];
        $hour = (int) $match[4];
        $minute = (int) $match[5];
        $second = isset($match[6]) ? (int) $match[6] : 0;

        if (! checkdate($month, $day, $year) || $hour > 23 || $minute > 59 || $second > 59) {
            return null;
        }

        try {
            return CarbonImmutable::createFromFormat(
                '!d/m/Y H:i:s',
                sprintf('%02d/%02d/%04d %02d:%02d:%02d', $day, $month, $year, $hour, $minute, $second),
                (string) config('app.timezone'),
            ) ?: null;
        } catch (\Throwable) {
            return null;
        }
    }
}
