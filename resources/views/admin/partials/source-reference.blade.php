@if($item['original_url'])
    <a class="source-reference" href="{{ $item['original_url'] }}" target="_blank" rel="noopener noreferrer" aria-label="Ver publicación original de {{ $item['source']['name'] }}: {{ $item['title'] }}"><span data-app-icon="external" aria-hidden="true"></span>Ver publicación original</a>
    @if($showUrl ?? false)<span class="source-url">{{ $item['original_url'] }}</span>@endif
@else
    <span class="source-reference-unavailable">Referencia original no disponible</span>
@endif
