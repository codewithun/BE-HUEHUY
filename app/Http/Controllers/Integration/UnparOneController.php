<?php

namespace App\Http\Controllers\Integration;

use App\Http\Controllers\Controller;
use App\Models\Ad;
use App\Models\AdCategory;
use App\Models\Cube;
use App\Models\DatasourceLog;
use App\Models\Grab;
use App\Models\SummaryGrab;
use App\Models\User;
use App\Models\UserWorld;
use App\Models\World;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class UnparOneController extends Controller
{
    public function login(Request $request)
    {
        /**
         * * Create Datasource Log
         */
        $logId = null;
        $logDatasource = new DatasourceLog();
        $logDatasource->user_id = null;
        $logDatasource->datasource = 'UNPARONE';
        $logDatasource->ip = $request->ip();
        $logDatasource->url = $request->fullUrl();
        $logDatasource->request_method = 'POST';
        $logDatasource->request_time = Carbon::now();
        $logDatasource->additional_headerparams = json_encode($request->header());
        $logDatasource->additional_bodyparams = json_encode($request->all());
        $logDatasource->log_type = 'MANUAL';
        $logDatasource->log_bound = 'INBOUND';

        try {
            $logDatasource->save();
            $logId = $logDatasource->id;
        } catch (\Throwable $err) {
            return response([
                'success' => false,
                'message' => "Error: failed to create datasource log"
            ], 500);
        }

        // * Validate Request
        $validate = Validator::make($request->all(), [
            "email" => 'required|string|max:255|exists:users,email',
            "password" => 'required',
            // "scope" => ['required', 'string', Rule::in(['admin', 'corporate', 'user'])]
        ]);

        if ($validate->fails()) {

            $this->_updateLogDatasource($logId, 'failed', json_encode([
                'success' => false,
                'message' => $validate->errors(),
                'error_code' => 422
            ]));

            return response()->json([
                'message' => "Error: Validation Error!",
                'errors' => $validate->errors(),
            ], 422);
        }

        // * Find the User
        $user = User::where('email', $request->email)
            ->first();

        if (!$user) {

            $this->_updateLogDatasource($logId, 'failed', json_encode([
                'success' => false,
                'message' => [
                    'email' => ['Data tidak ditemukan']
                ],
                'error_code' => 422
            ]));

            return response([
                'message' => 'User not found',
                'errors' => [
                    'email' => ['Data tidak ditemukan']
                ]
            ], 422);
        }

        // * Check Password
        if (!Hash::check($request->password, $user->password)) {

            $this->_updateLogDatasource($logId, 'failed', json_encode([
                'success' => false,
                'message' => ['password' => ['Password salah!']],
                'error_code' => 422
            ]));

            return response()->json([
                'message' => 'Wrong username or password in our records',
                'errors' => ['password' => ['Password salah!']],
            ], 422);
        }

        // * Create Token
        $userToken = $user->createToken('sanctum')->plainTextToken;

        /**
         * * Update Success Datasource Log
         */
        $this->_updateLogDatasource($logId, 'success', json_encode([
            'success' => true,
            'data' => $userToken,
            'error_code' => 200
        ]));

        return response([
            'message' => 'Success',
            'data' => $user,
            "token" => $userToken,
        ]);
    }

    public function register(Request $request)
    {
        /**
         * * Create Datasource Log
         */
        $logId = null;
        $logDatasource = new DatasourceLog();
        $logDatasource->user_id = null;
        $logDatasource->datasource = 'UNPARONE';
        $logDatasource->ip = $request->ip();
        $logDatasource->url = $request->fullUrl();
        $logDatasource->request_method = 'POST';
        $logDatasource->request_time = Carbon::now();
        $logDatasource->additional_headerparams = json_encode($request->header());
        $logDatasource->additional_bodyparams = json_encode($request->all());
        $logDatasource->log_type = 'MANUAL';
        $logDatasource->log_bound = 'INBOUND';

        try {
            $logDatasource->save();
            $logId = $logDatasource->id;
        } catch (\Throwable $err) {
            return response([
                'success' => false,
                'message' => "Error: failed to create datasource log"
            ], 500);
        }

        // * Validate Request
        $validate = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'phone' => 'nullable|string|max:100',
            'password' => 'nullable|string|min:8|max:50|confirmed',
            'image' => 'nullable'
        ]);

        if ($validate->fails()) {

            $this->_updateLogDatasource($logId, 'failed', json_encode([
                'success' => false,
                'message' => $validate->errors(),
                'error_code' => 422
            ]));

            return response()->json([
                'message' => "Error: Unprocessable Entity! Validation Error",
                'errors' => $validate->errors(),
            ], 422);
        }

        DB::beginTransaction();

        // * Create new User
        $user = new User();
        $user->role_id = 2; //default role for 'user'
        $user->name = $request->name;
        $user->email = $request->email;
        $user->phone = $request->phone;
        $user->password = Hash::make($request->password);
        $user->verified_at = Carbon::now();

        // Check if has upload file
        if ($request->hasFile('image')) {
            $user->picture_source = $this->upload_file($request->file('image'), 'profile');
        }

        try {
            $user->save();
        } catch (\Throwable $th) {
            DB::rollback();

            $this->_updateLogDatasource($logId, 'failed', json_encode([
                'success' => false,
                'message' => "Error: failed to insert new user record!",
                'error_code' => 500
            ]));

            return response([
                'message' => "Error: failed to insert new user record!",
            ], 500);
        }

        // * Register User to Unpar World
        try {
            UserWorld::create([
                'user_id' => $user->id,
                'world_id' => 1 //default world for `unpar`
            ]);
        } catch (\Throwable $th) {
            DB::rollback();

            $this->_updateLogDatasource($logId, 'failed', json_encode([
                'success' => false,
                'message' => "Error: failed to insert new unpar world member!",
                'error_code' => 500
            ]));

            return response([
                'message' => "Error: failed to insert new unpar world member!",
            ], 500);
        }

        DB::commit();

        /**
         * * Update Success Datasource Log
         */
        $this->_updateLogDatasource($logId, 'success', json_encode([
            'success' => true,
            'data' => $user,
            'error_code' => 200
        ]));

        // * Create Token
        $userToken = $user->createToken('sanctum')->plainTextToken;

        return response([
            'message' => 'Success',
            'data' => $user,
            'token' => $userToken,
        ]);
    }

    public function account(Request $request)
    {
        /**
         * * Create Datasource Log
         */
        $logId = null;
        $logDatasource = new DatasourceLog();
        $logDatasource->user_id = null;
        $logDatasource->datasource = 'UNPARONE';
        $logDatasource->ip = $request->ip();
        $logDatasource->url = $request->fullUrl();
        $logDatasource->request_method = 'GET';
        $logDatasource->request_time = Carbon::now();
        $logDatasource->additional_headerparams = json_encode($request->header());
        $logDatasource->additional_bodyparams = json_encode($request->all());
        $logDatasource->log_type = 'MANUAL';
        $logDatasource->log_bound = 'INBOUND';

        try {
            $logDatasource->save();
            $logId = $logDatasource->id;
        } catch (\Throwable $err) {
            return response([
                'success' => false,
                'message' => "Error: failed to create datasource log"
            ], 500);
        }

        // * Find Data
        $user = Auth::user();
        $user->role = $user->role;
        $user->cubes = $user->cubes;
        if ($user->corporate_user) {
            $user->corporate_user->role = $user->corporate_user->role;
            $user->corporate_user = $user->corporate_user->corporate;
        }

        /**
         * * Update Success Datasource Log
         */
        $this->_updateLogDatasource($logId, 'success', json_encode([
            'success' => true,
            'data' => $user,
            'error_code' => 200
        ]), $user->id);

        // * Response
        return response([
            'message' => 'Success',
            'data' => $user
        ]);
    }

    public function picklistWorld()
    {
        return World::where(function ($query) {
            return $query->where('corporate_id', 1) // static for `unpar` corporate
                ->orWhere('type', 'general');
        })->get(['id as value', 'name as label']);
    }

    public function getPrimaryCategory(Request $request)
    {
        /**
         * * Create Datasource Log
         */
        $logId = null;
        $logDatasource = new DatasourceLog();
        $logDatasource->user_id = null;
        $logDatasource->datasource = 'UNPARONE';
        $logDatasource->ip = $request->ip();
        $logDatasource->url = $request->fullUrl();
        $logDatasource->request_method = 'GET';
        $logDatasource->request_time = Carbon::now();
        $logDatasource->additional_headerparams = json_encode($request->header());
        $logDatasource->additional_bodyparams = json_encode($request->all());
        $logDatasource->log_type = 'MANUAL';
        $logDatasource->log_bound = 'INBOUND';

        try {
            $logDatasource->save();
            $logId = $logDatasource->id;
        } catch (\Throwable $err) {
            return response([
                'success' => false,
                'message' => "Error: failed to create datasource log"
            ], 500);
        }

        $model = AdCategory::where('is_primary_parent', true)
            ->limit(6)
            ->get();

        /**
         * * Update Success Datasource Log
         */
        $this->_updateLogDatasource($logId, 'success', json_encode([
            'success' => true,
            'data' => $model,
            'error_code' => 200
        ]));

        return response([
            'message' => 'Success',
            'data' => $model
        ]);
    }

    public function getAdCategory(Request $request)
    {
        /**
         * * Create Datasource Log
         */
        $logId = null;
        $logDatasource = new DatasourceLog();
        $logDatasource->user_id = null;
        $logDatasource->datasource = 'UNPARONE';
        $logDatasource->ip = $request->ip();
        $logDatasource->url = $request->fullUrl();
        $logDatasource->request_method = 'GET';
        $logDatasource->request_time = Carbon::now();
        $logDatasource->additional_headerparams = json_encode($request->header());
        $logDatasource->additional_bodyparams = json_encode($request->all());
        $logDatasource->log_type = 'MANUAL';
        $logDatasource->log_bound = 'INBOUND';

        try {
            $logDatasource->save();
            $logId = $logDatasource->id;
        } catch (\Throwable $err) {
            return response([
                'success' => false,
                'message' => "Error: failed to create datasource log"
            ], 500);
        }

        $user = Auth::user();

        $homeCategory = AdCategory::where('is_home_display', true)
            ->get();

        foreach ($homeCategory as $key => $category) {

            $ads = Ad::with('ad_category', 'cube')
                ->select('ads.*')
                ->leftJoin('ad_categories', 'ad_categories.id', 'ads.ad_category_id')
                ->leftJoin('ad_categories as parent_category', 'parent_category.id', 'ad_categories.parent_id')
                ->leftJoin('ad_categories as grand_category', 'grand_category.id', 'parent_category.parent_id')
                ->orWhere('ads.ad_category_id', $category->id)
                ->orWhere('ad_categories.parent_id', $category->id)
                ->orWhere('parent_category.parent_id', $category->id)
                ->orWhere('grand_category.parent_id', $category->id)
                ->distinct('ads.id')
                ->limit(5)
                ->get();

            $childCategory = AdCategory::where('parent_id', $category->id)
                ->get();

            $category->child_categories = $childCategory;
            $category->ads = $ads;
        }

        /**
         * * Update Success Datasource Log
         */
        $this->_updateLogDatasource($logId, 'success', json_encode([
            'success' => true,
            'data' => $homeCategory,
            'error_code' => 200
        ]), $user->id);

        return response([
            'message' => 'Success',
            'data' => $homeCategory
        ]);
    }

    public function getCube(Request $request)
    {
        /**
         * * Create Datasource Log
         */
        $logId = null;
        $logDatasource = new DatasourceLog();
        $logDatasource->user_id = null;
        $logDatasource->datasource = 'UNPARONE';
        $logDatasource->ip = $request->ip();
        $logDatasource->url = $request->fullUrl();
        $logDatasource->request_method = 'GET';
        $logDatasource->request_time = Carbon::now();
        $logDatasource->additional_headerparams = json_encode($request->header());
        $logDatasource->additional_bodyparams = json_encode($request->all());
        $logDatasource->log_type = 'MANUAL';
        $logDatasource->log_bound = 'INBOUND';

        try {
            $logDatasource->save();
            $logId = $logDatasource->id;
        } catch (\Throwable $err) {
            return response([
                'success' => false,
                'message' => "Error: failed to create datasource log"
            ], 500);
        }

        // ? Initial params
        $sortDirection = $request->get("sortDirection", "DESC");
        $sortby = $request->get("sortBy", "created_at");
        $paginate = $request->get("paginate", 10);
        $filter = $request->get("filter", null);

        // ? Preparation
        $columnAliases = [];

        // ? Begin
        $model = new Cube();
        $query = Cube::with([
            'opening_hours', 'cube_type', 'user', 'corporate', 'world', 'opening_hours', 
            'ads' => function ($query) {
                return $query->select([
                        'ads.*',
                        DB::raw('CAST(IF(ads.is_daily_grab = 1,
                            (SELECT SUM(total_grab) FROM summary_grabs WHERE date = DATE(NOW()) AND ad_id = ads.id),
                            SUM(total_grab)
                        ) AS SIGNED) AS total_grab'),
                        DB::raw('CAST(IF(ads.is_daily_grab = 1,
                            ads.max_grab - (SELECT SUM(total_grab) FROM summary_grabs WHERE date = DATE(NOW()) AND ad_id = ads.id),
                            ads.max_grab - SUM(total_grab)
                        ) AS SIGNED) AS total_remaining'),
                    ])
                    ->leftJoin('summary_grabs', 'summary_grabs.ad_id', 'ads.id')
                    ->groupBy('ads.id')
                    ->get();
            },
            'ads.ad_category'
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
            ->select($model->selectable)->paginate($paginate);

        // ? When empty
        if (empty($query->items())) {

            /**
             * * Update Success Datasource Log
             */
            $this->_updateLogDatasource($logId, 'success', json_encode([
                'success' => true,
                'data' => [],
                'error_code' => 200
            ]));

            return response([
                "message" => "empty data",
                "data" => [],
            ], 200);
        }

        /**
         * * Update Success Datasource Log
         */
        $this->_updateLogDatasource($logId, 'success', json_encode([
            'success' => true,
            'data' => $query->all(),
            'error_code' => 200
        ]));

        // ? When success
        return response([
            "message" => "success",
            "data" => $query->all(),
            "total_row" => $query->total(),
        ]);
    }

    public function getGrab(Request $request)
    {
        /**
         * * Create Datasource Log
         */
        $logId = null;
        $logDatasource = new DatasourceLog();
        $logDatasource->user_id = null;
        $logDatasource->datasource = 'UNPARONE';
        $logDatasource->ip = $request->ip();
        $logDatasource->url = $request->fullUrl();
        $logDatasource->request_method = 'GET';
        $logDatasource->request_time = Carbon::now();
        $logDatasource->additional_headerparams = json_encode($request->header());
        $logDatasource->additional_bodyparams = json_encode($request->all());
        $logDatasource->log_type = 'MANUAL';
        $logDatasource->log_bound = 'INBOUND';

        try {
            $logDatasource->save();
            $logId = $logDatasource->id;
        } catch (\Throwable $err) {
            return response([
                'success' => false,
                'message' => "Error: failed to create datasource log"
            ], 500);
        }

        // ? Initial params
        $sortDirection = $request->get("sortDirection", "DESC");
        $sortby = $request->get("sortBy", "created_at");
        $paginate = $request->get("paginate", 10);
        $filter = $request->get("filter", null);

        // ? Preparation
        $columnAliases = [];

        // ? Begin
        $model = new Grab();
        $query = Grab::with('ad', 'ad.category_id', 'ad.cube', 'ad.cube', 'ad.cube.user', 'ad.cube.corporate', 'ad.cube.world', 'ad.cube.tags');

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

            /**
             * * Update Success Datasource Log
             */
            $this->_updateLogDatasource($logId, 'success', json_encode([
                'success' => true,
                'data' => [],
                'error_code' => 200
            ]));

            return response([
                "message" => "empty data",
                "data" => [],
            ], 200);
        }

        /**
         * * Update Success Datasource Log
         */
        $this->_updateLogDatasource($logId, 'success', json_encode([
            'success' => true,
            'data' => $query->all(),
            'error_code' => 200
        ]));

        // ? When success
        return response([
            "message" => "success",
            "data" => $query->all(),
            "total_row" => $query->total(),
        ]);
    }

    public function storeGrab(Request $request)
    {
        /**
         * * Create Datasource Log
         */
        $logId = null;
        $logDatasource = new DatasourceLog();
        $logDatasource->user_id = null;
        $logDatasource->datasource = 'UNPARONE';
        $logDatasource->ip = $request->ip();
        $logDatasource->url = $request->fullUrl();
        $logDatasource->request_method = 'GET';
        $logDatasource->request_time = Carbon::now();
        $logDatasource->additional_headerparams = json_encode($request->header());
        $logDatasource->additional_bodyparams = json_encode($request->all());
        $logDatasource->log_type = 'MANUAL';
        $logDatasource->log_bound = 'INBOUND';

        try {
            $logDatasource->save();
            $logId = $logDatasource->id;
        } catch (\Throwable $err) {
            return response([
                'success' => false,
                'message' => "Error: failed to create datasource log"
            ], 500);
        }

        // ? Validate request
        $validation = $this->validation($request->all(), [
            'ad_id' => 'required|numeric'
        ]);

        if ($validation) return $validation;

        // * Check Ad exists
        $ad = Ad::with('cube')
            ->where('id', $request->ad_id)
            ->first();

        if (!$ad) {
            return response([
                "message" => "Error: Unprocessable Entity!",
                "errors" => [
                    "ad_id" => [
                        "Iklan tidak ditemukan"
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
                if ($summaryToday->total_grab >= $ad->max_grab) {
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
            if ($totalGrab >= $ad->max_grab) {
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

        // ? Executing
        try {
            $model->save();
        } catch (\Throwable $th) {

            /**
             * * Update Failed Datasource Log
             */
            $this->_updateLogDatasource($logId, 'failed', json_encode([
                'success' => false,
                'message' => "Error: failed to insert new user record!",
                'error_code' => 500
            ]), Auth::id());

            DB::rollBack();
            return response([
                "message" => $th,
            ], 500);
        }

        DB::commit();

        /**
         * * Update Success Datasource Log
         */
        $this->_updateLogDatasource($logId, 'success', json_encode([
            'success' => true,
            'data' => $model,
            'error_code' => 200
        ]), Auth::id());

        return response([
            "message" => "success",
            "data" => $model
        ], 201);
    }



    /**
     * Update Log Datasource
     */
    private function _updateLogDatasource($logId, $status, $responseData, int $user = null)
    {
        DatasourceLog::findOrFail($logId)->update([
            'user_id' => $user,
            'finish_time' => Carbon::now(),
            'request_status' => $status,
            'response_data' => $responseData
        ]);
    }
}
