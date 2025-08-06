<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserWorld extends Model
{
    use HasFactory;

    use HasFactory;

    // =========================>
    // ## Fillable
    // =========================>
    protected $fillable = [
        'user_id',
        'world_id',
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
        'user_worlds.id',
        'user_worlds.user_id',
        'user_worlds.world_id'
    ];

    /**
     * * Relation to `User` model
     */
    public function user() : BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }
}
