<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\RoadDataset;
use App\Services\RoadDatasetImporter;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\View\View;

class RoadNetworkController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorizeAdmin($request); return view('admin.roads', ['datasets' => RoadDataset::query()->withCount(['roads','referencePoints'])->latest()->get(), 'preview' => session('road_preview')]);
    }

    public function store(Request $request, RoadDatasetImporter $importer): RedirectResponse
    {
        $this->authorizeAdmin($request); $data = $request->validate(['dataset' => ['required','file','max:51200'], 'dataset_name' => ['required','string','max:180'], 'source_name' => ['required','string','max:180'], 'source_url' => ['nullable','url','max:2048'], 'source_date' => ['nullable','date'], 'mode' => ['required','in:validate,import'], 'replace' => ['sometimes','boolean']]);
        $extension = strtolower($data['dataset']->getClientOriginalExtension()); abort_unless(in_array($extension, ['geojson','json','kml','kmz','csv'], true), 422, 'Formato vial no soportado.');
        $name = now()->format('YmdHis').'-'.preg_replace('/[^A-Za-z0-9._-]/', '-', $data['dataset']->getClientOriginalName());
        $directory = storage_path('app/imports/roads'); File::ensureDirectoryExists($directory); $data['dataset']->move($directory, $name); $absolute = $directory.DIRECTORY_SEPARATOR.$name;
        $options = ['dry_run' => true, 'dataset_name' => $data['dataset_name'], 'source_name' => $data['source_name'], 'source_url' => $data['source_url'] ?? null, 'source_date' => $data['source_date'] ?? null, 'replace' => (bool) ($data['replace'] ?? false)];
        $preview = $importer->import($absolute, $options);
        if ($data['mode'] === 'import') { $options['dry_run'] = false; $preview = $importer->import($absolute, $options); }
        return back()->with('road_preview', $preview)->with('status', $data['mode'] === 'import' ? 'Dataset importado.' : 'Validación completada; todavía no se modificó la red activa.');
    }

    public function deactivate(Request $request, RoadDataset $roadDataset): RedirectResponse
    {
        $this->authorizeAdmin($request); $roadDataset->roads()->update(['active' => false]); $roadDataset->update(['status' => 'disabled']); return back()->with('status', 'Dataset desactivado sin eliminar sus datos.');
    }

    public function reprocess(Request $request): RedirectResponse
    {
        $this->authorizeAdmin($request);
        Artisan::call('alertapy:locations:reprocess', ['--only-low-accuracy' => true, '--limit' => 100]);
        return back()->with('status', 'Reprocesamiento finalizado. Solo se aplicaron mejoras de precisión.')->with('command_output', trim(Artisan::output()));
    }

    private function authorizeAdmin(Request $request): void
    {
        if ($request->user('web')?->is_admin) {
            return;
        }

        $token = trim((string) config('alertapy.admin_token')); if ($token === '') abort(404); if (! hash_equals($token, (string) $request->getPassword())) abort(401, 'Credenciales requeridas', ['WWW-Authenticate' => 'Basic realm="AlertaPY Admin"']);
    }
}
