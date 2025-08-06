<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Article extends Model
{
    use HasFactory;

    // =========================>
    // ## Fillable
    // =========================>
    protected $fillable = [
        'picture_source',
        'slug',
        'title',
        'description',
    ];

    // =========================>
    // ## Searchable
    // =========================>
    public $searchable = [
        'articles.title',
    ];

    // =========================>
    // ## Selectable
    // =========================>
    public $selectable = [
        'articles.id',
        'articles.picture_source',
        'articles.slug',
        'articles.title',
        'articles.description',
    ];

    public function toArray()
    {
        $toArray = parent::toArray();

        $toArray['picture_source'] = $this->picture_source ? asset('storage/' . $this->picture_source) : null;

        return $toArray;
    }
}
