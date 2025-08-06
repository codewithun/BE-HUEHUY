<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DynamicContentCube extends Model
{
    use HasFactory;

    // =========================>
    // ## Fillable
    // =========================>
    protected $fillable = [
        'dynamic_content_id',
        'cube_id'
    ];

    // =========================>
    // ## Searchable
    // =========================>
    public $searchable = [
        'dynamic_content_cubes.dynamic_content_id',
        'dynamic_content_cubes.cube_id',
    ];

    // =========================>
    // ## Selectable
    // =========================>
    public $selectable = [
        'dynamic_content_cubes.id',
        'dynamic_content_cubes.dynamic_content_id',
        'dynamic_content_cubes.cube_id',
    ];

    /**
     * * Relation to `Cube` model
     */
    public function cube(): BelongsTo
    {
        return $this->belongsTo(Cube::class, 'cube_id', 'id');
    }
}
