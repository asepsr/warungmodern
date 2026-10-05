<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ChatSession extends Model
{
    protected $fillable = ['phone', 'state', 'context', 'last_activity_at'];

    protected function casts(): array
    {
        return ['context' => 'array', 'last_activity_at' => 'datetime'];
    }
}
