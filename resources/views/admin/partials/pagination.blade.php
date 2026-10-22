@if($paginator->hasPages())
    <nav class="pagination" aria-label="Paginación">
        <span>{{ $paginator->firstItem() }}–{{ $paginator->lastItem() }} de {{ number_format($paginator->total(), 0, ',', '.') }}</span>
        <div>@if($paginator->onFirstPage())<span class="button button-disabled"><span data-app-icon="arrow_left" aria-hidden="true"></span>Anterior</span>@else<a class="button button-secondary" href="{{ $paginator->previousPageUrl() }}" rel="prev"><span data-app-icon="arrow_left" aria-hidden="true"></span>Anterior</a>@endif<span class="page-number">{{ $paginator->currentPage() }} / {{ $paginator->lastPage() }}</span>@if($paginator->hasMorePages())<a class="button button-secondary" href="{{ $paginator->nextPageUrl() }}" rel="next">Siguiente <span data-app-icon="arrow_right" aria-hidden="true"></span></a>@else<span class="button button-disabled">Siguiente <span data-app-icon="arrow_right" aria-hidden="true"></span></span>@endif</div>
    </nav>
@elseif($paginator->total() > 0)
    <div class="pagination"><span>{{ number_format($paginator->total(), 0, ',', '.') }} resultados</span></div>
@endif
