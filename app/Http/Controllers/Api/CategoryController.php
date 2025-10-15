<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class CategoryController extends Controller
{
    public function index(Request $request)
    {
        $q = Category::query()
            ->when($request->boolean('active_only'), fn($qq) => $qq->where('is_active', true))
            ->orderBy('order_column')
            ->orderBy('name');

        return response()->json([
            'data' => $q->get(['id','name','slug']),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required','string','max:255'],
            'slug' => ['nullable','string','max:255','unique:categories,slug'],
        ]);

        $data['slug'] = $data['slug'] ?? Str::slug($data['name']);
        $cat = Category::create($data);

        return response()->json(['data' => $cat], 201);
    }
}
