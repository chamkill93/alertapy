<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Cobertura — AlertaPY</title>
    <style>
        :root{font-family:Inter,system-ui,sans-serif;color:#102d2a;background:#f2f6f5}body{margin:0;padding:2rem}main{max-width:1200px;margin:auto}h1{margin-bottom:.25rem}p{color:#536865}section{background:white;border:1px solid #d9e4e1;border-radius:16px;padding:1rem;margin:1rem 0;overflow:auto}table{width:100%;border-collapse:collapse;font-size:.86rem}th,td{text-align:left;padding:.65rem;border-bottom:1px solid #e7eeec}th{color:#46615d}.pill{display:inline-block;padding:.18rem .5rem;border-radius:999px;font-weight:800}.high,.HEALTHY{background:#d9f5e9;color:#08745e}.medium,.DEGRADED,.STALE{background:#fff1ca;color:#8a5b00}.low,.FAILING,.FAILED,.MISSING{background:#ffe0e0;color:#a31d2f}.DISABLED{background:#e9edec;color:#56615f}.metrics{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:.75rem}.metric{background:#f5f9f8;border-radius:12px;padding:.8rem}.metric strong{display:block;font-size:1.45rem}.notice{border-left:4px solid #0877e8;padding-left:.75rem}
    </style>
</head>
<body><main>
    <h1>Cobertura territorial</h1>
    <p><a href="{{ route('admin.roads.index') }}">Administración → Cartografía → Red vial</a></p>
    <p class="notice">Índice de capacidad de monitoreo. No representa el porcentaje de todos los acontecimientos reales detectados.</p>
    <section><h2>Estado operativo</h2><div class="metrics"><div class="metric">Incidencias activas<strong>{{ $dashboard['active_incidents'] }}</strong></div><div class="metric">Scheduler<strong><span class="pill {{ $scheduler['status'] }}">{{ $scheduler['status'] }}</span></strong><small>{{ $scheduler['last_cycle']?->finished_at?->diffForHumans() ?? 'Sin ciclos registrados' }}</small></div><div class="metric">Ubicaciones de baja calidad<strong>{{ $dashboard['low_quality_locations'] }}</strong></div><div class="metric">Sin hora de ocurrencia<strong>{{ $dashboard['missing_occurred_at'] }}</strong></div><div class="metric">Resueltas/vencidas<strong>{{ $dashboard['stale_incidents'] }}</strong></div></div></section>
    <section><table><thead><tr><th>Departamento</th><th>Oficiales</th><th>Prensa nacional</th><th>Prensa regional</th><th>Municipalidad</th><th>Sensores</th><th>Capacidad</th><th>Nivel</th></tr></thead><tbody>
    @foreach($coverage as $row)<tr><td>{{ $row['department'] }}</td><td>{{ $row['official'] }}</td><td>{{ $row['national_press'] }}</td><td>{{ $row['regional_press'] }}</td><td>{{ $row['municipality'] }}</td><td>{{ $row['sensors'] }}</td><td title="Oficial {{ $row['components']['official'] }}, Prensa {{ $row['components']['press'] }}, Sensores {{ $row['components']['sensors'] }}, Infraestructura {{ $row['components']['infrastructure'] }}, Actualización {{ $row['components']['update'] }}">{{ $row['monitoring_capacity'] }}/100</td><td><span class="pill {{ $row['level'] }}">{{ strtoupper($row['level']) }}</span></td></tr>@endforeach
    </tbody></table></section>
    <h2>Calidad geográfica</h2>
    <section><table><thead><tr><th>Incidente</th><th>Categoría</th><th>Ubicación</th><th>Método</th><th>Calidad</th><th>Flags</th><th>Fuente</th><th>Fecha</th></tr></thead><tbody>
    @forelse($geographicIssues as $incident)<tr><td>{{ $incident->title }}</td><td>{{ $incident->category->name }}</td><td>{{ $incident->location_text }}</td><td>{{ $incident->location_method?->value ?? 'unknown' }}</td><td>{{ $incident->location_quality?->value ?? 'unknown' }}</td><td>{{ implode(', ', $incident->location_quality_flags ?? []) ?: '—' }}</td><td>{{ $incident->sources->first()?->source?->name ?? $incident->source_type->label() }}</td><td>{{ $incident->published_at?->format('d/m/Y H:i') ?? '—' }}</td></tr>@empty<tr><td colspan="8">No se detectaron incidencias geográficas pendientes.</td></tr>@endforelse
    </tbody></table></section>
    <h2>Salud de conectores</h2>
    <section><table><thead><tr><th>Fuente</th><th>Estado</th><th>Último intento</th><th>Último éxito</th><th>Último cambio</th><th>HTTP</th><th>Items</th><th>Duración</th><th>Errores</th><th>Próxima ejecución</th></tr></thead><tbody>
    @foreach($sources as $row)
        @php($source = $row['source']) @php($health = $row['health'])
        <tr><td>{{ $source->name }}</td><td><span class="pill {{ $health['status'] }}">{{ $health['status'] }}</span><br><small>{{ $health['reason'] }}</small></td><td>{{ $source->last_run_at?->diffForHumans() ?? 'Nunca' }}</td><td>{{ $source->last_success_at?->diffForHumans() ?? 'Nunca' }}</td><td>{{ $source->last_change_at?->diffForHumans() ?? 'Nunca' }}</td><td>{{ $health['http_status'] ?? '—' }}</td><td>{{ $health['items'] ?? 0 }}</td><td>{{ isset($health['duration_ms']) ? $health['duration_ms'].' ms' : '—' }}</td><td>{{ $source->consecutive_errors }}</td><td>{{ $health['next_run_at']?->diffForHumans() ?? '—' }}</td></tr>
    @endforeach
    </tbody></table></section>
</main></body></html>
