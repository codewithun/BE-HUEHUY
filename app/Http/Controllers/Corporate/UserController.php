<?php

namespace App\Http\Controllers\Corporate;

use App\Http\Controllers\Controller;
use App\Models\CorporateUser;
use App\Models\Role;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class UserController extends Controller
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
        $columnAliases = [
            'created_at' => 'corporate_users.created_at'
        ];

        $credentialUserCorporate = Auth::user()->corporate_user;

        // ? Begin
        $model = new User();
        $query = User::with('role', 'corporate_user', 'corporate_user.corporate', 'corporate_user.role');

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
        $query = $query
            ->join('corporate_users', 'corporate_users.user_id', 'users.id')
            ->where('corporate_users.corporate_id', $credentialUserCorporate->corporate_id)
            ->orderBy($this->remark_column($sortby, $columnAliases), $sortDirection)
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
    // ## Store a new user for corporate member
    // =============================================>
    public function store(Request $request)
    {
        // ? Validate request
        $validation = $this->validation($request->all(), [
            'email' => 'required|string|email|max:255',
            'role_id' => 'nullable|numeric'
        ]);

        if ($validation) return $validation;

        $credentialUserCorporate = Auth::user()->corporate_user;

        // * Check User email
        $user = User::where('email', $request->email)
            ->whereNotNull('verified_at')
            ->first();

        if (!$user) {
            return response([
                "message" => "Error: Unprocessable Entity!",
                "errors" => [
                    "email" => [
                        "User email tidak ditemukan atau belum verif"
                    ]
                ]
            ], 422);
        }

        // * Validate not duplicate user registered
        $checkCorporateUser = CorporateUser::where('corporate_id', $credentialUserCorporate->corporate_id)
            ->where('user_id', $user->id)
            ->first();

        if ($checkCorporateUser) {
            return response([
                "message" => "Error: Unprocessable Entity!",
                "errors" => [
                    "user_id" => [
                        "Duplicate user registered on this corporate"
                    ]
                ]
            ], 422);
        }

        // * Validate role params is role for corporate
        $role = Role::where('id', $request->role_id)
            ->where('is_corporate', 1)
            ->first();

        if (!$role) {
            return response([
                "message" => "Error: Unprocessable Entity!",
                "errors" => [
                    "role_id" => [
                        "The selected role is not for corporate"
                    ]
                ]
            ], 422);
        }

        // ? Initial
        DB::beginTransaction();
        $model = new CorporateUser();

        // ? Dump data
        $model = $this->dump_field($request->all(), $model);
        $model->user_id = $user->id;
        $model->corporate_id = $credentialUserCorporate->corporate_id;

        // ? Executing
        try {
            $model->save();
        } catch (\Throwable $th) {
            DB::rollBack();
            return response([
                "message" => "Error: server side having problem!",
            ], 500);
        }

        DB::commit();

        return response([
            "message" => "success",
            "data" => $model
        ], 201);
    }

    // =============================================>
    // ## Store a newly created resource in storage.
    // =============================================>
    public function createNew(Request $request)
    {
        // ? Validate request
        $validation = $this->validation($request->all(), [
            'role_id' => 'required|numeric',
            'name' => 'required|string|max:100',
            'email' => 'required|email',
            'phone' => 'nullable|string|max:100|unique:users,phone',
            'password' => 'nullable|string|min:8|max:50|confirmed',
            'image' => 'nullable',
        ]);

        if ($validation) return $validation;

        $credentialUserCorporate = Auth::user()->corporate_user;

        // * Validate role params is role for corporate
        $role = Role::where('id', $request->role_id)
            ->where('is_corporate', 1)
            ->first();

        if (!$role) {
            return response([
                "message" => "Error: Unprocessable Entity!",
                "errors" => [
                    "role_id" => [
                        "The selected role is not for corporate"
                    ]
                ]
            ], 422);
        }

        // ? Initial
        DB::beginTransaction();
        $model = new User();
        
        // ? Dump data
        $model = $this->dump_field($request->all(), $model);
        $model->verified_at = Carbon::now();

        // * Password Encryption
        if ($request->password) {
            $model->password = bcrypt($request->password);
        }

        // * Check if has upload file
        if ($request->hasFile('image')) {
            $model->picture_source = $this->upload_file($request->file('image'), 'profile');
        }

        // ? Executing
        try {
            $model->save();
        } catch (\Throwable $th) {
            DB::rollBack();
            return response([
                "message" => "Error: server side having problem!",
                "description" => "Failed to insert user"
            ], 500);
        }

        // ? Create Corporate Member
        try {
            $corporateUser = new CorporateUser();
            $corporateUser->user_id = $model->id;
            $corporateUser->corporate_id = $credentialUserCorporate->corporate_id;
            $corporateUser->role_id = $role->id;
            $corporateUser->save();
        } catch (\Throwable $th) {
            DB::rollBack();
            return response([
                "message" => "Error: server side having problem!",
                "description" => "Failed to insert corporate member"
            ], 500);
        }

        DB::commit();

        return response([
            "message" => "success",
            "data" => $model
        ], 201);
    }

    // ============================================>
    // ## Update the specified resource in storage.
    // ============================================>
    public function update(Request $request, string $id)
    {
        // ? Initial
        DB::beginTransaction();
        $model = User::findOrFail($id);

        // ? Validate request
        $validation = $this->validation($request->all(), [
            'role_id' => 'nullable|numeric'
        ]);

        if ($validation) return $validation;

        $credentialUserCorporate = Auth::user()->corporate_user;

        // * Validate role params is role for corporate
        $role = Role::where('id', $request->role_id)
            ->where('is_corporate', 1)
            ->first();

        if (!$role) {
            return response([
                "message" => "Error: Unprocessable Entity!",
                "errors" => [
                    "role_id" => [
                        "The selected role is not for corporate"
                    ]
                ]
            ], 422);
        }

        // ? Dump data
        $model = $this->dump_field($request->all(), $model);

        // ? Executing
        try {
            $model->save();
        } catch (\Throwable $th) {
            DB::rollBack();
            return response([
                "message" => "Error: server side having problem!",
            ], 500);
        }

        // ? Update user corporate role
        try {
            CorporateUser::where('user_id', $model->id)
                ->where('corporate_id', $credentialUserCorporate->corporate_id)
                ->update([
                    'role_id' => $role->id
                ]);
        } catch (\Throwable $th) {
            return response([
                "message" => "Error: server side having problem!",
                "description" => "Failed to update corporate user role"
            ], 500);
        }

        DB::commit();

        return response([
            "message" => "success",
            "data" => $model
        ]);
    }

    // ===============================================>
    // ## Remove the specified resource from storage.
    // ===============================================>
    public function destroy(string $id)
    {
        $credentialUserCorporate = Auth::user()->corporate_user;

        // ? Initial
        $model = CorporateUser::where('user_id', $id)
            ->where('corporate_id', $credentialUserCorporate->corporate_id)
            ->firstOrFail();

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
}
        