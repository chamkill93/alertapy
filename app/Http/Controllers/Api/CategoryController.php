<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Category;
use Illuminate\Http\JsonResponse;

class CategoryController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $categories = Category::query()
            ->where('enabled', true)
            ->orderBy('sort_order')
            ->get(['slug', 'name', 'icon', 'color', 'description']);

        return response()->json(['data' => $categories]);
    }
}
