<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class UserController extends Controller
{
    /**
     * Helper: terapkan filter role yang fleksibel.
     * roles[] bisa berisi: admin, manager tenant, manager_tenant, dll.
     */
    private function applyRoleFilter($query, array $roles)
    {
        // Normalisasi nama role -> lowercase & trim
        $roles = collect($roles)->filter()->map(function ($r) {
            return strtolower(trim($r));
        })->unique()->values()->all();

        if (empty($roles)) return $query;

        // Asumsi: relasi User::role()->name
        return $query->whereHas('role', function ($q) use ($roles) {
            $q->whereIn(DB::raw('LOWER(name)'), $roles);
        });
    }

    // ========================================>
    // ## Display a listing of the resource.
    // ========================================>
    public function index(Request $request)
    {
        // Params default
        $sortDirection = $request->get("sortDirection", "DESC");
        $sortby        = $request->get("sortBy", "created_at");

        // paginate:
        // - angka (default 10) => paginate biasa
        // - 'all' atau 0 => ambil semua data (untuk MultiSelectDropdown)
        $paginateRaw   = $request->get("paginate", 10);
        $paginateAll   = ($paginateRaw === 'all' || (int)$paginateRaw === 0);
        $paginate      = $paginateAll ? 0 : (int)$paginateRaw;

        $filter        = $request->get("filter", null);
        $onlyContacts  = $request->boolean('only_admin_contacts', false);
        $roleFilters   = (array) $request->get('roles', []); // roles[]=admin&roles[]=manager_tenant

        // aliases (kalau kamu pakai remark_column)
        $columnAliases = [];

        // Model & query dasar
        $model = new User();
        $selectable = property_exists($model, 'selectable') && is_array($model->selectable)
            ? $model->selectable
            : ['*']; // fallback kalau properti selectable nggak ada

        $query = User::with('role');

        // ==== Search bawaanmu =====
        if (filled($request->get("search"))) {
            $query = $this->search($request->get("search"), $model, $query);
        }

        // ==== Filter bawaanmu =====
        if ($filter) {
            $filters = json_decode($filter);
            foreach ($filters as $column => $value) {
                $query = $this->filter($this->remark_column($column, $columnAliases), $value, $model, $query);
            }
        }

        // ==== Filter admin contacts (role) ====
        if ($onlyContacts && empty($roleFilters)) {
            // default FE: admin + manager tenant
            $roleFilters = ['admin', 'manager tenant', 'manager_tenant'];
        }
        if (!empty($roleFilters)) {
            $query = $this->applyRoleFilter($query, $roleFilters);
        }

        // ==== Sorting ====
        $query = $query->orderBy($this->remark_column($sortby, $columnAliases), $sortDirection);

        // ==== Eksekusi ====
        if ($paginateAll) {
            $users = $query->select($selectable)->get();

            if ($users->isEmpty()) {
                return response([
                    "message" => "empty data",
                    "data"    => [],
                ], 200);
            }

            // Payload ringkas untuk FE
            $data = $users->map(function ($u) {
                return [
                    'id'    => $u->id,
                    'name'  => $u->name,
                    'email' => $u->email,
                    'phone' => $u->phone,
                    'role'  => [
                        'name' => optional($u->role)->name
                    ],
                ];
            });

            return response([
                "message"   => "success",
                "data"      => $data,
                "total_row" => $data->count(),
            ]);
        }

        // Paginate biasa
        $page = $query->select($selectable)->paginate($paginate);

        if (empty($page->items())) {
            return response([
                "message" => "empty data",
                "data"    => [],
            ], 200);
        }

        // Payload ringkas untuk FE
        $items = collect($page->items())->map(function ($u) {
            return [
                'id'    => $u->id,
                'name'  => $u->name,
                'email' => $u->email,
                'phone' => $u->phone,
                'role'  => [
                    'name' => optional($u->role)->name
                    ],
            ];
        });

        return response([
            "message"   => "success",
            "data"      => $items,
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
            'role_id'  => 'nullable|numeric|exists:roles,id',
            'name'     => 'nullable|string|max:100',
            'email'    => 'nullable|email',
            'phone'    => 'nullable|string|max:100',
            'password' => 'nullable|string|min:8|max:50|confirmed',
            'image'    => 'nullable',
        ]);

        if ($validation) return $validation;

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
            ], 500);
        }

        DB::commit();

        return response([
            "message" => "success",
            "data"    => $model
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
        $oldPicture = $model->picture_source;

        // ? Validate request
        $validation = $this->validation($request->all(), [
            'name'     => 'nullable|string|max:100',
            'email'    => 'nullable|email',
            'phone'    => 'nullable|string|max:100',
            'password' => 'nullable|string|min:8|max:50|confirmed',
            'image'    => 'nullable',
        ]);

        if ($validation) return $validation;

        // ? Dump data
        $model = $this->dump_field($request->all(), $model);

        // * Password Encryption
        if ($request->password) {
            $model->password = bcrypt($request->password);
        }

        // * Check if has upload file
        if ($request->hasFile('image')) {
            $model->picture_source = $this->upload_file($request->file('image'), 'profile');

            if ($oldPicture) {
                $this->delete_file($oldPicture ?? '');
            }
        }

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
            "data"    => $model
        ]);
    }

    // ===============================================>
    // ## Remove the specified resource from storage.
    // ===============================================>
    public function destroy(string $id)
    {
        // ? Initial
        $model = User::findOrFail($id);

        if ($model->picture_source) {
            $this->delete_file($model->picture_source ?? '');
        }

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
            "data"    => $model
        ]);
    }

    // ============================================>
    // ## Update the specified point.
    // ============================================>
    public function updatePoint(Request $request, string $id)
    {
        // ? Initial
        DB::beginTransaction();
        $model = User::findOrFail($id);

        // ? Validate request
        $validation = $this->validation($request->all(), [
            'point'           => 'required|numeric|min:0',
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
            "data"    => $model
        ]);
    }
}
