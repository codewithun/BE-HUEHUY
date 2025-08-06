<?php

namespace App\Http\Controllers\Corporate;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\UserWorld;
use App\Models\World;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class WorldController extends Controller
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
            'created_at' => 'worlds.created_at'
        ];

        $credentialUserCorporate = Auth::user()->corporate_user;

        // ? Begin
        $model = new World();
        $query = World::with('corporate');

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
        $query = $query->leftJoin('world_affiliates', 'world_affiliates.world_id', 'worlds.id')
            ->where('worlds.corporate_id', $credentialUserCorporate->corporate_id)
            ->orWhere('world_affiliates.corporate_id', $credentialUserCorporate->corporate_id)
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

    // ============================================>
    // ## Update the specified resource in storage.
    // ============================================>
    public function update(Request $request, string $id)
    {
        // ? Initial
        DB::beginTransaction();
        $model = World::findOrFail($id);

        // ? Validate request
        $validation = $this->validation($request->all(), [
            'name' => 'required|string|max:255',
            'description' => 'required|string|max:255',
            'color' => 'required|string|max:10',
            'type' => ['nullable', Rule::in(['lock', 'general'])],
        ]);

        if ($validation) return $validation;
        
        $credentialUserCorporate = Auth::user()->corporate_user;

        if ($credentialUserCorporate->corporate_id != $model->corporate_id) {
            return response([
                'message' => 'Anda tidak bisa mengubah data pada dunia ini'
            ], 403);
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

        DB::commit();

        return response([
            "message" => "success",
            "data" => $model
        ]);
    }

    // ========================================>
    // ## Display a listing of the resource.
    // ========================================>
    public function getWorldMember(Request $request, $id)
    {
        // ? Initial params
        $sortDirection = $request->get("sortDirection", "DESC");
        $sortby = $request->get("sortBy", "created_at");
        $paginate = $request->get("paginate", 10);
        $filter = $request->get("filter", null);

        // ? Preparation
        $columnAliases = [];

        // ? Begin
        $model = new UserWorld();
        $query = UserWorld::with('user', 'user.role');

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
        $query = $query
            ->where('world_id', $id)
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
    // ## Create New World User/Member by World ID.
    // =============================================>
    public function createWorldMember(Request $request, $id)
    {
        // ? Validate request
        $validation = $this->validation($request->all(), [
            'role_id' => 'required|numeric|exists:roles,id',
            'name' => 'required|string|max:100',
            'email' => 'required|email|unique:users,email',
            'phone' => 'nullable|string|max:100|unique:users,phone',
            'password' => 'nullable|string|min:8|max:50|confirmed',
            'image' => 'nullable',
        ]);

        if ($validation) return $validation;

        $credentialUserCorporate = Auth::user()->corporate_user;

        $world = World::findOrFail($id);

        // * Validate this world is under this corporate
        if ($credentialUserCorporate->corporate_id != $world->corporate_id) {
            return response([
                'message' => 'Anda tidak bisa mengubah data pada dunia ini'
            ], 403);
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

        // ? Create World Member
        try {
            $worldMember = new UserWorld();
            $worldMember->user_id = $model->id;
            $worldMember->world_id = $id;
            $worldMember->save();
        } catch (\Throwable $th) {
            DB::rollBack();
            return response([
                "message" => "Error: server side having problem!",
                "description" => "Failed to insert world member"
            ], 500);
        }

        DB::commit();

        return response([
            "message" => "success",
            "data" => $model
        ], 201);
    }

    // =============================================>
    // ## Add World User/Member by World ID.
    // =============================================>
    public function addWorldMember(Request $request, $id)
    {
        // ? Validate request
        $validation = $this->validation($request->all(), [
            'email' => 'required|string|email|max:255',
        ]);

        if ($validation) return $validation;

        $credentialUserCorporate = Auth::user()->corporate_user;

        $world = World::findOrFail($id);

        // * Validate this world is under this corporate
        if ($credentialUserCorporate->corporate_id != $world->corporate_id) {
            return response([
                'message' => 'Anda tidak bisa mengubah data pada dunia ini'
            ], 403);
        }

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
        $worldMember = UserWorld::where('world_id', $id)
            ->where('user_id', $user->id)
            ->first();

        if ($worldMember) {
            return response([
                "message" => "Error: Unprocessable Entity!",
                "errors" => [
                    "email" => [
                        "Duplicate user registered on this world"
                    ]
                ]
            ], 422);
        }

        DB::beginTransaction();

        // ? Create World Member
        $worldMember = new UserWorld();
        $worldMember->user_id = $user->id;
        $worldMember->world_id = $id;
        try {
            $worldMember->save();
        } catch (\Throwable $th) {
            DB::rollBack();
            return response([
                "message" => "Error: server side having problem!",
                "description" => "Failed to insert world member"
            ], 500);
        }

        DB::commit();

        return response([
            "message" => "success",
            "data" => $worldMember
        ], 201);
    }

    // ===============================================>
    // ## Remove World User/Member by World ID.
    // ===============================================>
    public function destroyWorldMember(string $id, $userWorldId)
    {
        $credentialUserCorporate = Auth::user()->corporate_user;

        $world = World::findOrFail($id);

        // * Validate this world is under this corporate
        if ($credentialUserCorporate->corporate_id != $world->corporate_id) {
            return response([
                'message' => 'Anda tidak bisa mengubah data pada dunia ini'
            ], 403);
        }

        // ? Initial
        $model = UserWorld::where('world_id', $id)
            ->where('id', $userWorldId)
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
        