<?php

namespace Tests\Unit\Discovery;

use App\Discovery\BlueskyDiscoveryProvider;
use App\Discovery\GdeltDiscoveryProvider;
use App\Discovery\MastodonDiscoveryProvider;
use App\Discovery\TelegramChannelDiscoveryProvider;
use App\Discovery\XDiscoveryProvider;
use App\Discovery\YouTubeDiscoveryProvider;
use App\Enums\SourceType;
use App\Models\Source;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class DiscoveryProvidersTest extends TestCase
{
    use RefreshDatabase;

    public function test_gdelt_returns_the_original_article_and_never_itself_as_final_source(): void
    {
        Http::fake(['api.gdeltproject.org/*' => Http::response(['articles' => [[
            'url' => 'https://medio.example/incendio-asuncion',
            'title' => 'Incendio afecta un depósito en Asunción',
            'seendate' => '20260819T220000Z',
        ], [
            'url' => 'https://api.gdeltproject.org/result/1',
            'title' => 'Accidente en Paraguay',
        ]]])]);

        $items = app(GdeltDiscoveryProvider::class)->discover($this->source('gdelt', SourceType::Discovery));

        $this->assertCount(1, $items);
        $this->assertSame('medio.example', $items[0]->originalDomain);
        $this->assertStringNotContainsString('gdeltproject.org', $items[0]->originalUrl);
    }

    public function test_bluesky_parses_public_posts_as_unverified_social_discovery(): void
    {
        Http::fake(['public.api.bsky.app/*' => Http::response(['posts' => [[
            'uri' => 'at://did:plc:test/app.bsky.feed.post/abc123',
            'author' => ['handle' => 'alerta.test'],
            'record' => ['text' => 'Incendio reportado en Asunción', 'createdAt' => '2026-08-19T22:00:00Z'],
        ]]])]);

        $items = app(BlueskyDiscoveryProvider::class)->discover($this->source('bluesky', SourceType::Social));

        $this->assertCount(1, $items);
        $this->assertSame(SourceType::Social, $items[0]->sourceType);
        $this->assertSame('https://bsky.app/profile/alerta.test/post/abc123', $items[0]->originalUrl);
    }

    public function test_telegram_only_accepts_explicitly_configured_channels(): void
    {
        Cache::clear();
        config()->set('alertapy.discovery.telegram.bot_token', 'test-token');
        config()->set('alertapy.discovery.telegram.channels', ['canal_autorizado']);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => [[
            'update_id' => 10,
            'channel_post' => ['message_id' => 5, 'date' => 1787176800, 'text' => 'Ruta bloqueada en Asunción', 'chat' => ['username' => 'canal_autorizado']],
        ], [
            'update_id' => 11,
            'channel_post' => ['message_id' => 6, 'date' => 1787176800, 'text' => 'Incendio en Asunción', 'chat' => ['username' => 'otro_canal']],
        ]]])]);

        $items = app(TelegramChannelDiscoveryProvider::class)->discover($this->source('telegram', SourceType::Social));

        $this->assertCount(1, $items);
        $this->assertSame('https://t.me/canal_autorizado/5', $items[0]->originalUrl);
    }

    public function test_youtube_only_uses_configured_channels_and_does_not_transcribe_video(): void
    {
        config()->set('alertapy.discovery.youtube.api_key', 'test-key');
        config()->set('alertapy.discovery.youtube.channel_ids', ['channel-1']);
        Http::fake(['www.googleapis.com/youtube/v3/search*' => Http::response(['items' => [[
            'id' => ['videoId' => 'video-1'],
            'snippet' => ['title' => 'Accidente sobre ruta en Capiatá', 'description' => 'Reporte inicial', 'publishedAt' => '2026-08-19T21:00:00Z', 'channelTitle' => 'Canal PY'],
        ]]])]);

        $items = app(YouTubeDiscoveryProvider::class)->discover($this->source('youtube', SourceType::Discovery));

        $this->assertCount(1, $items);
        $this->assertSame('https://www.youtube.com/watch?v=video-1', $items[0]->originalUrl);
        $this->assertStringNotContainsString('transcript', strtolower($items[0]->content));
    }

    public function test_credential_and_instance_dependent_providers_fail_with_clear_configuration_errors(): void
    {
        config()->set('alertapy.discovery.x.bearer_token', '');
        config()->set('alertapy.discovery.mastodon.instances', []);

        try {
            app(XDiscoveryProvider::class)->discover($this->source('x-discovery', SourceType::Social));
            $this->fail('X debía requerir credenciales.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('X_BEARER_TOKEN', $exception->getMessage());
        }

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('MASTODON_INSTANCES');
        app(MastodonDiscoveryProvider::class)->discover($this->source('mastodon', SourceType::Social));
    }


    public function test_gdelt_queries_all_configured_groups_and_keeps_original_urls(): void
    {
        config()->set('alertapy.discovery.gdelt.query', null);
        config()->set('alertapy.discovery.gdelt.queries', [
            'sourcecountry:paraguay storm',
            'sourcecountry:paraguay animal-health',
        ]);

        Http::fake(function ($request) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $parameters);

            $articles = str_contains((string) ($parameters['query'] ?? ''), 'animal-health')
                ? [[
                    'url' => 'https://medio.example/alerta-aftosa-san-pedro',
                    'title' => 'Confirman fiebre aftosa en bovinos de San Pedro',
                    'seendate' => '20261023T120000Z',
                ], [
                    'url' => 'https://medio.example/temporal-pilar',
                    'title' => 'Temporal afecta viviendas de Pilar',
                    'seendate' => '20261023T110000Z',
                ]]
                : [[
                    'url' => 'https://medio.example/temporal-pilar',
                    'title' => 'Temporal afecta viviendas de Pilar',
                    'seendate' => '20261023T110000Z',
                ]];

            return Http::response(['articles' => $articles]);
        });

        $items = app(GdeltDiscoveryProvider::class)->discover($this->source('gdelt', SourceType::Discovery));

        $this->assertCount(2, $items);
        $this->assertSame([
            'https://medio.example/temporal-pilar',
            'https://medio.example/alerta-aftosa-san-pedro',
        ], $items->pluck('originalUrl')->all());
        Http::assertSentCount(2);
    }


    private function source(string $slug, SourceType $type): Source
    {
        return Source::query()->create([
            'name' => $slug,
            'slug' => $slug,
            'type' => $type,
            'base_url' => 'https://example.com/',
            'domain' => 'example.com',
            'enabled' => true,
            'polling_minutes' => 30,
        ]);
    }
}
