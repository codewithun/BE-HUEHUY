<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Models\Ad;
use App\Models\CorporateUser;
use App\Models\Grab;
use App\Models\SummaryGrab;
use App\Models\Voucher;
use App\Models\VoucherItem;
use App\Models\World;
use App\Services\NotificationService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class GrabController extends Controller
{
    // ========================================>
    // ## Display a listing of the resource.
    // ========================================>
    public function index(Request $request)
    {
        // ? Initial params
        $sortDirection = $request->get("sortDirection", "DESC");
        $sortby = $request->get("sortBy", "created_at");
        $paginate = $request->get("paginate", 10);
        $filter = $request->get("filter", null);

        // ? Preparation
        $columnAliases = [];

        // ? Begin
        $model = new Grab();
        $query = Grab::with([
            'ad', 'ad.ad_category', 'ad.cube', 'ad.cube', 'ad.cube.user', 'ad.cube.corporate', 'ad.cube.world', 'ad.cube.tags',
            'voucher_item', 'voucher_item.voucher'
        ]);

        // ? When search
        if ($request->get("search") != "") {
            $query = $this->search($request->get("search"), $model, $query);
        } else {
            $query = $query;
        }

        // ? When Filter
        if ($filter) {
            $filters = json_decode($filter);
            foreach ($filters as $column => $value) {
                $query = $this->filter($this->remark_column($column, $columnAliases), $value, $model, $query);
            }
        }

        // ? Sort & executing with pagination
        $query = $query->orderBy($this->remark_column($sortby, $columnAliases), $sortDirection)
            ->where('user_id', Auth::id())
            ->select($model->selectable)->paginate($paginate);

        // ? When empty
        if (empty($query->items())) {
            return response([
                "message" => "empty data",
                "data" => [],
            ], 200);
        }

        // ? When success
        return response([
            "message" => "success",
            "data" => $query->all(),
            "total_row" => $query->total(),
        ]);
    }

    // =============================================>
    // ## Store a newly created resource in storage.
    // =============================================>
    public function store(Request $request)
    {
        // ? Validate request
        $validation = $this->validation($request->all(), [
            'ad_id' => 'required|numeric'
        ]);

        if ($validation) return $validation;

        // * Check Ad exists
        $ad = Ad::with('cube')
            ->where('id', $request->ad_id)
            ->where('status', 'active')
            ->first();

        if (!$ad) {
            return response([
                "message" => "Error: Unprocessable Entity!",
                "errors" => [
                    "ad_id" => [
                        "Iklan tidak ditemukan atau status iklan tidak aktif"
                    ]
                ]
            ], 422);
        }

        // * Check is information cube
        if ($ad->cube->is_information == true) {
            return response([
                "message" => "Error: Unprocessable Entity!",
                "errors" => [
                    "ad_id" => [
                        "Promo ini tidak bisa di-grab, karena kubus informasi"
                    ]
                ]
            ], 422);
        }

        // * Check ad is active
        if ($ad->start_validate == null) {
        } else if (
            !(Carbon::now() >= $ad->start_validate && Carbon::now() <= $ad->finish_validate)
        ) {
            return response([
                "message" => "Error: Unprocessable Entity!",
                "errors" => [
                    "ad_id" => [
                        "Promo ini tidak bisa di-grab"
                    ]
                ]
            ], 422);
        }

        // * Check for dupplicate grab
        $check = Grab::where('ad_id', $request->ad_id)
            ->where('user_id', Auth::id())
            ->whereNull('validation_at')
            ->first();

        if($check) {
            return response([
                "message" => "Error: Unprocessable Entity!",
                "errors" => [
                    "ad_id" => [
                        "Kamu sudah merebut promo ini!"
                    ]
                ]
            ], 422);
        }

        // * Check Max Grab Ads
        if ($ad->is_daily_grab) {

            $summaryToday = SummaryGrab::where('ad_id', $request->ad_id)
                ->whereDate('date', Carbon::now())
                ->first();

            if (!$summaryToday) {

                try {
                    $summary = new SummaryGrab();
                    $summary->ad_id = $request->ad_id;
                    $summary->total_grab = 1;
                    $summary->date = Carbon::now();
                    $summary->save();
                } catch (\Throwable $th) {
                    return response([
                        "message" => 'Error: Failed to create summary grab for today',
                    ], 500);
                }
            } else {

                // * Check if in max grab
                if ($ad->max_grab != null && $summaryToday->total_grab >= $ad->max_grab) {
                    return response([
                        "message" => 'Iklan ini sudah melewati maksimal grab',
                        'errors' => [
                            'ad_id' => ['Iklan ini sudah melewati maksimal grab pada hari ini']
                        ]
                    ], 422);
                }

                try {
                    $summaryToday->total_grab += 1;
                    $summaryToday->save();
                } catch (\Throwable $th) {
                    return response([
                        "message" => 'Error: Failed to update summary grab for today',
                    ], 500);
                }
            }
        } else {

            $totalGrab = SummaryGrab::where('ad_id', $request->ad_id)
                ->sum('total_grab');

            // * Check if in max grab
            if ($ad->max_grab != null && $totalGrab >= $ad->max_grab) {
                return response([
                    "message" => 'Iklan ini sudah melewati maksimal grab',
                    'errors' => [
                        'ad_id' => ['Iklan ini sudah melewati maksimal grab']
                    ]
                ], 422);
            }

            $summaryToday = SummaryGrab::where('ad_id', $request->ad_id)
                ->whereDate('date', Carbon::now())
                ->first();

            if (!$summaryToday) {

                try {
                    $summary = new SummaryGrab();
                    $summary->ad_id = $request->ad_id;
                    $summary->total_grab = 1;
                    $summary->date = Carbon::now();
                    $summary->save();
                } catch (\Throwable $th) {
                    info($th);
                    return response([
                        "message" => 'Error: Failed to create summary grab',
                    ], 500);
                }
            } else {

                try {
                    $summaryToday->total_grab += 1;
                    $summaryToday->save();
                } catch (\Throwable $th) {
                    return response([
                        "message" => 'Error: Failed to update summary grab',
                    ], 500);
                }
            }
        }

        // ? Initial
        DB::beginTransaction();
        $model = new Grab();
        $model->code = $model->generateCode();
        $model->user_id = Auth::id();

        // ? Dump data
        $model = $this->dump_field($request->all(), $model);

        if ($ad->validation_time_limit != null) {
            $interval = Carbon::parse($ad->validation_time_limit)->diffAsCarbonInterval();
            $model->expired_at = Carbon::now()->add($interval);
        }

        // ? Executing
        try {
            $model->save();
        } catch (\Throwable $th) {
            DB::rollBack();
            return response([
                "message" => 'Error: failed to create new grab',
                'errors' => $th
            ], 500);
        }

        // ? Generate Voucher Item if Promo Type is `online`
        if ($ad->promo_type == 'online') {
            
            $voucher = Voucher::where('ad_id', $ad->id)
                ->first();

            if ($voucher) {

                $voucherItem = new VoucherItem();
                $voucherItem->user_id = Auth::id();
                $voucherItem->voucher_id = $voucher->id;
                $voucherItem->code = $voucherItem->generateCode();

                try {
                    $voucherItem->save();

                    $model->voucher_item_id = $voucherItem->id;
                    $model->save();
                } catch (\Throwable $th) {
                    DB::rollBack();
                    return response([
                        "message" => 'Error: failed to generate voucher item',
                        'errors' => $th
                    ], 500);
                }
            }
        }

        // ! Unused
        // * Create notifications
        // $notificationMessage = "Promo iklan $ad->title pada kubus " . $ad->cube->code . " telah di-grab oleh seseorang";

        // $notificationType = '';
        // if ($ad->cube->user_id) {
        //     $notificationType = 'USER';
        // } else if ($ad->cube->corporate_id) {
        //     $notificationType = 'CORPORATE';
        // } else if ($ad->cube->world_id) {
        //     $notificationType = 'WORLD_OWNER';
        // }

        // $createNotif = NotificationService::create($notificationType, $notificationMessage, [
        //     // 'ad_id' => $ad->id,
        //     // 'cube_id' => $ad->cube_id,
        //     'grab_id' => $model->id,
        //     'user' => $ad->cube->user_id ? [$ad->cube->user_id] : null,
        //     'corporate' => $ad->cube->corporate_id ?? null,
        //     'world' => $ad->cube->world_id ?? null,
        // ]);
        
        // if ($createNotif['error'] == true) {
        //     return response([
        //         'message' => $createNotif['message'],
        //         'error' => $createNotif['errorMessage']
        //     ], $createNotif['errorCode']);
        // }

        DB::commit();

        return response([
            "message" => "success",
            "data" => $model
        ], 201);
    }

    // ===============================================>
    // ## Remove the specified resource from storage.
    // ===============================================>
    public function destroy(string $id)
    {
        // ? Initial
        $model = Grab::findOrFail($id);

        // ? Executing
        try {
            $model->delete();
        } catch (\Throwable $th) {
            return response([
                "message" => "Error: server side having problem!"
            ], 500);
        }

        return response([
            "message" => "Success",
            "data" => $model
        ]);
    }

    /**
     * * Validate Grab
     */
    public function validateGrab(Request $request)
    {
        $credential = Auth::user();

        // ? Validate request
        $validation = $this->validation($request->all(), [
            'code' => 'required|string|max:10'
        ]);

        if ($validation) return $validation;

        // * Check Cube Exists
        $grab = Grab::where('code', $request->code)
            ->whereNull('validation_by')
            ->whereNull('validation_at')
            ->first();

        if (!$grab) {
            return response([
                "message" => 'Validation Error: Unprocessable Entity!',
                'errors' => [
                    'code' => ['Kode ini tidak valid']
                ]
            ], 422);
        }

        $ad = $grab->ad;
        $cube = $ad->cube;

        // * Check the ad or cube is the Validator's mine
        if ($cube->corporate_id != null) {

            $corporateMember = CorporateUser::where('corporate_id', $cube->corporate_id)
                ->where('user_id', $credential->id)
                ->first();

            if (!$corporateMember) {
                return response([
                    "message" => 'Gagal validasi promo. validator tidak memiliki wewenang',
                ], 403);
            }
        } else if ($cube->world_id != null) {

            $check = World::select([
                'corporate_users.user_id as user_id'
            ])
                ->join('corporates', 'corporates.id', 'worlds.corporate_id')
                ->join('corporate_users', 'corporate_users.corporate_id', 'corporates.id')
                ->where('worlds.id', $cube->world_id)
                ->where('corporate_users.user_id', $credential->id)
                ->first();

            if (!$check) {
                return response([
                    "message" => 'Gagal validasi promo. validator tidak memiliki wewenang kubus dunia',
                ], 403);
            }
        }

        // * Check grab expired
        if (Carbon::now() > $grab->expired_at) {
            return response([
                "message" => 'Validation Error: Unprocessable Entity!',
                'errors' => [
                    'code' => ['Tidak bisa validasi grab ini karena telah expired']
                ]
            ], 422);
        }

        $grab->validation_by = $credential->id;
        $grab->validation_at = Carbon::now();

        try {
            $grab->save();
        } catch (\Throwable $th) {
            DB::rollBack();
            return response([
                "message" => 'Error: failed to update validated grab data',
            ], 500);
        }

        // * Create notifications to User's Grab
        $notificationMessage = "Promo $ad->title pada kubus " . $ad->cube->code . " telah tervalidasi";

        $createNotif = NotificationService::create('USER', $notificationMessage, [
            // 'ad_id' => $ad->id,
            // 'cube_id' => $ad->cube_id,
            'grab_id' => $grab->id,
            'user' => [$grab->user_id]
        ], 'hunter');

        if ($createNotif['error'] == true) {
            return response([
                'message' => $createNotif['message'],
                'error' => $createNotif['errorMessage']
            ], $createNotif['errorCode']);
        }

        return response([
            'message' => 'Success',
            'data' => $grab
        ]);
    }

    // ========================================>
    // ## Display a listing of the resource.
    // ========================================>
    public function validatedHistory(Request $request)
    {
        // ? Initial params
        $sortDirection = $request->get("sortDirection", "DESC");
        $sortby = $request->get("sortBy", "created_at");
        $paginate = $request->get("paginate", 10);
        $filter = $request->get("filter", null);

        $credential = Auth::user();

        // ? Preparation
        $columnAliases = [];

        // ? Begin
        $model = new Grab();
        $query = Grab::with('user', 'ad', 'ad.ad_category', 'ad.cube', 'ad.cube.cube_type', 'ad.cube.user', 'ad.cube.corporate', 'ad.cube.world', 'ad.cube.tags');

        // ? When search
        if ($request->get("search") != "") {
            $query = $this->search($request->get("search"), $model, $query);
        } else {
            $query = $query;
        }

        // ? When Filter
        if ($filter) {
            $filters = json_decode($filter);
            foreach ($filters as $column => $value) {
                $query = $this->filter($this->remark_column($column, $columnAliases), $value, $model, $query);
            }
        }

        // ? Sort & executing with pagination
        $query = $query->orderBy($this->remark_column($sortby, $columnAliases), $sortDirection)
            ->where('validation_by', $credential->id)
            ->select($model->selectable)->paginate($paginate);

        // ? When empty
        if (empty($query->items())) {
            return response([
                "message" => "empty data",
                "data" => [],
                'auth' => $credential
            ], 200);
        }

        // ? When success
        return response([
            "message" => "success",
            "data" => $query->all(),
            "total_row" => $query->total(),
        ]);
    }
}
        