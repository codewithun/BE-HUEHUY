<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DatasourceLog extends Model
{
    use HasFactory;

    // =========================>
    // ## Fillable
    // =========================>
    protected $fillable = [
        'user_id',
        'datasource',
        'ip',
        'url',
        'request_method',
        'request_time',
        'finish_time',
        'exec_time',
        'additional_headerparams',
        'additional_bodyparams',
        'additional_queryparams',
        'response_data',
        'log_type',
        'log_bound',
        'request_status',
    ];

    // =========================>
    // ## Searchable
    // =========================>
    public $searchable = [
        'datasource_logs.datasource',
    ];

    // =========================>
    // ## Selectable
    // =========================>
    public $selectable = [
        'datasource_logs.id',
        'datasource_logs.user_id',
        'datasource_logs.datasource',
        'datasource_logs.ip',
        'datasource_logs.url',
        'datasource_logs.request_method',
        'datasource_logs.request_time',
        'datasource_logs.finish_time',
        'datasource_logs.exec_time',
        'datasource_logs.additional_headerparams',
        'datasource_logs.additional_bodyparams',
        'datasource_logs.additional_queryparams',
        'datasource_logs.response_data',
        'datasource_logs.log_type',
        'datasource_logs.log_bound',
        'datasource_logs.request_status',
    ];
}
