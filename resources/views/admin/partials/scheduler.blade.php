@php
    $cycle = $scheduler['last_cycle'] ?? null;
    $cycleTone = match($scheduler['status'] ?? 'MISSING') { 'HEALTHY' => 'success', 'RUNNING' => 'info', 'FAILED' => 'danger', default => 'warning' };
@endphp
<section class="cycle-banner" aria-label="Estado de la actualización automática"><div class="cycle-icon" data-app-icon="refresh" aria-hidden="true"></div><div class="cycle-copy"><h2>Actualización automática</h2><p>{{ $scheduler['label'] ?? 'Sin información del ciclo' }}@if($cycle) · Último inicio: @include('admin.partials.date', ['value' => $cycle['started_at'] ?? null])@endif</p></div>@include('admin.partials.badge', ['label' => $scheduler['label'] ?? 'Sin registro', 'tone' => $cycleTone])@if($cycle)<div class="cycle-stats"><strong>{{ $cycle['sources_processed'] ?? 0 }}</strong><span>fuentes en la última pasada</span></div>@endif</section>
