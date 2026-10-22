<?php

namespace App\Collectors;

use App\Contracts\Collectors\CollectorInterface;
use App\DTOs\CandidateDTO;
use App\Exceptions\SourceAccessBlockedException;
use App\Models\Source;
use App\Services\Collectors\AndeHtmlParser;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class AndeCollector implements CollectorInterface
{
    public function __construct(private readonly AndeHtmlParser $parser) {}

    public function collect(Source $source): Collection
    {
        $archivePath = trim((string) config('alertapy.sources.ande.browser_archive_path'));

        if ($archivePath !== '') {
            return $this->collectBrowserArchive($source, $archivePath);
        }

        $lookbackDays = (int) config('alertapy.sources.ande.lookback_days', 0);
        $maximumPages = $lookbackDays > 0
            ? max(1, (int) config('alertapy.sources.ande.max_pages', 5))
            : 1;
        $cutoff = $lookbackDays > 0
            ? CarbonImmutable::now((string) config('app.timezone'))->startOfDay()->subDays($lookbackDays)
            : null;
        $links = [];

        for ($page = 1; $page <= $maximumPages; $page++) {
            $response = $this->request($this->indexUrl($source->base_url, $page));
            $this->guardResponse($response, $source->name);
            $pageLinks = $this->parser->articleLinks($response->body(), 'https://www.ande.gov.py');
            $reachedCutoff = false;

            foreach ($pageLinks as $link) {
                $eventDate = $this->parser->eventDateFromTitle($link['title']);

                if ($cutoff !== null && $eventDate !== null && $eventDate->lt($cutoff)) {
                    $reachedCutoff = true;

                    continue;
                }

                $links[$link['id']] = $link;
            }

            if ($pageLinks === [] || $reachedCutoff || $lookbackDays === 0) {
                break;
            }
        }

        $links = array_slice(
            array_values($links),
            0,
            (int) config('alertapy.sources.ande.max_articles', 250),
        );

        if ($links === []) {
            throw new RuntimeException('ANDE no publicó enlaces de trabajos programados en el formato esperado.');
        }

        $candidates = collect();

        foreach ($links as $link) {
            $detail = $this->request($link['url']);
            $this->guardResponse($detail, $source->name);
            $candidates->push(...$this->parser->articleCandidates(
                $detail->body(),
                $link['url'],
                $link['id'],
                $link['title'],
            ));
        }

        return $candidates;
    }

    /** @return Collection<int, CandidateDTO> */
    private function collectBrowserArchive(Source $source, string $path): Collection
    {
        $allowedDirectory = realpath(storage_path('app/private'));
        $realPath = realpath($path);

        if ($allowedDirectory === false
            || $realPath === false
            || ! str_starts_with(
                strtolower($realPath),
                strtolower($allowedDirectory.DIRECTORY_SEPARATOR),
            )) {
            throw new RuntimeException('El archivo de ANDE debe existir dentro de storage/app/private.');
        }

        $payload = json_decode((string) file_get_contents($realPath), true);
        $items = is_array($payload) ? ($payload['items'] ?? null) : null;

        if (! is_array($items)) {
            throw new RuntimeException('El archivo capturado de ANDE no contiene una lista válida.');
        }

        $candidates = collect();

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $url = trim((string) ($item['href'] ?? ''));
            $title = trim((string) ($item['title'] ?? ''));
            $articleId = trim((string) ($item['id'] ?? ''));
            $html = (string) ($item['html'] ?? '');

            if ($url === ''
                || $title === ''
                || $html === ''
                || preg_match('/^\d+$/', $articleId) !== 1
                || strtolower((string) parse_url($url, PHP_URL_HOST)) !== 'www.ande.gov.py') {
                continue;
            }

            $candidates->push(...$this->parser->articleCandidates(
                $html,
                $url,
                $articleId,
                $title,
            ));
        }

        if ($candidates->isEmpty()) {
            throw new RuntimeException('El archivo capturado de ANDE no produjo zonas geolocalizables.');
        }

        return $candidates;
    }

    private function request(string $url): Response
    {
        return Http::accept('text/html, application/xhtml+xml;q=0.9')
            ->withUserAgent((string) config('alertapy.http.user_agent'))
            ->timeout((int) config('alertapy.http.timeout', 15))
            ->retry(2, 300)
            ->get($url);
    }

    private function indexUrl(string $baseUrl, int $page): string
    {
        if ($page === 1) {
            return $baseUrl;
        }

        return $baseUrl.(str_contains($baseUrl, '?') ? '&' : '?').'page='.$page;
    }

    private function guardResponse(Response $response, string $sourceName): void
    {
        if (! $response->successful()) {
            throw new RuntimeException("{$sourceName} respondió HTTP {$response->status()}.");
        }

        $body = $response->body();

        if (str_contains($body, 'Radware Page')
            || str_contains($body, 'Verifying your browser')
            || str_contains($body, 'stormcaster.js')) {
            throw SourceAccessBlockedException::antiBot($sourceName);
        }
    }
}
