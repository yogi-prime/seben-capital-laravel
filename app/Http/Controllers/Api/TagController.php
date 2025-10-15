<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tag;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class TagController extends Controller
{
    public function index(Request $request)
    {
        $q = Tag::query()->orderBy('name');

        return response()->json([
            'data' => $q->get(['id','name','slug']),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required','string','max:255'],
            'slug' => ['nullable','string','max:255','unique:tags,slug'],
        ]);

        $data['slug'] = $data['slug'] ?? Str::slug($data['name']);
        $tag = Tag::create($data);

        return response()->json(['data' => $tag], 201);
    }
}
