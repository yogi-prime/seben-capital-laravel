<?php

namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class ChatbotLead extends Model {
    protected $fillable = ['name','phone','email','answers','meta','status'];
    protected $casts = ['answers' => 'array', 'meta' => 'array'];
    public function messages() { return $this->hasMany(ChatbotMessage::class, 'lead_id'); }
}