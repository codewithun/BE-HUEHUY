<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Faq extends Model
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
        'faqs.title',
    ];

    // =========================>
    // ## Selectable
    // =========================>
    public $selectable = [
        'faqs.id',
        'faqs.picture_source',
        'faqs.slug',
        'faqs.title',
        'faqs.description',
    ];

    public function toArray()
    {
        $toArray = parent::toArray();

        $toArray['picture_source'] = $this->picture_source ? asset('storage/' . $this->picture_source) : null;

        return $toArray;
    }
}
