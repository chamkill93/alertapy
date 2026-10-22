<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\RawItem;
use App\Services\BackofficeDataService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class NewsController extends Controller
{
    public function index(Request $request, BackofficeDataService $data): View
    {
        return view('admin.news.index', $data->newsIndex($request));
    }

    public function show(RawItem $rawItem, BackofficeDataService $data): View
    {
        return view('admin.news.show', ['news' => $data->newsDetail($rawItem)]);
    }
}
