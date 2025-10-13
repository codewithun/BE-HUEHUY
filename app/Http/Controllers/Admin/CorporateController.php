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
        $paginateRaw = $request->get("paginate", 10);
        $paginateAll = ($paginateRaw === 'all' || (int)$paginateRaw === 0);
        $filter = $request->get("filter", null);

        // ? Preparation
        $columnAliases = [];

        // ? Begin
        $model = new Corporate();
        $query = Corporate::query();

        // Safe selectable fallback
        $selectable = property_exists($model, 'selectable') && is_array($model->selectable)
            ? $model->selectable
            : ['*'];

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

        // ? Sort & executing
        $query = $query->orderBy($this->remark_column($sortby, $columnAliases), $sortDirection);

        if ($paginateAll) {
            $items = $query->select($selectable)->get();

            if ($items->isEmpty()) {
                return response([
                    "message" => "empty data",
                    "data" => [],
                ], 200);
            }

            return response([
                "message" => "success",
                "data" => $items,
                "total_row" => $items->count(),
            ]);
        }

        $page = $query->select($selectable)->paginate((int)$paginateRaw);

        if (empty($page->items())) {
            return response([
                "message" => "empty data",
                "data" => [],
            ], 200);
        }

        return response([
            "message" => "success",
            "data" => $page->all(),
            "total_row" => $page->total(),
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
        $paginateRaw = $request->get("paginate", 10);
        $paginateAll = ($paginateRaw === 'all' || (int)$paginateRaw === 0);
        $filter = $request->get("filter", null);

        // ? Preparation
        $columnAliases = [];

        // ? Begin
        $model = new CorporateUser();
        $query = CorporateUser::with('user', 'user.role', 'role')->where('corporate_id', $id);

        // Safe selectable fallback
        $selectable = property_exists($model, 'selectable') && is_array($model->selectable)
            ? $model->selectable
            : ['*'];

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

        // ? Sort & executing
        $query = $query->orderBy($this->remark_column($sortby, $columnAliases), $sortDirection);

        if ($paginateAll) {
            $items = $query->select($selectable)->get();

            if ($items->isEmpty()) {
                return response([
                    "message" => "empty data",
                    "data" => [],
                ], 200);
            }

            return response([
                "message" => "success",
                "data" => $items,
                "total_row" => $items->count(),
            ]);
        }

        $page = $query->select($selectable)->paginate((int)$paginateRaw);

        if (empty($page->items())) {
            return response([
                "message" => "empty data",
                "data" => [],
            ], 200);
        }

        return response([
            "message" => "success",
            "data" => $page->all(),
            "total_row" => $page->total(),
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
            'corporate_role_id' => 'required|numeric|exists:roles,id,is_corporate,1'
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
        $corporateRole = Role::where('id', $request->corporate_role_id)
            ->where('is_corporate', 1)
            ->first();

        if (!$corporateRole) {
            return response([
                "message" => "Error: Unprocessable Entity!",
                "errors" => [
                    "corporate_role_id" => [
                        "The selected role is not for corporate"
                    ]
                ]
            ], 422);
        }

        DB::beginTransaction();

        // ? Create Corporate Member
        $corporateUser = new CorporateUser();
        $corporateUser->user_id = $user->id;
        $corporateUser->role_id = $corporateRole->id;
        $corporateUser->corporate_id = $id;
        
        try {
            $corporateUser->save();
            
            // Note: Do NOT sync corporate role to users.role_id (global). Keep global role unchanged.
            
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
            'corporate_role_id' => 'required|numeric|exists:roles,id,is_corporate,1', // Role corporate
            'name' => 'required|string|max:100',
            'email' => 'required|email|unique:users,email',
            'phone' => 'nullable|string|max:100|unique:users,phone',
            'password' => 'nullable|string|min:8|max:50|confirmed',
            'image' => 'nullable',
        ]);

        if ($validation) return $validation;

        // * Validate corporate role params
        $corporateRole = Role::where('id', $request->corporate_role_id)
            ->where('is_corporate', 1)
            ->first();

        if (!$corporateRole) {
            return response([
                "message" => "Error: Unprocessable Entity!",
                "errors" => [
                    "corporate_role_id" => [
                        "The selected corporate role is not valid"
                    ]
                ]
            ], 422);
        }

        // ? Initial
        DB::beginTransaction();
        $model = new User();

        // ? Manual assignment untuk field yang aman
        $model->name = $request->name;
        $model->email = $request->email;
        $model->phone = $request->phone;
        $model->verified_at = Carbon::now();
        
        // ✅ Set role global default (User) - TIDAK menggunakan role corporate
        $defaultGlobalRole = Role::where('is_corporate', 0)
            ->where('name', 'User')
            ->first();
        $model->role_id = $defaultGlobalRole ? $defaultGlobalRole->id : 1; // Fallback ke ID 1

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
            $corporateUser->role_id = $corporateRole->id; // ✅ Role corporate ke table corporate_users
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
            'corporate_role_id' => 'required|numeric|exists:roles,id,is_corporate,1',
        ]);

        if ($validation) return $validation;

        // * Validate role params is role for corporate
        $corporateRole = Role::where('id', $request->corporate_role_id)
            ->where('is_corporate', 1)
            ->first();

        if (!$corporateRole) {
            return response([
                "message" => "Error: Unprocessable Entity!",
                "errors" => [
                    "corporate_role_id" => [
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
        
        $model->role_id = $corporateRole->id;

        // ? Executing
        try {
            $model->save();
            // Note: Do NOT sync corporate role to users.role_id (global). Keep global role unchanged.
                
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
