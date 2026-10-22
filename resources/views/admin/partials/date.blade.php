@if(!empty($value))
    <time datetime="{{ $value }}">{{ \Carbon\CarbonImmutable::parse($value)->setTimezone(config('app.timezone'))->format('d/m/Y H:i') }}</time>
@else
    <span class="muted">{{ $fallback ?? 'Sin registro' }}</span>
@endif
