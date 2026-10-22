@extends('admin.layout')
@section('title', 'Resumen')
@section('section', 'Resumen')
@section('heading', 'El pulso de AlertaPY')
@section('description', 'Una vista del ingreso de noticias, las publicaciones y el funcionamiento de las fuentes.')
@section('content')
<div class="metric-grid">
    <div class="metric"><span class="metric-label">Noticias recibidas</span><strong class="metric-value">{{ number_format($summary['received_24h'], 0, ',', '.') }}</strong><span class="metric-note">Detectadas en las últimas 24 horas</span></div>
    <div class="metric metric-accent"><span class="metric-label">Incidencias publicadas</span><strong class="metric-value">{{ number_format($summary['published_24h'], 0, ',', '.') }}</strong><span class="metric-note">Con publicación en las últimas 24 horas</span></div>
    <div class="metric metric-warning"><span class="metric-label">Pendientes de publicación</span><strong class="metric-value">{{ number_format($summary['pending'], 0, ',', '.') }}</strong><a class="metric-note" href="{{ route('admin.news.index') }}">Revisar noticias <span data-app-icon="arrow_right" aria-hidden="true"></span></a></div>
    <div class="metric"><span class="metric-label">Fuentes habilitadas</span><strong class="metric-value">{{ $summary['sources_enabled'] }}</strong><a class="metric-note" href="{{ route('admin.connections.index') }}">{{ $summary['sources_problematic'] }} necesitan atención <span data-app-icon="arrow_right" aria-hidden="true"></span></a></div>
</div>
@include('admin.partials.scheduler')
<div class="dashboard-grid">
    <section class="panel"><div class="panel-heading"><div><h2>Últimas noticias detectadas</h2><p>El ingreso más reciente, con su estado de publicación.</p></div><a href="{{ route('admin.news.index') }}">Ver todas <span data-app-icon="arrow_right" aria-hidden="true"></span></a></div>@include('admin.partials.news-list', ['items' => $latestNews])</section>
    <section class="panel"><div class="panel-heading"><div><h2>Conexiones a revisar</h2><p>Fuentes habilitadas que necesitan atención.</p></div></div><ul class="record-list">@forelse($attentionConnections as $connection)<li><div class="connection-mini"><div><a class="record-title" href="{{ route('admin.connections.show', $connection['id']) }}">{{ $connection['name'] }}</a><p>{{ $connection['reason'] ?? 'Revisar el resultado de la última consulta.' }}</p><small>Último éxito: @include('admin.partials.date', ['value' => $connection['last_success_at']])</small></div>@include('admin.partials.badge', ['label' => $connection['health_label'], 'tone' => $connection['health_tone']])</div></li>@empty<li class="empty-state"><span class="empty-icon" data-app-icon="check" aria-hidden="true"></span><h3>Sin alertas de conexión</h3><p>Las fuentes habilitadas no presentan problemas registrados.</p></li>@endforelse</ul><div class="panel-body"><a class="button button-secondary" href="{{ route('admin.connections.index') }}">Ver todas las conexiones <span data-app-icon="arrow_right" aria-hidden="true"></span></a></div></section>
</div>
@endsection
