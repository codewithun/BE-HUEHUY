<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\CorporateUser;
use App\Models\Corporate;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

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
        Log::info('Admin UserController store called', [
            'request_data' => $request->all(),
            'content_type' => $request->header('Content-Type')
        ]);

        // ? Validate request
        $validation = $this->validation($request->all(), [
            'role_id'     => 'required|numeric|exists:roles,id',
            'name'        => 'required|string|max:100',
            'email'       => 'required|email|unique:users,email',
            'phone'       => 'nullable|string|max:100',
            'password'    => 'required|string|min:8|max:50|confirmed',
            'corporate_id'=> 'nullable|numeric|exists:corporates,id', // Tambahan untuk corporate user
            'image'       => 'nullable',
        ]);

        if ($validation) return $validation;

        // ? Initial
        DB::beginTransaction();
        $model = new User();

        // ? Manual assignment untuk field yang required
        $model->name = $request->name;
        $model->email = $request->email;
        $model->phone = $request->phone;
        $model->role_id = $request->role_id;
        $model->verified_at = Carbon::now();
        $model->picture_source = null;

        Log::info('Admin UserController before save', [
            'model_data' => [
                'name' => $model->name,
                'email' => $model->email,
                'phone' => $model->phone,
                'role_id' => $model->role_id,
                'verified_at' => $model->verified_at,
                'picture_source' => $model->picture_source
            ]
        ]);

        // * Password Encryption
        if ($request->password) {
            $model->password = bcrypt($request->password);
        }

        // * Check if has upload file
        if ($request->hasFile('image')) {
            $model->picture_source = $this->upload_file($request->file('image'), 'profile');
        }

        // ? Executing - Save User first
        try {
            $model->save();
            Log::info('Admin UserController user created successfully', [
                'user_id' => $model->id,
                'user_email' => $model->email
            ]);
        } catch (\Throwable $th) {
            DB::rollBack();
            Log::error('Admin UserController user creation failed', [
                'error' => $th->getMessage(),
                'trace' => $th->getTraceAsString()
            ]);
            return response([
                "message" => "Error: server side having problem!",
            ], 500);
        }

        // * TAMBAHAN: Auto-create CorporateUser for corporate roles (3, 4, 5)
        if (in_array($request->role_id, [3, 4, 5])) {
            try {
                // Ambil corporate_id dari request atau default ke corporate pertama
                $corporateId = $request->corporate_id;
                
                if (!$corporateId) {
                    // Jika tidak ada corporate_id, ambil corporate pertama atau buat default
                    $defaultCorporate = Corporate::first();
                    if (!$defaultCorporate) {
                        // Jika belum ada corporate, buat satu default
                        $defaultCorporate = Corporate::create([
                            'name' => 'Default Corporate',
                            'description' => 'Default corporate untuk user mitra',
                            'address' => 'Default Address',
                            'phone' => '000000000'
                        ]);
                        Log::info('Created default corporate', ['corporate_id' => $defaultCorporate->id]);
                    }
                    $corporateId = $defaultCorporate->id;
                }

                // Buat CorporateUser
                $corporateUser = new CorporateUser();
                $corporateUser->user_id = $model->id;
                $corporateUser->corporate_id = $corporateId;
                $corporateUser->role_id = $request->role_id; // Role corporate
                $corporateUser->save();

                Log::info('CorporateUser created successfully', [
                    'corporate_user_id' => $corporateUser->id,
                    'user_id' => $model->id,
                    'corporate_id' => $corporateId,
                    'role_id' => $request->role_id
                ]);

            } catch (\Throwable $th) {
                DB::rollBack();
                Log::error('CorporateUser creation failed', [
                    'error' => $th->getMessage(),
                    'user_id' => $model->id
                ]);
                return response([
                    "message" => "Error: Failed to create corporate user relationship!",
                ], 500);
            }
        }

        DB::commit();

        // Reload model dengan relations untuk response
        $model->load(['role', 'corporate_user', 'corporate_user.corporate']);

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
