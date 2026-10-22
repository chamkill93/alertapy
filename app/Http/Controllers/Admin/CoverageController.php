<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Source;
use App\Models\Incident;
use App\Services\CoverageService;
use App\Services\SchedulerHealthService;
use App\Services\SourceHealthService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CoverageController extends Controller
{
    public function __invoke(Request $request, CoverageService $coverage, SourceHealthService $health, SchedulerHealthService $scheduler): View
    {
        $token = trim((string) config('alertapy.admin_token'));

        if (! $request->user('web')?->is_admin && $token === '') {
            abort(404);
        }

        if (! $request->user('web')?->is_admin && ! hash_equals($token, (string) $request->getPassword())) {
            abort(401, 'Credenciales requeridas', ['WWW-Authenticate' => 'Basic realm="AlertaPY Admin"']);
        }

        return view('admin.coverage', [
            'coverage' => $coverage->departments(),
            'sources' => Source::query()->with('latestRun')->orderBy('name')->get()->map(fn (Source $source) => ['source' => $source, 'health' => $health->status($source)]),
            'scheduler' => $scheduler->status(),
            'dashboard' => [
                'active_incidents' => Incident::query()->current()->count(),
                'missing_occurred_at' => Incident::query()->published()->whereNull('occurred_at')->count(),
                'low_quality_locations' => Incident::query()->whereIn('location_quality', ['low','very_low','unknown'])->count(),
                'stale_incidents' => Incident::query()->whereIn('lifecycle_status', ['expired','resolved'])->count(),
            ],
            'geographicIssues' => Incident::query()->with(['category:id,name','sources.source:id,name'])->where(function ($query): void {
                $query->whereIn('location_quality', ['low','very_low','unknown'])->orWhereNotNull('location_quality_flags');
            })->latest('published_at')->limit(100)->get(),
        ]);
    }
}
