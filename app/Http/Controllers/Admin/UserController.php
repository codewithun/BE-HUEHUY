<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\CorporateUser;
use App\Models\Corporate;
use App\Models\Role;
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

        $query = User::with(['role', 'corporate_user', 'corporate_user.corporate', 'corporate_user.role']);

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
                    'corporate_user' => $u->corporate_user ? [
                        'corporate_id' => $u->corporate_user->corporate_id,
                        'corporate_name' => optional($u->corporate_user->corporate)->name,
                        'role_id' => $u->corporate_user->role_id,
                        'corporate_role_name' => optional($u->corporate_user->role)->name
                    ] : null,
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
                'corporate_user' => $u->corporate_user ? [
                    'corporate_id' => $u->corporate_user->corporate_id,
                    'corporate_name' => optional($u->corporate_user->corporate)->name,
                    'role_id' => $u->corporate_user->role_id,
                    'corporate_role_name' => optional($u->corporate_user->role)->name
                ] : null,
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
            'role_id'           => 'required|numeric|exists:roles,id,is_corporate,0', // Hanya role global
            'corporate_role_id' => 'nullable|numeric|exists:roles,id,is_corporate,1', // Role corporate
            'name'              => 'required|string|max:100',
            'email'             => 'required|email|unique:users,email',
            'phone'             => 'nullable|string|max:100',
            'password'          => 'required|string|min:8|max:50|confirmed',
            'corporate_id'      => 'nullable|numeric|exists:corporates,id',
            'image'             => 'nullable',
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

        // * TAMBAHAN: Create CorporateUser jika ada corporate_role_id
        if ($request->corporate_role_id && $request->corporate_id) {
            try {
                // Buat CorporateUser dengan role corporate yang benar
                $corporateUser = new CorporateUser();
                $corporateUser->user_id = $model->id;
                $corporateUser->corporate_id = $request->corporate_id;
                $corporateUser->role_id = $request->corporate_role_id; // Gunakan corporate_role_id
                $corporateUser->save();

                Log::info('CorporateUser created successfully', [
                    'corporate_user_id' => $corporateUser->id,
                    'user_id' => $model->id,
                    'corporate_id' => $request->corporate_id,
                    'corporate_role_id' => $request->corporate_role_id
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
        $model->load(['role', 'corporate_user', 'corporate_user.corporate', 'corporate_user.role']);

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
            'name'              => 'nullable|string|max:100',
            'email'             => 'nullable|email|unique:users,email,' . $id,
            'phone'             => 'nullable|string|max:100|unique:users,phone,' . $id,
            'password'          => 'nullable|string|min:8|max:50|confirmed',
            'role_id'           => 'prohibited', // Larang ubah role di endpoint ini
            'corporate_role_id' => 'prohibited', // Larang ubah corporate role di endpoint ini
            'image'             => 'nullable',
        ]);

        if ($validation) return $validation;

        // ? Buang role_id dan corporate_role_id dari payload untuk double protection
        $safe = collect($request->all())->except(['role_id', 'corporate_role_id'])->all();
        $model = $this->dump_field($safe, $model);

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

        // Load relations untuk response
        $model->load(['role', 'corporate_user', 'corporate_user.corporate', 'corporate_user.role']);

        return response([
            "message" => "success",
            "data"    => $model
        ]);
    }

    // ============================================>
    // ## Update user role (dedicated endpoint)
    // ============================================>
    public function updateRole(Request $request, string $id)
    {
        // ? Validate request
        $validation = $this->validation($request->all(), [
            'role_id' => 'required|numeric|exists:roles,id,is_corporate,0', // Hanya role global
        ]);

        if ($validation) return $validation;

        // ? Initial
        DB::beginTransaction();
        $model = User::findOrFail($id);
        $oldRoleId = $model->role_id;

        // ? Update role
        $model->role_id = $request->role_id;

        // ? Executing
        try {
            $model->save();
            
            Log::info('Admin updated user role', [
                'user_id' => $model->id,
                'old_role_id' => $oldRoleId,
                'new_role_id' => $request->role_id,
                'admin_id' => auth()->id()
            ]);
            
        } catch (\Throwable $th) {
            DB::rollBack();
            return response([
                "message" => "Error: server side having problem!",
            ], 500);
        }

        DB::commit();

        // Load role relation untuk response
        $model->load('role');

        return response([
            "message" => "User role updated successfully",
            "data" => $model
        ]);
    }

    // ============================================>
    // ## Assign user to corporate
    // ============================================>
    public function assignToCorporate(Request $request, string $id)
    {
        // ? Validate request
        $validation = $this->validation($request->all(), [
            'corporate_id'      => 'required|numeric|exists:corporates,id',
            'corporate_role_id' => 'required|numeric|exists:roles,id,is_corporate,1', // Role corporate
        ]);

        if ($validation) return $validation;

        // ? Initial
        DB::beginTransaction();
        $user = User::findOrFail($id);

        // Check if user already assigned to this corporate
        $existingCorporateUser = CorporateUser::where('user_id', $user->id)
            ->where('corporate_id', $request->corporate_id)
            ->first();

        if ($existingCorporateUser) {
            return response([
                "message" => "Error: User already assigned to this corporate!",
            ], 422);
        }

        // ? Create CorporateUser
        try {
            $corporateUser = new CorporateUser();
            $corporateUser->user_id = $user->id;
            $corporateUser->corporate_id = $request->corporate_id;
            $corporateUser->role_id = $request->corporate_role_id;
            $corporateUser->save();

            Log::info('User assigned to corporate successfully', [
                'corporate_user_id' => $corporateUser->id,
                'user_id' => $user->id,
                'corporate_id' => $request->corporate_id,
                'corporate_role_id' => $request->corporate_role_id,
                'admin_id' => auth()->id()
            ]);
            
        } catch (\Throwable $th) {
            DB::rollBack();
            Log::error('Failed to assign user to corporate', [
                'error' => $th->getMessage(),
                'user_id' => $user->id
            ]);
            return response([
                "message" => "Error: server side having problem!",
            ], 500);
        }

        DB::commit();

        // Load relations untuk response
        $user->load(['role', 'corporate_user', 'corporate_user.corporate', 'corporate_user.role']);

        return response([
            "message" => "User assigned to corporate successfully",
            "data" => $user
        ]);
    }

    // ============================================>
    // ## Remove user from corporate
    // ============================================>
    public function removeFromCorporate(Request $request, string $id)
    {
        // ? Validate request
        $validation = $this->validation($request->all(), [
            'corporate_id' => 'required|numeric|exists:corporates,id',
        ]);

        if ($validation) return $validation;

        // ? Initial
        DB::beginTransaction();
        $user = User::findOrFail($id);

        // Find corporate user
        $corporateUser = CorporateUser::where('user_id', $user->id)
            ->where('corporate_id', $request->corporate_id)
            ->first();

        if (!$corporateUser) {
            return response([
                "message" => "Error: User is not assigned to this corporate!",
            ], 422);
        }

        // ? Remove CorporateUser
        try {
            $corporateUser->delete();

            Log::info('User removed from corporate successfully', [
                'user_id' => $user->id,
                'corporate_id' => $request->corporate_id,
                'admin_id' => auth()->id()
            ]);
            
        } catch (\Throwable $th) {
            DB::rollBack();
            Log::error('Failed to remove user from corporate', [
                'error' => $th->getMessage(),
                'user_id' => $user->id
            ]);
            return response([
                "message" => "Error: server side having problem!",
            ], 500);
        }

        DB::commit();

        return response([
            "message" => "User removed from corporate successfully",
            "data" => $user
        ]);
    }

    // ============================================>
    // ## Fix sistem roles dan data corrupt
    // ============================================>
    public function fixCorruptRoles(Request $request)
    {
        DB::beginTransaction();
        
        try {
            // ✅ STEP 1: Setup correct roles data structure
            $correctRoles = [
                // Role Global (is_corporate = 0)
                ['name' => 'Admin', 'is_corporate' => 0, 'slug' => 'admin'],
                ['name' => 'User', 'is_corporate' => 0, 'slug' => 'user'],
                ['name' => 'Manager Tenant', 'is_corporate' => 0, 'slug' => 'manager_tenant'],
                
                // Role Corporate (is_corporate = 1)  
                ['name' => 'Kepala Mitra', 'is_corporate' => 1, 'slug' => 'kepala_mitra'],
                ['name' => 'Manager Mitra', 'is_corporate' => 1, 'slug' => 'manager_mitra'],
                ['name' => 'Staff Mitra', 'is_corporate' => 1, 'slug' => 'staff_mitra'],
            ];

            // ✅ STEP 2: Update/Insert correct roles
            foreach ($correctRoles as $roleData) {
                $existing = Role::where('name', $roleData['name'])->first();
                if ($existing) {
                    // Update jika sudah ada tapi wrong category
                    $existing->update([
                        'is_corporate' => $roleData['is_corporate'],
                        'slug' => $roleData['slug'] ?? strtolower(str_replace(' ', '_', $roleData['name']))
                    ]);
                    Log::info('Updated role', ['role' => $roleData['name'], 'is_corporate' => $roleData['is_corporate']]);
                } else {
                    // Create jika belum ada
                    Role::create([
                        'name' => $roleData['name'],
                        'is_corporate' => $roleData['is_corporate'],
                        'slug' => $roleData['slug'] ?? strtolower(str_replace(' ', '_', $roleData['name']))
                    ]);
                    Log::info('Created role', ['role' => $roleData['name'], 'is_corporate' => $roleData['is_corporate']]);
                }
            }

            // ✅ STEP 3: Fix users with corrupt role_id (corporate role di global field)
            $corruptUsers = User::join('roles', 'roles.id', '=', 'users.role_id')
                ->where('roles.is_corporate', 1)
                ->select('users.id', 'users.email', 'users.role_id', 'roles.name as role_name')
                ->get();

            $fixedCount = 0;
            $results = [];

            foreach ($corruptUsers as $user) {
                // Reset ke role global default (User)
                $defaultGlobalRole = Role::where('is_corporate', 0)
                    ->where('name', 'User')
                    ->first();
                
                if (!$defaultGlobalRole) {
                    // Fallback ke role global pertama
                    $defaultGlobalRole = Role::where('is_corporate', 0)->first();
                }

                if ($defaultGlobalRole) {
                    User::where('id', $user->id)->update([
                        'role_id' => $defaultGlobalRole->id
                    ]);
                    
                    $fixedCount++;
                    $results[] = [
                        'user_id' => $user->id,
                        'email' => $user->email,
                        'old_role_id' => $user->role_id,
                        'old_role_name' => $user->role_name,
                        'new_role_id' => $defaultGlobalRole->id,
                        'new_role_name' => $defaultGlobalRole->name
                    ];
                    
                    Log::info('Fixed user role', [
                        'user_id' => $user->id,
                        'email' => $user->email,
                        'from' => $user->role_name,
                        'to' => $defaultGlobalRole->name
                    ]);
                }
            }

            DB::commit();

            return response([
                "message" => "Successfully fixed role system and {$fixedCount} corrupt user roles",
                "data" => [
                    "roles_structure_fixed" => true,
                    "corrupt_users_fixed" => $fixedCount,
                    "fixed_users" => $results,
                    "role_structure" => [
                        "global_roles" => ["Admin", "User", "Manager Tenant"],
                        "corporate_roles" => ["Kepala Mitra", "Manager Mitra", "Staff Mitra"]
                    ]
                ]
            ]);

        } catch (\Throwable $th) {
            DB::rollBack();
            Log::error('Failed to fix role system', [
                'error' => $th->getMessage(),
                'trace' => $th->getTraceAsString()
            ]);
            
            return response([
                "message" => "Error: Failed to fix role system!",
                "error" => $th->getMessage()
            ], 500);
        }
    }

    // ============================================>
    // ## Check current roles structure  
    // ============================================>
    public function checkRolesStructure(Request $request)
    {
        try {
            $globalRoles = Role::where('is_corporate', 0)
                ->orderBy('name', 'asc')
                ->get(['id', 'name', 'slug', 'is_corporate']);

            $corporateRoles = Role::where('is_corporate', 1)
                ->orderBy('name', 'asc')
                ->get(['id', 'name', 'slug', 'is_corporate']);

            // Check corrupt users 
            $corruptUsers = User::join('roles', 'roles.id', '=', 'users.role_id')
                ->where('roles.is_corporate', 1)
                ->select('users.id', 'users.email', 'users.role_id', 'roles.name as role_name')
                ->get();

            return response([
                "message" => "Current roles structure",
                "data" => [
                    "global_roles" => $globalRoles,
                    "corporate_roles" => $corporateRoles,
                    "corrupt_users_count" => $corruptUsers->count(),
                    "corrupt_users" => $corruptUsers->take(5), // Show first 5
                    "structure_correct" => [
                        "should_be_global" => ["Admin", "User", "Manager Tenant"],
                        "should_be_corporate" => ["Kepala Mitra", "Manager Mitra", "Staff Mitra"]
                    ]
                ]
            ]);

        } catch (\Throwable $th) {
            return response([
                "message" => "Error checking roles structure",
                "error" => $th->getMessage()
            ], 500);
        }
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
