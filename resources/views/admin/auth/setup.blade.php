@extends('admin.auth.layout')
@section('title', 'Configurar administrador')
@section('heading', 'Creá tu administrador')
@section('description', 'Configurá el primer acceso de esta instalación local. Después vas a ingresar con tu correo y contraseña.')
@section('content')
<form class="auth-form" method="post" action="{{ route('admin.setup.store') }}">@csrf
    <label>Nombre<input type="text" name="name" value="{{ old('name') }}" autocomplete="name" required maxlength="120" autofocus></label>
    <label>Correo electrónico<input type="email" name="email" value="{{ old('email') }}" autocomplete="username" required maxlength="254"></label>
    <label>Contraseña<input type="password" name="password" autocomplete="new-password" required minlength="12"><small>Usá al menos 12 caracteres.</small></label>
    <label>Confirmar contraseña<input type="password" name="password_confirmation" autocomplete="new-password" required minlength="12"></label>
    <button class="button button-primary" type="submit">Crear administrador <span aria-hidden="true">→</span></button>
</form>
<p class="auth-help">Esta configuración inicial se cierra al crear la primera cuenta.</p>
@endsection
