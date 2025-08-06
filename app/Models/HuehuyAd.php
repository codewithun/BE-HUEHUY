<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class HuehuyAd extends Model
{
    use HasFactory;

    // =========================>
    // ## Fillable
    // =========================>
    protected $fillable = [
        'title',
        'description',
        'picture_source',
        'type',
        'limit',
    ];

    // =========================>
    // ## Searchable
    // =========================>
    public $searchable = [
        'huehuy_ads.title',
    ];

    // =========================>
    // ## Selectable
    // =========================>
    public $selectable = [
        'huehuy_ads.id',
        'huehuy_ads.title',
        'huehuy_ads.description',
        'huehuy_ads.picture_source',
        'huehuy_ads.type',
        'huehuy_ads.limit',
    ];

    public function toArray()
    {
        $toArray = parent::toArray();

        $toArray['picture_source'] = $this->picture_source ? asset('storage/' . $this->picture_source) : null;

        return $toArray;
    }
}
