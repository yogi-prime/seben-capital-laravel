<?php

namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class ChatbotMessage extends Model {
    protected $fillable = ['lead_id','direction','content','step_key','ip','ua'];
    public function lead() { return $this->belongsTo(ChatbotLead::class, 'lead_id'); }
}
