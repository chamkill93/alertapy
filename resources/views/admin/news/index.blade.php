@extends('admin.layout')
@section('title', 'Noticias')
@section('section', 'Noticias')
@section('heading', 'Noticias y publicaciones')
@section('description', 'Lo que detectaron las fuentes en los últimos 7 días, con su referencia original y estado de publicación.')
@section('content')
<section class="panel" aria-label="Noticias recolectadas">
    <div class="panel-heading"><div><h2>Publicaciones detectadas</h2><p>{{ number_format($news->total(), 0, ',', '.') }} resultados con los filtros actuales</p></div></div>
    <form class="filters-form" method="get" action="{{ route('admin.news.index') }}">
        <label class="field">Buscar noticia<input type="search" name="q" value="{{ $filters['q'] }}" placeholder="Título o texto de la publicación" maxlength="200"></label>
        <label class="field">Fuente<select name="source"><option value="">Todas las fuentes</option>@foreach($sources as $source)<option value="{{ $source['slug'] }}" @selected($filters['source'] === $source['slug'])>{{ $source['name'] }}</option>@endforeach</select></label>
        <label class="field">Estado<select name="status"><option value="">Todos los estados</option>@foreach($statusOptions as $value => $label)<option value="{{ $value }}" @selected($filters['status'] === $value)>{{ $label }}</option>@endforeach</select></label>
        <label class="field">Categoría publicada<select name="category"><option value="">Todas las categorías</option>@foreach($categories as $category)<option value="{{ $category['slug'] }}" @selected($filters['category'] === $category['slug'])>{{ $category['name'] }}</option>@endforeach</select></label>
        <div class="filter-actions"><button class="button button-primary" type="submit"><span data-app-icon="filters" aria-hidden="true"></span>Filtrar</button><a class="filter-reset" href="{{ route('admin.news.index') }}">Limpiar</a></div>
    </form>
    @if($news->count())
        <div class="table-scroll"><table class="news-table responsive-table"><thead><tr><th scope="col">Noticia</th><th scope="col">Fuente y referencia</th><th scope="col">Estado</th><th scope="col">Publicación</th></tr></thead>
            <tbody>@foreach($news as $item)
                <tr>
                    <td data-label="Noticia"><a class="record-title" href="{{ route('admin.news.show', $item['id']) }}">{{ $item['title'] }}</a><span class="cell-secondary">Detección: @include('admin.partials.date', ['value' => $item['detected_at']])</span>@if($item['incident_count'])<span class="cell-secondary">{{ $item['incident_count'] }} {{ $item['incident_count'] === 1 ? 'incidencia asociada' : 'incidencias asociadas' }}</span>@endif</td>
                    <td data-label="Fuente y referencia"><a href="{{ route('admin.news.index', ['source' => $item['source']['slug']]) }}">{{ $item['source']['name'] }}</a>@include('admin.partials.source-reference', ['item' => $item])</td>
                    <td data-label="Estado">@include('admin.partials.badge', ['label' => $item['status_label'], 'tone' => $item['status_tone']])</td>
                    <td data-label="Publicación">@include('admin.partials.date', ['value' => $item['published_at'], 'fallback' => 'Sin fecha de publicación'])</td>
                </tr>
            @endforeach</tbody>
        </table></div>
    @else
        <div class="empty-state"><span class="empty-icon" data-app-icon="press" aria-hidden="true"></span><h3>No hay noticias con estos filtros</h3><p>Probá otra búsqueda o quitá los filtros para ver todas las publicaciones detectadas.</p></div>
    @endif
    @include('admin.partials.pagination', ['paginator' => $news])
</section>
@endsection
