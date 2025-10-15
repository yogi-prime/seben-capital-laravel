<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Post extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'title','slug','excerpt','content_html','content_markdown',
        'featured_image','featured_image_alt',
        'seo_title','seo_description','canonical_url',
        'og_data','twitter_data','schema_json',
        'author_name','read_time','word_count','views',
        'status','published_at','is_featured','primary_category_id','meta'
    ];

    protected $casts = [
        'og_data' => 'array',
        'twitter_data' => 'array',
        'schema_json' => 'array',
        'meta' => 'array',
        'is_featured' => 'boolean',
        'published_at' => 'datetime',
    ];

    /* Relationships */
    public function categories() {
        return $this->belongsToMany(Category::class)->withTimestamps()->withPivot('order_column');
    }

    public function primaryCategory() {
        return $this->belongsTo(Category::class, 'primary_category_id');
    }

    public function tags() {
        return $this->belongsToMany(Tag::class)->withTimestamps();
    }

    /* Boot: auto-slug & word_count */
    protected static function booted(): void
    {
        static::creating(function (Post $post) {
            if (blank($post->slug) && filled($post->title)) {
                $post->slug = Str::slug(Str::limit($post->title, 80, ''));
            }
            if (blank($post->word_count) && filled($post->content_markdown)) {
                $post->word_count = str_word_count(strip_tags($post->content_markdown));
            } elseif (blank($post->word_count) && filled($post->content_html)) {
                $post->word_count = str_word_count(strip_tags($post->content_html));
            }
            if (blank($post->read_time) && $post->word_count) {
                $mins = max(1, (int) ceil($post->word_count / 220)); // avg reading speed
                $post->read_time = "{$mins} min read";
            }
        });
    }

    /* Scopes */
    public function scopePublished($q) {
        return $q->where('status', 'published')
                 ->whereNotNull('published_at')
                 ->where('published_at', '<=', now());
    }
}
