<?php

namespace App\Http\Controllers;

use App\Models\Category;
use Illuminate\Contracts\View\View;

class MapController extends Controller
{
    public function __invoke(): View
    {
        return view('map', [
            'categories' => Category::query()
                ->where('enabled', true)
                ->orderBy('sort_order')
                ->get(['id', 'name', 'slug', 'icon', 'color']),
        ]);
    }
}
