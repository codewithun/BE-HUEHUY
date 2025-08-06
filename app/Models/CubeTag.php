<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CubeTag extends Model
{
    use HasFactory;

    // =========================>
    // ## Fillable
    // =========================>
    protected $fillable = [
        'cube_id',
        'address',
        'map_lat',
        'map_lng',
        'link'
    ];

    // =========================>
    // ## Searchable
    // =========================>
    public $searchable = [
        'cube_tags.address',
        'cube_tags.link',
    ];

    // =========================>
    // ## Selectable
    // =========================>
    public $selectable = [
        'cube_tags.id',
        'cube_tags.address',
        'cube_tags.map_lat',
        'cube_tags.map_lng',
        'cube_tags.link',
    ];

    /**
     * * Relation to `Cube` model
     */
    public function cube() : BelongsTo
    {
        return $this->belongsTo(Cube::class, 'cube_id', 'id');
    }
}
