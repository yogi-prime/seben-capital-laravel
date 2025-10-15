<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Category extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'name','slug','seo_title','seo_description','canonical_url',
        'og_data','twitter_data','schema_json','order_column','is_active'
    ];

    protected $casts = [
        'og_data' => 'array',
        'twitter_data' => 'array',
        'schema_json' => 'array',
        'is_active' => 'boolean',
    ];

    public function posts() {
        return $this->belongsToMany(Post::class)->withTimestamps()->withPivot('order_column');
    }

    // Optional: primary category inverse (posts where this is primary)
    public function primaryPosts() {
        return $this->hasMany(Post::class, 'primary_category_id');
    }
}
