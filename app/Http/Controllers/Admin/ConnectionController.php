<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Source;
use App\Services\BackofficeDataService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ConnectionController extends Controller
{
    public function index(Request $request, BackofficeDataService $data): View
    {
        return view('admin.connections.index', $data->connectionsIndex($request));
    }

    public function show(Request $request, Source $source, BackofficeDataService $data): View
    {
        return view('admin.connections.show', $data->connectionDetail($source, $request));
    }
}
