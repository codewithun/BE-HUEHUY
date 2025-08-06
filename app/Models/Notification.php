<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Notification extends Model
{
    use HasFactory;

    // =========================>
    // ## Fillable
    // =========================>
    protected $fillable = [
        'user_id',
        'cube_id',
        'ad_id',
        'grab_id',
        'type',
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
        'notifications.id',
        'notifications.user_id',
        'notifications.cube_id',
        'notifications.ad_id',
        'notifications.grab_id',
        'notifications.type',
        'notifications.message',
    ];

    /**
     * * Relation to `User` model
     */
    public function user() : BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }

    /**
     * * Relation to `Cube` model
     */
    public function cube() : BelongsTo
    {
        return $this->belongsTo(Cube::class, 'cube_id', 'id');
    }

    /**
     * * Relation to `Ad` model
     */
    public function ad() : BelongsTo
    {
        return $this->belongsTo(Ad::class, 'ad_id', 'id');
    }

    /**
     * * Relation to `Grab` model
     */
    public function grab() : BelongsTo
    {
        return $this->belongsTo(Grab::class, 'grab_id', 'id');
    }
}
