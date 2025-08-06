<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorldAffiliate extends Model
{
    use HasFactory;

    // =========================>
    // ## Fillable
    // =========================>
    protected $fillable = [
        'world_id',
        'corporate_id',
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
        'world_affiliates.id',
        'world_affiliates.world_id',
        'world_affiliates.corporate_id',
    ];

    /**
     * * Relation to `World` model
     */
    public function world() : BelongsTo
    {
        return $this->belongsTo(World::class, 'world_id', 'id');
    }

    /**
     * * Relation to `Corporate` model
     */
    public function corporate() : BelongsTo
    {
        return $this->belongsTo(Corporate::class, 'corporate_id', 'id');
    }
}
