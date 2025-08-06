<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Auth;

class Corporate extends Model
{
    use HasFactory;

    // =========================>
    // ## Fillable
    // =========================>
    protected $fillable = [
        'name',
        'description',
        'address',
        'phone',
        'point',
    ];

    // =========================>
    // ## Searchable
    // =========================>
    public $searchable = [
        'corporates.name',
        'corporates.phone',
    ];

    // =========================>
    // ## Selectable
    // =========================>
    public $selectable = [
        'corporates.id',
        'corporates.name',
        'corporates.description',
        'corporates.address',
        'corporates.phone',
        'corporates.point',
    ];

    /**
     * * Relation to `CorporateUser` model
     */
    public function corporate_users() : HasMany
    {
        return $this->hasMany(CorporateUser::class, 'corporate_id', 'id');
    }

    /**
     * * Hook Event Model
     */
    protected static function booted()
    {
        static::updated(function (Corporate $corporate) {

            $originalData = $corporate->getOriginal();

            $requestData = request()->all();

            if ($corporate->wasChanged('point')) {

                // ? Create Point Log
                $pointLog = new PointLog();
                $pointLog->corporate_id = $corporate->id;
                $pointLog->initial_point = $originalData['point'];
                $pointLog->final_point = $corporate->point;
                $pointLog->description = isset($requestData['log_description'])
                    ? $requestData['log_description']
                    : "Diubah oleh " . Auth::user()->email . " dari " . $originalData['point'] . " ke " . $corporate->point;
                $pointLog->save();
            }
        });
    }
}
