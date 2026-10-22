<?php

namespace App\Discovery;

use App\Contracts\Discovery\DiscoveryProviderInterface;
use App\Discovery\Concerns\FiltersTerritorialDiscovery;
use App\DTOs\DiscoveryItemDTO;
use App\Enums\SourceType;
use App\Models\Source;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class TelegramChannelDiscoveryProvider implements DiscoveryProviderInterface
{
    use FiltersTerritorialDiscovery;

    public function discover(Source $source): Collection
    {
        $settings = (array) config('alertapy.discovery.telegram');
        $token = trim((string) ($settings['bot_token'] ?? ''));
        $channels = collect((array) ($settings['channels'] ?? []))->map(fn ($channel): string => ltrim(strtolower(trim((string) $channel)), '@'));

        if ($token === '' || $channels->isEmpty()) {
            throw new RuntimeException('TELEGRAM_BOT_TOKEN y TELEGRAM_CHANNELS deben estar configurados.');
        }

        $offset = (int) Cache::get('alertapy:telegram:last-update-id', 0);
        $response = Http::acceptJson()->timeout((int) config('alertapy.http.timeout', 15))
            ->retry(1, 250, throw: false)
            ->post("https://api.telegram.org/bot{$token}/getUpdates", [
                'offset' => $offset > 0 ? $offset + 1 : null,
                'limit' => 100,
                'timeout' => 0,
                'allowed_updates' => ['channel_post', 'edited_channel_post'],
            ]);

        if (! $response->successful() || $response->json('ok') !== true) {
            throw new RuntimeException("Telegram respondió HTTP {$response->status()}.");
        }

        $updates = collect($response->json('result', []));
        $latest = (int) $updates->max('update_id');

        if ($latest > 0) {
            Cache::forever('alertapy:telegram:last-update-id', $latest);
        }

        return $updates->map(function (mixed $update) use ($channels): ?DiscoveryItemDTO {
            $post = is_array($update) ? ($update['channel_post'] ?? $update['edited_channel_post'] ?? null) : null;
            $username = is_array($post) ? ltrim(strtolower((string) data_get($post, 'chat.username')), '@') : '';
            $text = is_array($post) ? trim((string) ($post['text'] ?? $post['caption'] ?? '')) : '';
            $messageId = is_array($post) ? (string) ($post['message_id'] ?? '') : '';

            if ($username === '' || ! $channels->contains($username) || $messageId === '' || ! $this->isRelevant($text)) {
                return null;
            }

            return new DiscoveryItemDTO(
                externalIdentifier: "{$username}:{$messageId}",
                title: str($text)->squish()->limit(220)->toString(),
                originalUrl: "https://t.me/{$username}/{$messageId}",
                content: $text,
                publishedAt: isset($post['date']) ? CarbonImmutable::createFromTimestampUTC((int) $post['date'])->setTimezone((string) config('app.timezone')) : null,
                sourceType: SourceType::Social,
                author: $username,
                originalDomain: 't.me',
            );
        })->filter()->values();
    }
}
