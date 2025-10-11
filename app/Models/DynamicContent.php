<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DynamicContent extends Model
{
    use HasFactory;

    // =========================>
    // ## Fillable
    // =========================>
    protected $fillable = [
        'name',
        'description',
        'type',
        'content_type',
        'source_type',
        'level',
        'is_active',
        'community_id',
        'size',
    ];

    // =========================>
    // ## Searchable
    // =========================>
    public $searchable = [
        'dynamic_contents.name',
    ];

    // =========================>
    // ## Selectable
    // =========================>
    public $selectable = [
        'dynamic_contents.id',
        'dynamic_contents.name',
        'dynamic_contents.description',
        'dynamic_contents.type',
        'dynamic_contents.content_type',
        'dynamic_contents.source_type',
        'dynamic_contents.level',
        'dynamic_contents.is_active',
        'dynamic_contents.community_id',
        'dynamic_contents.size',
    ];

    /**
     * * Relation to `DynamicContentCube` model
     */
    public function dynamic_content_cubes(): HasMany
    {
        return $this->hasMany(DynamicContentCube::class, 'dynamic_content_id', 'id');
    }
}
