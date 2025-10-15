<?php

namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class ChatbotFlow extends Model {
    protected $fillable = ['key','steps','is_active'];
    protected $casts = ['steps' => 'array', 'is_active' => 'boolean'];
}