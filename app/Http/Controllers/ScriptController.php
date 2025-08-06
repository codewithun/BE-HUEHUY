<?php

namespace App\Http\Controllers;

use App\Models\Ad;
use App\Models\Cube;
use App\Models\Grab;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ScriptController extends Controller
{
    /**
     * * Script for check expired activate cubes then remove it
     */
    public function checkExpiredActivateCubes(Request $request)
    {
        $validatedToken = $this->validateToken($request);

        if (!$validatedToken['success']) {
            return response()->json([
                'success' => $validatedToken['success'],
                'message' => $validatedToken['message']
            ], $validatedToken['code']);
        }

        DB::beginTransaction();

        $cubes = Cube::with('tags', 'ads')
            ->whereDate('expired_activate_date', '<=', Carbon::now())
            ->get();

        foreach ($cubes as $cube) {

            // * Remove image
            if ($cube->picture_source) {

                if (Storage::disk('public')->exists($cube->picture_source)) {
                    Storage::disk('public')->delete($cube->picture_source);
                }
            }

            if (count($cube->tags) > 0) {

                $cube->tags()->delete();
            }

            if (count($cube->ads) > 0) {

                $ids = $cube->ads->map(function ($val) {
                    return $val->id;
                });

                // * Deactive ads
                Ad::whereIn('id', $ids)->update([
                    'status' => 'inactive'
                ]);
            }
        }

        DB::commit();

        return response([
            'success' => true,
            'message' => 'checkClosedProductSpecialOffer job drained successfully.',
            'total_data' => count($cubes) . " data removed",
        ]);
    }

    /**
     * * Script for flush the datasource log
     */
    public function flushDatasourceLog(Request $request)
    {
        $validatedToken = $this->validateToken($request);

        if (!$validatedToken['success']) {
            return response()->json([
                'success' => $validatedToken['success'],
                'message' => $validatedToken['message']
            ], $validatedToken['code']);
        }

        try {
            DB::table('datasource_logs')->truncate();
        } catch (\Throwable $err) {
            return response([
                'success' => false,
                'message' => "Error: failed to truncate the datasource log"
            ], 500);
        }

        return response([
            'success' => true,
            'message' => 'flushDatasourceLog job drained successfully.'
        ]);
    }

    /**
     * * Script for check cube expirate
     */
    public function checkCubeExpired(Request $request)
    {
        $validatedToken = $this->validateToken($request);

        if (!$validatedToken['success']) {
            return response()->json([
                'success' => $validatedToken['success'],
                'message' => $validatedToken['message']
            ], $validatedToken['code']);
        }

        DB::beginTransaction();

        $cubes = Cube::whereNotNull('inactive_at')
            ->whereDate('inactive_at', '<=', Carbon::now())
            ->where('status', 'active')
            ->get();

        $cubesId = $cubes->map(function (Cube $cube) {
            return $cube->id;
        });

        // * Update cube status
        try {
            Cube::whereIn('id', $cubesId)->update([
                'status' => 'inactive'
            ]);
        } catch (\Throwable $th) {
            DB::rollBack();
            return response([
                'success' => false,
                'message' => "Error: failed to update cubes status",
                'error' => $th
            ], 500);
        }

        // * Update ads status
        try {
            Ad::whereIn('cube_id', $cubesId)->update([
                'status' => 'expired'
            ]);
        } catch (\Throwable $th) {
            DB::rollBack();
            return response([
                'success' => false,
                'message' => "Error: failed to update ads status",
                'error' => $th
            ], 500);
        }

        /**
         * * Remove grab if ads is expired/inactive
         */
        try {
            Grab::join('ads', 'ads.id', 'grabs.ad_id')
                ->join('cubes', 'cubes.id', 'ads.cube_id')
                ->where('ads.status', '!=', 'active')
                ->whereNull('grabs.validation_at')
                ->delete();
        } catch (\Throwable $th) {
            DB::rollBack();
            return response([
                'success' => false,
                'message' => "Error: failed to remove grab data",
                'error' => $th
            ], 500);
        }

        DB::commit();

        return response([
            'success' => true,
            'message' => 'checkCubeExpired job drained successfully.'
        ]);
    }



    private function validateToken($requestData)
    {
        $scriptToken = env('SCRIPT_TOKEN');
        $authToken = $requestData->header('Authorization');

        /**
         * * Check API Key Token with Script Token Defined
         */
        if ($authToken != $scriptToken) {
            return [
                'success' => false,
                'message' => 'API token script is invalid',
                'code' => 401
            ];
        }

        return [
            'success' => true,
            'message' => 'Token valid!',
            'code' => 200
        ];
    }
}
