@extends('admin.auth.layout')
@section('title', 'Ingresar al backoffice')
@section('heading', 'Ingresá a tu cuenta')
@section('description', 'Usá tu cuenta de administrador para acceder al backoffice.')
@section('content')
<form class="auth-form" method="post" action="{{ route('admin.login.store') }}">@csrf
    <label>Correo electrónico<input type="email" name="email" value="{{ old('email') }}" autocomplete="username" required maxlength="254" autofocus></label>
    <label>Contraseña<input type="password" name="password" autocomplete="current-password" required></label>
    <button class="button button-primary" type="submit">Ingresar al backoffice <span data-app-icon="arrow_right" aria-hidden="true"></span></button>
</form>
<p class="auth-help">El acceso está limitado a administradores autorizados.</p>
@endsection
