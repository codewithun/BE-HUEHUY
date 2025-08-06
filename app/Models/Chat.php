<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Chat extends Model
{
    use HasFactory;

    // =========================>
    // ## Fillable
    // =========================>
    protected $fillable = [
        'chat_room_id',
        'user_sender_id',
        'cube_id',
        'grab_id',
        'message',
    ];

    // =========================>
    // ## Searchable
    // =========================>
    public $searchable = [
    ];

    // =========================>
    // ## Selectable
    // =========================>
    public $selectable = [
        'chats.id',
        'chats.chat_room_id',
        'chats.user_sender_id',
        'chats.cube_id',
        'chats.grab_id',
        'chats.message',
    ];

    /**
     * * Relation to `ChatRoom` model
     */
    public function chat_room() : BelongsTo {
        return $this->belongsTo(ChatRoom::class, 'chat_room_id', 'id');
    }

    /**
     * * Relation to `User` model
     */
    public function user_sender() : BelongsTo
    {
        return $this->belongsTo(User::class, 'user_sender_id', 'id');
    }

    /**
     * * Relation to `Cube` model
     */
    public function cube() : BelongsTo
    {
        return $this->belongsTo(Cube::class, 'cube_id', 'id');
    }

    /**
     * * Relation to `Grab` model
     */
    public function grab() : BelongsTo
    {
        return $this->belongsTo(Grab::class, 'grab_id', 'id');
    }
}
