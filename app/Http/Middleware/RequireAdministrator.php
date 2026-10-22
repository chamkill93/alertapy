<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireAdministrator
{
    public function handle(Request $request, Closure $next, ?string $mode = null): Response
    {
        if ($request->user('web')?->is_admin) {
            return $next($request);
        }

        // Existing import tools may continue authenticating through HTTP Basic.
        if ($mode === 'legacy' && ! $request->user('web')) {
            $token = trim((string) config('alertapy.admin_token'));
            abort_if($token === '', 404);
            abort_unless(hash_equals($token, (string) $request->getPassword()), 401, 'Credenciales requeridas', [
                'WWW-Authenticate' => 'Basic realm="AlertaPY Admin"',
            ]);

            return $next($request);
        }

        if (! $request->user('web')) {
            if ($request->expectsJson()) {
                abort(401, 'Iniciá sesión para acceder al backoffice.');
            }

            return redirect()->guest(route('admin.login'));
        }

        abort_unless($request->user('web')->is_admin, 403, 'Acceso exclusivo para administradores.');

        return $next($request);
    }
}
