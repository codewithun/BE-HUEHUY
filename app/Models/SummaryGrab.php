<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SummaryGrab extends Model
{
    use HasFactory;

    // =========================>
    // ## Fillable
    // =========================>
    protected $fillable = [
        'ad_id',
        'total_grab',
        'date',
    ];

    // =========================>
    // ## Searchable
    // =========================>
    public $searchable = [
        'summary_grabs.ad_id',
        'summary_grabs.total_grab',
        'summary_grabs.date',
    ];

    // =========================>
    // ## Selectable
    // =========================>
    public $selectable = [
        'summary_grabs.id',
        'summary_grabs.ad_id',
        'summary_grabs.total_grab',
        'summary_grabs.date',
    ];
}
