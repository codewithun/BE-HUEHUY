<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AdCategory extends Model
{
    use HasFactory;

    // =========================>
    // ## Fillable
    // =========================>
    protected $fillable = [
        'parent_id',
        'name',
        'picture_source',
        'image_updated_at',
        'is_primary_parent',
        'is_home_display',
    ];

    // =========================>
    // ## Searchable
    // =========================>
    public $searchable = [
        'ad_categories.name',
    ];

    // =========================>
    // ## Selectable
    // =========================>
    public $selectable = [
        'ad_categories.id',
        'ad_categories.parent_id',
        'ad_categories.name',
        'ad_categories.picture_source',
        'ad_categories.image_updated_at',
        'ad_categories.is_primary_parent',
        'ad_categories.is_home_display',
    ];

    public function toArray()
    {
        $toArray = parent::toArray();

        if(isset($toArray['picture_source'])) {
            $cacheBuster = $this->image_updated_at ? '?v=' . strtotime($this->image_updated_at) : '';
            $toArray['picture_source'] = $this->picture_source 
                ? asset('storage/' . $this->picture_source) . $cacheBuster 
                : null;
        }

        return $toArray;
    }

    /**
     * * Relation to child categories
     */
    public function childs() : HasMany
    {
        return $this->hasMany(AdCategory::class, 'parent_id', 'id');
    }
}
