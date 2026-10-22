<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="theme-color" content="#102d36">
    <title>@yield('title', 'Backoffice') · AlertaPY</title>
    <link rel="stylesheet" href="{{ asset('css/alertapy-admin.css') }}?v={{ filemtime(public_path('css/alertapy-admin.css')) }}">
    <link rel="stylesheet" href="{{ asset('css/alertapy-brand.css') }}?v={{ filemtime(public_path('css/alertapy-brand.css')) }}">
    <script defer src="{{ asset('js/alertapy-icons.js') }}?v={{ filemtime(public_path('js/alertapy-icons.js')) }}"></script>

<link rel="icon" type="image/svg+xml" href="{{ asset('favicon.svg') }}"></head>
<body>
    <a class="skip-link" href="#admin-main">Saltar al contenido</a>
    <div class="admin-shell">
        <aside class="admin-sidebar" aria-label="Navegación del backoffice">
            <a class="admin-brand" href="{{ route('admin.dashboard') }}">@include('components.brand')<small>Backoffice</small></a>
            <div class="sidebar-caption">GESTIÓN DEL MONITOREO</div>
            <nav class="admin-nav">
                <a href="{{ route('admin.dashboard') }}" @if(request()->routeIs('admin.dashboard')) aria-current="page" @endif><span class="nav-icon" data-app-icon="dashboard" aria-hidden="true"></span>Resumen</a>
                <a href="{{ route('admin.news.index') }}" @if(request()->routeIs('admin.news.*')) aria-current="page" @endif><span class="nav-icon" data-app-icon="press" aria-hidden="true"></span>Noticias</a>
                <a href="{{ route('admin.connections.index') }}" @if(request()->routeIs('admin.connections.*')) aria-current="page" @endif><span class="nav-icon" data-app-icon="connections" aria-hidden="true"></span>Conexiones</a>
            </nav>
            <div class="sidebar-bottom"><span class="private-label"><span data-app-icon="lock" aria-hidden="true"></span> Área privada</span><a class="map-link" href="{{ route('map') }}">Volver al mapa <span data-app-icon="external" aria-hidden="true"></span></a></div>
        </aside>
        <div class="admin-content">
            <header class="admin-topbar"><span class="workspace-label">Centro de operaciones <span>/ @yield('section', 'Resumen')</span></span><div class="account"><span>{{ auth()->user()?->name }}</span><form method="post" action="{{ route('admin.logout') }}">@csrf<button class="button button-quiet" type="submit"><span data-app-icon="logout" aria-hidden="true"></span>Cerrar sesión</button></form></div></header>
            <main id="admin-main" class="admin-main" tabindex="-1">
                <div class="page-heading"><div><span class="eyebrow">ALERTAPY · BACKOFFICE</span><h1>@yield('heading', 'Resumen')</h1><p>@yield('description')</p></div><div class="heading-actions">@yield('actions')<a class="button button-secondary" href="{{ request()->fullUrl() }}"><span data-app-icon="refresh" aria-hidden="true"></span>Actualizar</a></div></div>
                @if(session('status'))<div class="notice notice-success" role="status">{{ session('status') }}</div>@endif
                @if($errors->any())<div class="notice notice-error" role="alert"><strong>Revisá los datos ingresados.</strong><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
                @yield('content')
                <footer class="admin-footer"><span>Información al {{ now()->timezone(config('app.timezone'))->format('d/m/Y H:i') }} · Hora de Paraguay</span><span>AlertaPY</span></footer>
            </main>
        </div>
    </div>
</body>
</html>
