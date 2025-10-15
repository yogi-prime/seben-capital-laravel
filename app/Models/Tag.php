<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Tag extends Model
{
    use SoftDeletes;

    protected $fillable = ['name','slug','seo_title','seo_description'];

    public function posts() {
        return $this->belongsToMany(Post::class)->withTimestamps();
    }
}
