<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Banner extends Model
{
    use HasFactory;

    // =========================>
    // ## Fillable
    // =========================>
    protected $fillable = [
        'picture_source',
    ];

    // =========================>
    // ## Searchable
    // =========================>
    public $searchable = [
        'banners.picture_source',
    ];

    // =========================>
    // ## Selectable
    // =========================>
    public $selectable = [
        'banners.id',
        'banners.picture_source',
    ];

    public function toArray()
    {
        $toArray = parent::toArray();

        $toArray['picture_source'] = $this->picture_source ? asset('storage/' . $this->picture_source) : null;

        return $toArray;
    }
}
