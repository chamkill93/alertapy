<ul class="record-list">
@forelse($items as $item)
    <li><a class="record-title" href="{{ route('admin.news.show', $item['id']) }}">{{ $item['title'] }}</a><div class="record-meta"><span>{{ $item['source']['name'] }}</span><span>Publicación: @include('admin.partials.date', ['value' => $item['published_at']])</span></div><div class="record-status">@include('admin.partials.badge', ['label' => $item['status_label'], 'tone' => $item['status_tone']])</div>@include('admin.partials.source-reference', ['item' => $item])</li>
@empty
    <li class="empty-state"><span class="empty-icon" data-app-icon="press" aria-hidden="true"></span><h3>Todavía no hay noticias</h3><p>Las publicaciones aparecerán después de una consulta de las fuentes.</p></li>
@endforelse
</ul>
