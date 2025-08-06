<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ChatRoom extends Model
{
    use HasFactory;

    // =========================>
    // ## Fillable
    // =========================>
    protected $fillable = [
        'world_id',
        'user_merchant_id',
        'user_hunter_id'
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
        'chat_rooms.id',
        'chat_rooms.world_id',
        'chat_rooms.user_merchant_id',
        'chat_rooms.user_hunter_id',
        'chat_rooms.created_at',
        'chat_rooms.updated_at',
    ];

    /**
     * * Relation to `Chat` model
     */
    public function chats() : HasMany
    {
        return $this->hasMany(Chat::class, 'chat_room_id', 'id');
    }

    /**
     * * Relation to `World` model
     */
    public function world() : BelongsTo
    {
        return $this->belongsTo(World::class, 'world_id', 'id');
    }

    /**
     * * Relation to `User` model as user merchant
     */
    public function user_merchant() : BelongsTo
    {
        return $this->belongsTo(User::class, 'user_merchant_id', 'id');
    }

    /**
     * * Relation to `User` model as user hunter
     */
    public function user_hunter() : BelongsTo
    {
        return $this->belongsTo(User::class, 'user_hunter_id', 'id');
    }

    public function getLastChatAttribute()
    {
        return Chat::with('cube', 'grab')->where('chat_room_id', $this->id)->orderBy('created_at', 'desc')->first();
    }

    public function toArray()
    {
        $toArray = parent::toArray();

        $toArray['last_chat'] = $this->last_chat;

        return $toArray;
    }
}
