<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Post;
use App\Models\Category;
use App\Models\Tag;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class PostController extends Controller
{
    // GET /api/posts?q=&category_slug=&tag_slug=&status=&featured=1&page=1&per_page=12
  public function index(Request $request)
{
    Log::info('--- PostController@index called ---');
    Log::debug('Request all inputs:', $request->all());

    $perPage = min(50, (int)($request->integer('per_page') ?: 12));
    Log::debug('Per page value set:', ['perPage' => $perPage]);

    $q = Post::query()
        ->with(['primaryCategory:id,name,slug', 'categories:id,name,slug', 'tags:id,name,slug']);

    Log::debug('Initial query with relationships loaded');

    // STATUS FILTER
    if ($request->filled('status')) {
        $status = $request->string('status');
        $q->where('status', $status);
        Log::debug('Applied status filter:', ['status' => $status]);
    }

    // FEATURED FILTER
    if ($request->boolean('featured')) {
        $q->where('is_featured', true);
        Log::debug('Applied featured filter: true');
    }

    // CATEGORY FILTER
    if ($request->filled('category_slug')) {
        $slug = $request->string('category_slug');
        $q->where(function ($w) use ($slug) {
            $w->whereHas('categories', fn($c) => $c->where('slug', $slug))
              ->orWhereHas('primaryCategory', fn($c) => $c->where('slug', $slug));
        });
        Log::debug('Applied category filter:', ['category_slug' => $slug]);
    }

    // TAG FILTER
    if ($request->filled('tag_slug')) {
        $tag = $request->string('tag_slug');
        $q->whereHas('tags', fn($t) => $t->where('slug', $tag));
        Log::debug('Applied tag filter:', ['tag_slug' => $tag]);
    }

    // SEARCH TERM FILTER
    if ($request->filled('q')) {
        $term = $request->string('q');
        Log::debug('Applying search term filter:', ['q' => $term]);
        $q->where(function ($w) use ($term) {
            if (config('database.default') === 'mysql') {
                $w->whereRaw("MATCH (title, excerpt, content_html) AGAINST (? IN NATURAL LANGUAGE MODE)", [$term]);
                Log::debug('Used FULLTEXT search mode for MySQL');
            } else {
                $like = '%' . $term . '%';
                $w->where('title', 'like', $like)
                    ->orWhere('excerpt', 'like', $like)
                    ->orWhere('content_html', 'like', $like);
                Log::debug('Used LIKE search fallback');
            }
        });
    }

    $q->orderByDesc('published_at')->orderByDesc('id');
    Log::debug('Applied ordering: published_at desc, id desc');

    // Execute the query with pagination
    $posts = $q->paginate($perPage, [
        'id','title','slug','excerpt','featured_image','featured_image_alt',
        'seo_title','seo_description','published_at','read_time','word_count','is_featured','status'
    ]);

    Log::info('Query executed successfully', [
        'total' => $posts->total(),
        'current_page' => $posts->currentPage(),
        'per_page' => $posts->perPage(),
        'count' => $posts->count(),
    ]);

    return $posts;
}

    // GET /api/posts/{slug}
    public function showBySlug(string $slug)
    {
        $post = Post::with([
            'primaryCategory:id,name,slug',
            'categories:id,name,slug',
            'tags:id,name,slug'
        ])->where('slug', $slug)->firstOrFail();

        return response()->json(['data' => $post]);
    }

    // POST /api/posts  (multipart: featured_image + payload JSON)
    public function store(Request $request)
    {
        // Pull JSON payload (was sent as a Blob)
        $payloadRaw = $request->input('payload'); // string
        if (!$payloadRaw) {
            return response()->json(['message' => 'Missing payload'], 422);
        }

        $payload = json_decode($payloadRaw, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return response()->json(['message' => 'Invalid JSON in payload'], 422);
        }

        // Minimal validation
        $validated = validator($payload, [
            'title'   => ['required','string','max:255'],
            'slug'    => ['nullable','string','max:255','unique:posts,slug'],
            'excerpt' => ['nullable','string','max:1000'],
            'content_markdown' => ['nullable','string'],
            'content_html'     => ['nullable','string'],
            'featured_image_alt' => ['nullable','string','max:255'],

            'seo_title'       => ['nullable','string','max:255'],
            'seo_description' => ['nullable','string','max:500'],
            'canonical_url'   => ['nullable','string','max:500'],
            'og_data'         => ['nullable','array'],
            'twitter_data'    => ['nullable','array'],
            'schema_json'     => ['nullable','array'],

            'author_name'  => ['nullable','string','max:255'],
            'read_time'    => ['nullable','string','max:50'],
            'word_count'   => ['nullable','integer','min:0'],

            'status'       => ['required','in:draft,scheduled,published,archived'],
            'published_at' => ['nullable','date'],

            'is_featured' => ['boolean'],
            'primary_category_id' => ['nullable'],

            'categories_existing_ids' => ['array'],
            'categories_existing_ids.*' => ['integer','min:1'],

            'categories_new' => ['array'],
            'categories_new.*.name' => ['required','string','max:255'],
            'categories_new.*.slug' => ['nullable','string','max:255'],

            'tags_existing_names' => ['array'],
            'tags_existing_names.*' => ['string','max:255'],
            'tags_new_names' => ['array'],
            'tags_new_names.*' => ['string','max:255'],
        ])->validate();

        // Transaction for consistency
        return DB::transaction(function () use ($request, $validated, $payload) {

            // 1) Create any NEW categories first
            $newCatIds = [];
            if (!empty($validated['categories_new'])) {
                foreach ($validated['categories_new'] as $nc) {
                    $name = $nc['name'];
                    $slug = $nc['slug'] ?? Str::slug($name);
                    $cat = Category::firstOrCreate(['slug' => $slug], ['name' => $name]);
                    $newCatIds[] = $cat->id;
                }
            }

            // 2) Resolve all category IDs (existing + newly created)
            $categoryIds = array_values(array_unique(array_merge(
                $validated['categories_existing_ids'] ?? [],
                $newCatIds
            )));

            // 3) Resolve primary category: can be an existing id, or a temp negative id linked to new cats
            $primaryCategoryId = null;
            if (!empty($payload['primary_category_id'])) {
                $pcId = (int)$payload['primary_category_id'];

                if ($pcId > 0) {
                    $primaryCategoryId = Category::whereKey($pcId)->value('id');
                } else {
                    // negative id: map by name/slug from categories_new
                    $pc = $payload['categories_new'][0] ?? null; // heuristic: the first new one
                    if ($pc) {
                        $primaryCategoryId = Category::where('slug', $pc['slug'] ?? Str::slug($pc['name']))->value('id');
                    }
                }
            }
            // If still null, fallback to first category chosen
            if (!$primaryCategoryId && !empty($categoryIds)) {
                $primaryCategoryId = $categoryIds[0];
            }

            // 4) Tags: resolve existing by name, create new by name
            $tagIds = [];
            if (!empty($validated['tags_existing_names'])) {
                $existing = Tag::whereIn(DB::raw('LOWER(name)'), array_map('mb_strtolower', $validated['tags_existing_names']))
                               ->get(['id','name']);
                $tagIds = array_merge($tagIds, $existing->pluck('id')->all());
            }
            if (!empty($validated['tags_new_names'])) {
                foreach ($validated['tags_new_names'] as $name) {
                    $tag = Tag::firstOrCreate(['slug' => Str::slug($name)], ['name' => $name]);
                    $tagIds[] = $tag->id;
                }
            }
            $tagIds = array_values(array_unique($tagIds));

            // 5) Handle image upload (optional)
            $featuredImageUrl = null;
            if ($request->hasFile('featured_image')) {
                $path = $request->file('featured_image')->store('posts', 'public'); // storage/app/public/posts
                $featuredImageUrl = Storage::url($path); // /storage/posts/...
            }

            // 6) Create Post
            $post = new Post();
            $post->title = $validated['title'];
            $post->slug  = $validated['slug'] ?? Str::slug(Str::limit($validated['title'], 80, ''));
            $post->excerpt = $validated['excerpt'] ?? null;
            $post->content_markdown = $validated['content_markdown'] ?? null;
            $post->content_html     = $validated['content_html'] ?? null;

            if ($featuredImageUrl) {
                $post->featured_image = $featuredImageUrl;
                $post->featured_image_alt = $validated['featured_image_alt'] ?? null;
            }

            // SEO/meta
            $post->seo_title       = $validated['seo_title'] ?? null;
            $post->seo_description = $validated['seo_description'] ?? null;
            $post->canonical_url   = $validated['canonical_url'] ?? null;
            $post->og_data         = $validated['og_data'] ?? null;
            $post->twitter_data    = $validated['twitter_data'] ?? null;
            $post->schema_json     = $validated['schema_json'] ?? null;

            $post->author_name = $validated['author_name'] ?? 'Seben Team';
            $post->read_time   = $validated['read_time'] ?? null;
            $post->word_count  = $validated['word_count'] ?? 0;

            $post->status      = $validated['status'];
            $post->published_at = $validated['published_at'] ?? null;
            $post->is_featured  = (bool)($validated['is_featured'] ?? false);

            $post->primary_category_id = $primaryCategoryId;

            $post->save();

            // 7) attach categories & tags
            if (!empty($categoryIds)) {
                $post->categories()->sync($categoryIds);
            }
            if (!empty($tagIds)) {
                $post->tags()->sync($tagIds);
            }

            // Eager load for response
            $post->load(['primaryCategory:id,name,slug', 'categories:id,name,slug', 'tags:id,name,slug']);

            return response()->json(['data' => $post], 201);
        });
    }


    public function show($id)
    {
        $post = Post::with(['categories', 'tags'])->findOrFail($id);

        // Attach primaryCategory in response (if you keep primary_category_id on posts table)
        $primary = null;
        if ($post->primary_category_id) {
            $primary = Category::find($post->primary_category_id);
        }

        return response()->json([
            'data' => array_merge($post->toArray(), [
                'primaryCategory' => $primary ? [
                    'id' => $primary->id, 'name' => $primary->name, 'slug' => $primary->slug
                ] : null
            ])
        ]);
    }

    public function update(Request $request, $id)
    {
        $post = Post::with(['categories','tags'])->findOrFail($id);
    $payload = json_decode($request->input('payload', '{}'), true) ?? [];

         // ---- SAFE word_count + read_time ----
    $words = isset($payload['word_count']) ? (int)$payload['word_count'] : 0;
    if ($words <= 0) {
        $words = $this->computeWordsFromPayload($payload);
    }
    $post->word_count = $words;

    $readTime = $payload['read_time'] ?? null;
    if (empty($readTime)) {
        $readTime = $this->computeReadTime($words);
    }
    $post->read_time = $readTime;
     

        // --- Basic fields ---
        $post->title               = $payload['title'] ?? $post->title;
        $post->slug                = $payload['slug'] ?? Str::slug($post->title);
        $post->excerpt             = $payload['excerpt'] ?? null;
        $post->content_markdown    = $payload['content_markdown'] ?? null;
        $post->content_html        = $payload['content_html'] ?? null;
        $post->featured_image_alt  = $payload['featured_image_alt'] ?? null;

        // SEO/meta
        $post->seo_title        = $payload['seo_title'] ?? null;
        $post->seo_description  = $payload['seo_description'] ?? null;
        $post->canonical_url    = $payload['canonical_url'] ?? null;
        $post->og_data          = isset($payload['og_data']) ? $payload['og_data'] : null;
        $post->twitter_data     = isset($payload['twitter_data']) ? $payload['twitter_data'] : null;
        $post->schema_json      = isset($payload['schema_json']) ? $payload['schema_json'] : null;

        // Publishing/meta
        $post->author_name      = $payload['author_name'] ?? $post->author_name;
        $post->is_featured      = (bool) ($payload['is_featured'] ?? false);
        $post->status           = $payload['status'] ?? $post->status;
        $post->published_at     = $payload['published_at'] ?? $post->published_at;

        // Primary Category (can be null)
        $post->primary_category_id = $payload['primary_category_id'] ?? null;

        // --- Featured image upload (optional) ---
       if ($request->hasFile('featured_image')) {
        if ($post->featured_image && \Storage::disk('public')->exists($post->featured_image)) {
            \Storage::disk('public')->delete($post->featured_image);
        }
        $path = $request->file('featured_image')->store('blog', 'public');
        $post->featured_image = \Storage::url($path);
    }

    $post->save();

        // --- Taxonomies ---
        $existingCatIds = collect($payload['categories_existing_ids'] ?? [])
            ->filter(fn($id) => $id > 0)
            ->values()
            ->all();

        $newCatsPayload = $payload['categories_new'] ?? []; // [{name,slug}]
        $newCatIds = [];
        foreach ($newCatsPayload as $c) {
            if (!isset($c['name'])) continue;
            $slug = $c['slug'] ?? Str::slug($c['name']);
            $cat = Category::firstOrCreate(['slug' => $slug], ['name' => $c['name']]);
            $newCatIds[] = $cat->id;
        }
        $post->categories()->sync(array_values(array_unique(array_merge($existingCatIds, $newCatIds))));

        // Tags: by name
        $tagNames = array_unique(array_merge(
            $payload['tags_existing_names'] ?? [],
            $payload['tags_new_names'] ?? []
        ));
        $tagIds = [];
        foreach ($tagNames as $name) {
            $slug = Str::slug($name);
            $tag = Tag::firstOrCreate(['slug' => $slug], ['name' => $name]);
            $tagIds[] = $tag->id;
        }
        $post->tags()->sync($tagIds);

         return response()->json(['message' => 'Updated', 'data' => $post->fresh(['categories','tags'])]);
    }

    public function destroy($id)
    {
        $post = Post::findOrFail($id);
        // optionally delete image from storage
        if ($post->featured_image && Storage::disk('public')->exists($post->featured_image)) {
            Storage::disk('public')->delete($post->featured_image);
        }
        $post->categories()->detach();
        $post->tags()->detach();
        $post->delete();

        return response()->json(['message' => 'Deleted']);
    }
    private function computeWordsFromPayload(array $payload): int
{
    $src = ($payload['content_html'] ?? '') . ' '
         . ($payload['content_markdown'] ?? '') . ' '
         . ($payload['title'] ?? '') . ' '
         . ($payload['excerpt'] ?? '');
    $plain = strip_tags($src);
    // str_word_count works fine for ascii; for unicode you can fallback to preg
    $w = str_word_count($plain);
    return max(0, (int)$w);
}

private function computeReadTime(int $words): string
{
    $mins = max(1, (int)ceil($words / 220));
    return $mins . ' min read';
}
public function related(string $slug, Request $request)
{
    try {
        // 1️⃣ Log the entry point
        Log::info('🟢 [Related Posts] Method called', [
            'slug' => $slug,
            'request_params' => $request->all(),
        ]);

        // 2️⃣ Handle limit
        $limit = min(12, (int)($request->integer('limit') ?: 3));
        Log::info('🔹 Limit calculated', ['limit' => $limit]);

        // 3️⃣ Fetch the main post
        $post = Post::with(['primaryCategory:id', 'categories:id'])
            ->where('slug', $slug)
            ->first();

        if (!$post) {
            Log::warning('⚠️ Post not found for slug', ['slug' => $slug]);
            return response()->json(['message' => 'Post not found'], 404);
        }

        Log::info('🟩 Post found', [
            'post_id' => $post->id,
            'primary_category_id' => $post->primary_category_id,
            'categories' => $post->categories->pluck('id')->toArray(),
        ]);

        // 4️⃣ Collect all category IDs (primary + attached)
        $catIds = collect([$post->primary_category_id])
            ->merge($post->categories->pluck('id'))
            ->filter()
            ->unique()
            ->values()
            ->all();

        Log::info('🟦 Category IDs collected', ['catIds' => $catIds]);

        // 5️⃣ Fetch related posts
        $query = Post::query()
            ->with(['primaryCategory:id,name,slug', 'categories:id,name,slug', 'tags:id,name,slug'])
            ->where('id', '<>', $post->id)
            ->when(!empty($catIds), function ($q) use ($catIds) {
                $q->where(function ($w) use ($catIds) {
                    $w->whereIn('primary_category_id', $catIds)
                      ->orWhereHas('categories', fn($c) => $c->whereIn('categories.id', $catIds));
                });
            })
            ->where('status', 'published')
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->limit($limit);

        // Log the generated SQL (for debugging only, not in production)
        Log::debug('🧩 SQL Query Prepared', ['sql' => $query->toSql(), 'bindings' => $query->getBindings()]);

        $rows = $query->get([
            'id', 'title', 'slug', 'excerpt', 'featured_image', 'featured_image_alt',
            'seo_title', 'seo_description', 'published_at', 'read_time', 'word_count',
            'is_featured', 'status'
        ]);

        Log::info('✅ Related posts fetched', [
            'count' => $rows->count(),
            'post_ids' => $rows->pluck('id')->toArray(),
        ]);

        return response()->json(['data' => $rows]);
    } catch (\Throwable $e) {
        Log::error('🚨 Exception in related() method', [
            'slug' => $slug,
            'error_message' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTraceAsString(),
        ]);

        return response()->json([
            'success' => false,
            'message' => 'Internal server error',
        ], 500);
    }
}
}
