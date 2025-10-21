<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Chat extends Model
{
    use HasFactory;

    protected $fillable = [
        'community_id',
        'corporate_id',
        'user_id',
        'admin_id',
        'last_message',
        'updated_at',
    ];

    public function community()
    {
        return $this->belongsTo(Community::class);
    }

    public function corporate()
    {
        return $this->belongsTo(Corporate::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function admin()
    {
        return $this->belongsTo(User::class, 'admin_id');
    }

    public function messages()
    {
        return $this->hasMany(ChatMessage::class);
    }
}
