<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Corporate;
use App\Models\CorporateUser;
use App\Models\Role;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CorporateController extends Controller
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
        $model = new Corporate();
        $query = Corporate::query();

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
            'name' => 'required|string|max:255',
            'description' => 'required|string|max:255',
            'address' => 'required|string|max:255',
            'phone' => 'required|string|max:100',
        ]);

        if ($validation) return $validation;

        // ? Initial
        DB::beginTransaction();
        $model = new Corporate();

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
        $model = Corporate::findOrFail($id);

        // ? Validate request
        $validation = $this->validation($request->all(), [
            'name' => 'required|string|max:255',
            'description' => 'required|string|max:255',
            'address' => 'required|string|max:255',
            'phone' => 'required|string|max:100',
        ]);

        if ($validation) return $validation;

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
        // ? Initial
        $model = Corporate::findOrFail($id);

        DB::beginTransaction();

        // * Remove Corporate User
        try {
            $model->corporate_users()->delete();
        } catch (\Throwable $th) {
            DB::rollBack();
            return response([
                "message" => "Error: server side having problem!",
                "description" => "Failed to delete user corporate"
            ], 500);
        }

        // ? Executing
        try {
            $model->delete();
        } catch (\Throwable $th) {
            DB::rollBack();
            return response([
                "message" => "Error: server side having problem!",
                "description" => "Failed to delete corporate"
            ], 500);
        }

        DB::commit();

        return response([
            "message" => "Success",
            "data" => $model
        ]);
    }

    // ============================================>
    // ## Update the specified point.
    // ============================================>
    public function updatePoint(Request $request, string $id)
    {
        // ? Initial
        DB::beginTransaction();
        $model = Corporate::findOrFail($id);

        // ? Validate request
        $validation = $this->validation($request->all(), [
            'point' => 'required|numeric|min:0',
            'log_description' => 'nullable|string|max:255'
        ]);

        if ($validation) return $validation;

        // ? Update data
        $model->point = $request->point;

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
        ]);
    }

    // ========================================>
    // ## Display a listing of the resource.
    // ========================================>
    public function getCorporateMember(Request $request, $id)
    {
        // ? Initial params
        $sortDirection = $request->get("sortDirection", "DESC");
        $sortby = $request->get("sortBy", "created_at");
        $paginate = $request->get("paginate", 10);
        $filter = $request->get("filter", null);

        // ? Preparation
        $columnAliases = [];

        // ? Begin
        $model = new CorporateUser();
        $query = CorporateUser::with('user', 'user.role', 'role');

        // ? When search
        if ($request->get("search") != "") {
            $query = $this->search($request->get("search"), $model, $query, ['user.name', 'user.email']);
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
        $query = $query->where('corporate_id', $id)
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
    // ## Add Corporate User/Member by Corporate ID.
    // =============================================>
    public function addCorporateMember(Request $request, $id)
    {
        // ? Validate request
        $validation = $this->validation($request->all(), [
            'email' => 'required|string|email|max:255',
            'role_id' => 'nullable|numeric'
        ]);

        if ($validation) return $validation;

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
        $checkCorporateUser = CorporateUser::where('corporate_id', $id)
            ->where('user_id', $user->id)
            ->first();

        if ($checkCorporateUser) {
            return response([
                "message" => "Error: Unprocessable Entity!",
                "errors" => [
                    "email" => [
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

        DB::beginTransaction();

        // ? Create Corporate Member
        $corporateUser = new CorporateUser();
        $corporateUser->user_id = $user->id;
        $corporateUser->role_id = $role->id;
        $corporateUser->corporate_id = $id;
        
        try {
            $corporateUser->save();
            
            // * UPDATE: Sinkronisasi role_id di tabel users
            $user->role_id = $role->id;
            $user->save();
            
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
            "data" => $corporateUser
        ], 201);
    }

    // =============================================>
    // ## Create New Corporate User/Member by Corporate ID.
    // =============================================>
    public function createCorporateMember(Request $request, $id)
    {
        // ? Validate request
        $validation = $this->validation($request->all(), [
            'role_id' => 'required|numeric',
            'name' => 'required|string|max:100',
            'email' => 'required|email|unique:users,email',
            'phone' => 'nullable|string|max:100|unique:users,phone',
            'password' => 'nullable|string|min:8|max:50|confirmed',
            'image' => 'nullable',
        ]);

        if ($validation) return $validation;

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
        $model->role_id = $role->id; // * Set role_id corporate

        // * Password Encryption
        if ($request->password) {
            $model->password = bcrypt($request->password);
        }

        // * Check if has upload file
        if ($request->hasFile('image')) {
            $model->picture_source = $this->upload_file($request->file('image'), 'profile');
        }

        // ? Create User
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
            $corporateUser->corporate_id = $id;
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

    // ===============================================>
    // ## Remove Corporate User/Member by Corporate ID.
    // ===============================================>
    public function destroyCorporateMember(string $id, $corporateUserId)
    {
        // ? Initial
        $model = CorporateUser::where('corporate_id', $id)
            ->where('id', $corporateUserId)
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

    // ===============================================>
    // ## Remove Corporate User/Member by Corporate ID.
    // ===============================================>
    public function updateCorporateMemberRole(Request $request, string $id, $corporateUserId)
    {
        // ? Validate request
        $validation = $this->validation($request->all(), [
            'role_id' => 'required|numeric',
        ]);

        if ($validation) return $validation;

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

        $model = CorporateUser::where('corporate_id', $id)
            ->where('id', $corporateUserId)
            ->firstOrFail();
        
        $model->role_id = $role->id;

        // ? Executing
        try {
            $model->save();
            
            // * UPDATE: Sinkronisasi role_id di tabel users
            User::where('id', $model->user_id)
                ->update([
                    'role_id' => $role->id
                ]);
                
        } catch (\Throwable $th) {
            DB::rollBack();
            return response([
                "message" => "Error: server side having problem!",
                "description" => "Failed to update corporate user role"
            ], 500);
        }

        DB::commit();

        return response([
            "message" => "Success",
            "data" => $model
        ]);
    }
}
