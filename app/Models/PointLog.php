<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PointLog extends Model
{
    use HasFactory;

    // =========================>
    // ## Fillable
    // =========================>
    protected $fillable = [
        'user_id',
        'corporate_id',
        'initial_point',
        'final_point',
        'description',
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
        'point_logs.id',
        'point_logs.user_id',
        'point_logs.corporate_id',
        'point_logs.initial_point',
        'point_logs.final_point',
        'point_logs.description',
    ];
}
