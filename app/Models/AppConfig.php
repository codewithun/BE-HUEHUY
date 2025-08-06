<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AppConfig extends Model
{
    use HasFactory;

    // =========================>
    // ## Fillable
    // =========================>
    protected $fillable = [
        'code',
        'name',
        'description',
        'value'
    ];

    // =========================>
    // ## Searchable
    // =========================>
    public $searchable = [
        'app_configs.code',
        'app_configs.name',
    ];

    // =========================>
    // ## Selectable
    // =========================>
    public $selectable = [
        'app_configs.id',
        'app_configs.code',
        'app_configs.name',
        'app_configs.description',
        'app_configs.value',
        'app_configs.created_at',
        'app_configs.updated_at',
    ];

    public function toArray()
    {
        $toArray = parent::toArray();

        if(isset($toArray['value'])) {
            try {
                $toArray['value'] = json_decode($this->value);
            } catch (\Throwable $th) {
                info($th);
                $toArray['value'] = $this->value;
            }

            // * Force the key of `picture_source` return as url
            if (isset($toArray['value']?->picture_source)) {
                $toArray['value']->picture_source = $toArray['value']?->picture_source ? asset('storage/' . $toArray['value']?->picture_source) : null;
            }
        }

        return $toArray;
    }
}
