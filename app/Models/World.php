<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class World extends Model
{
    use HasFactory;

    // =========================>
    // ## Fillable
    // =========================>
    protected $fillable = [
        'corporate_id',
        'name',
        'description',
        'color',
        'type',
    ];

    // =========================>
    // ## Searchable
    // =========================>
    public $searchable = [
        'worlds.name',
    ];

    // =========================>
    // ## Selectable
    // =========================>
    public $selectable = [
        'worlds.id',
        'worlds.corporate_id',
        'worlds.name',
        'worlds.description',
        'worlds.color',
        'worlds.type',
    ];

    /**
     * * Relation to `Corporate` model
     */
    public function corporate() : BelongsTo
    {
        return $this->belongsTo(Corporate::class, 'corporate_id', 'id');
    }

    /**
     * * Relation to `UserWorld` model
     */
    public function user_worlds() : HasMany
    {
        return $this->hasMany(UserWorld::class, 'world_id', 'id');
    }
}
