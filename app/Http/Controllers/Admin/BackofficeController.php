<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\BackofficeDataService;
use Illuminate\View\View;

class BackofficeController extends Controller
{
    public function __invoke(BackofficeDataService $data): View
    {
        return view('admin.dashboard', $data->dashboard());
    }
}
