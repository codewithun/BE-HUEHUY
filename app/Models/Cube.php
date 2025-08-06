<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

class Cube extends Model
{
    use HasFactory;

    // =========================>
    // ## Fillable
    // =========================>
    protected $fillable = [
        'cube_type_id',
        'parent_id',
        'user_id',
        'corporate_id',
        'world_id',
        'world_affiliate_id',
        'code',
        'picture_source',
        'color',
        'address',
        'map_lat',
        'map_lng',
        'expired_activate_date',
        'status',
        'inactive_at',
        'is_recommendation',
        'is_information',
        'link_information'
    ];

    // =========================>
    // ## Searchable
    // =========================>
    public $searchable = [
        'cubes.code',
    ];

    // =========================>
    // ## Selectable
    // =========================>
    public $selectable = [
        'cubes.id',
        'cubes.cube_type_id',
        'cubes.parent_id',
        'cubes.user_id',
        'cubes.corporate_id',
        'cubes.world_id',
        'cubes.world_affiliate_id',
        'cubes.code',
        'cubes.picture_source',
        'cubes.color',
        'cubes.address',
        'cubes.map_lat',
        'cubes.map_lng',
        'cubes.expired_activate_date',
        'cubes.status',
        'cubes.inactive_at',
        'cubes.is_recommendation',
        'cubes.is_information',
        'cubes.link_information'
    ];

    /**
     * * Relation to `Ads` model
     */
    public function ads() : HasMany
    {
        return $this->hasMany(Ad::class, 'cube_id', 'id');
    }

    /**
     * * Relation to `CubeTag` model
     */
    public function tags() : HasMany
    {
        return $this->hasMany(CubeTag::class, 'cube_id', 'id');
    }

    /**
     * * Relation to `OpeningHour` model
     */
    public function opening_hours() : HasMany
    {
        return $this->hasMany(OpeningHour::class, 'cube_id', 'id');
    }

    /**
     * * Relation to `CubeType` model
     */
    public function cube_type() : BelongsTo
    {
        return $this->belongsTo(CubeType::class, 'cube_type_id', 'id');
    }

    /**
     * * Relation to `User` model
     */
    public function user() : BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }

    /**
     * * Relation to `Corporate` model
     */
    public function corporate() : BelongsTo
    {
        return $this->belongsTo(Corporate::class, 'corporate_id', 'id');
    }

    /**
     * * Relation to `World` model
     */
    public function world() : BelongsTo
    {
        return $this->belongsTo(World::class, 'world_id', 'id');
    }

    public function toArray()
    {
        $toArray = parent::toArray();

        $toArray['picture_source'] = $this->picture_source ? asset('storage/' . $this->picture_source) : null;

        return $toArray;
    }

    /**
     * * Generate Cube Number Code
     */
    public function generateCubeCode($type) {

        $zeroPadding = "000000";
        $prefixCode = "CB$type-";
        $code = "$prefixCode";

        $increment = 0;
        $similiarCode = DB::table('cubes')->select('code')
            ->where('code', 'LIKE', "$prefixCode%")
            ->orderBy('created_at', 'desc')
            ->first();

        if (!$similiarCode) {
            $increment = 1;
        } else {
            $increment = (int) substr($similiarCode->code, strlen(($code)));
            $increment = $increment + 1;
        }

        $code = $code . substr($zeroPadding, strlen("$increment")) . $increment;

        return $code;
    }

    /**
     * * Get Nearest Cube by Lat Long
     */
    public function getNearestDataByLatLong($lat, $long, $limit = 10)
    {
        $result = DB::table('cubes')
            ->select([
                'id', 'cube_types_id', 'parent_id', 'user_id', 'corporate_id', 'world_id', 'code',
                'picture_source', 'color', 'map_lat', 'map_lng', 'status', 'inactive_at', 'created_at', 'updated_at',
                DB::raw("
                (
                    (
                        (
                            acos(
                                sin(($lat * pi() / 180))
                                *
                                sin((map_lat * pi() / 180)) + cos(($lat * pi() / 180))
                                *
                                cos((map_lat * pi() / 180)) * cos((($long - map_lng) * pi()/180))
                            )
                        ) * 180/pi()
                    ) * 60 * 1.1515 * 1.609344
                ) as distance
                ")
            ])
            ->orderBy('distance', 'ASC')
            ->limit($limit);

        return $result;
    }
}
