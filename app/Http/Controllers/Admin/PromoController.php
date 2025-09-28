<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Promo;
use App\Models\PromoItem;
use App\Models\PromoValidation;
use App\Models\Community;
use App\Models\User; // ambil data manager merchant
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Auth;

class PromoController extends Controller
{

    /** Normalisasi nomor HP: ambil digit, buang 0/62 di depan */
    private function normalizePhone(?string $raw): ?string
    {
        if (!$raw) return null;
        $d = preg_replace('/\D+/', '', $raw);
        if ($d === '') return null;
        // buang prefix umum Indonesia
        $d = preg_replace('/^(?:0|62)/', '', $d);
        return $d;
    }

    /** Temukan user (tenant pemilik promo) dari owner_contact / owner_name */
    private function resolveTenantUserIdByPromo(Promo $promo): ?int
    {
        try {
            $ownerPhone = $this->normalizePhone($promo->owner_contact ?? null);

            // Ambil daftar kolom users yang benar2 ada
            $allUserCols = \Illuminate\Support\Facades\Schema::getColumnListing('users');

            $phoneCols = array_values(array_intersect(
                $allUserCols,
                ['phone', 'phone_number', 'telp', 'telpon', 'mobile', 'contact', 'whatsapp', 'wa']
            ));

            // 1) Cocokkan via nomor HP (paling akurat)
            if ($ownerPhone && !empty($phoneCols)) {
                $u = \App\Models\User::query()
                    ->where(function ($q) use ($ownerPhone, $phoneCols) {
                        foreach ($phoneCols as $col) {
                            // samakan hanya digit; izinkan prefix 0/62
                            $q->orWhereRaw(
                                "REGEXP_REPLACE(COALESCE($col,''),'[^0-9]','') REGEXP ?",
                                ["^(0|62)?$ownerPhone$"]
                            );
                        }
                    })
                    // ->where('role_id', 6) // kalau perlu batasi role manager tenant
                    ->first();

                if ($u) return $u->id;
            }

            // 2) Fallback by nama (opsional)
            $nameCols = array_values(array_intersect(
                $allUserCols,
                ['name', 'full_name', 'username', 'display_name', 'company_name']
            ));
            $ownerName = trim((string)($promo->owner_name ?? ''));
            if ($ownerName !== '' && !empty($nameCols)) {
                $u = \App\Models\User::query()
                    ->where(function ($q) use ($ownerName, $nameCols) {
                        foreach ($nameCols as $col) {
                            $q->orWhere($col, $ownerName);
                        }
                    })
                    // ->where('role_id', 6)
                    ->first();

                if ($u) return $u->id;
            }

            return null;
        } catch (\Throwable $e) {
            Log::error('resolveTenantUserIdByPromo failed: ' . $e->getMessage(), [
                'promo_id'      => $promo->id ?? null,
                'owner_name'    => $promo->owner_name ?? null,
                'owner_contact' => $promo->owner_contact ?? null,
            ]);
            return null; // fallback aman agar tidak 500
        }
    }

    /**
     * Transform promo image URLs
     */
    private function transformPromoImageUrls($promo)
    {
        // Pakai accessor dari model agar konsisten - accessor sudah handle URL generation dengan benar
        $promo->append(['image_url', 'image_url_versioned']);
        return $promo;
    }

    /** Helper pilih nilai pertama yang tidak kosong */
    private function pick(...$candidates)
    {
        foreach ($candidates as $v) {
            if (isset($v) && trim((string)$v) !== '') return $v;
        }
        return null;
    }

    /**
     * Ambil nama & telepon dari berbagai kemungkinan kolom user dengan AMAN.
     * Tidak pernah akses $user->profile / $user->meta sebagai properti langsung.
     */
    private function extractUserNamePhone(?User $user): array
    {
        if (!$user) return [null, null];

        $name = $this->pick(
            $user->name ?? null,
            $user->full_name ?? null,
            $user->username ?? null,
            $user->display_name ?? null,
            $user->company_name ?? null
        );

        $phone = $this->pick(
            $user->phone ?? null,
            $user->phone_number ?? null,
            $user->telp ?? null,
            $user->telpon ?? null,
            $user->mobile ?? null,
            $user->contact ?? null,
            $user->whatsapp ?? null,
            $user->wa ?? null
        );

        // Fallback dari relasi profile() jika ADA
        if (method_exists($user, 'profile')) {
            $profile = $user->profile()->first();
            if ($profile) {
                $name = $name ?: $this->pick(
                    $profile->name ?? null,
                    $profile->full_name ?? null,
                    $profile->display_name ?? null
                );
                $phone = $phone ?: $this->pick(
                    $profile->phone ?? null,
                    $profile->phone_number ?? null,
                    $profile->telp ?? null,
                    $profile->telpon ?? null,
                    $profile->mobile ?? null,
                    $profile->whatsapp ?? null
                );
            }
        }

        // Fallback dari relasi meta() jika ADA
        if (method_exists($user, 'meta')) {
            $meta = $user->meta()->first();
            if ($meta) {
                $phone = $phone ?: $this->pick(
                    $meta->phone ?? null,
                    $meta->phone_number ?? null,
                    $meta->contact ?? null,
                    $meta->whatsapp ?? null
                );
            }
        }

        return [$name, $phone ? trim((string)$phone) : null];
    }

    // Align with VoucherController index (search/sort/paginate + response shape)
    public function index(Request $request)
    {
        try {
            $adminId = Auth::id();
            $sortDirection = $request->get('sortDirection', 'DESC');
            $sortby        = $request->get('sortBy', 'created_at');
            $paginate      = (int) $request->get('paginate', 10);
            $filter        = $request->get('filter', null);

            $query = Promo::with(['community'])
                ->when($request->filled('search'), function ($q) use ($request) {
                    $s = $request->get('search');
                    $q->where(function ($qq) use ($s) {
                        $qq->where('title', 'like', "%{$s}%")
                            ->orWhere('code', 'like', "%{$s}%")
                            ->orWhere('description', 'like', "%{$s}%");
                    });
                });

            // $query->where('admin_id', $adminId);

            if ($filter) {
                $filters = is_string($filter) ? json_decode($filter, true) : (array) $filter;
                foreach ($filters as $column => $value) {
                    if ($value === null || $value === '') continue;

                    if ($column === 'community_id') {
                        $filterVal = is_string($value) && str_contains($value, ':')
                            ? explode(':', $value)[1]
                            : $value;
                        $query->where('community_id', $filterVal);
                    } elseif ($column === 'promo_type') {
                        $query->where('promo_type', $value);
                    } elseif ($column === 'status') {
                        $query->where('status', $value);
                    } else {
                        $query->where($column, 'like', '%' . $value . '%');
                    }
                }
            }

            if ($paginate <= 0 || $request->boolean('all')) {
                $items = $query->orderBy($sortby, $sortDirection)->get();

                $transformedItems = $items->map(function ($p) {
                    $p->validation_type = $p->validation_type ?? 'auto';
                    return $this->transformPromoImageUrls($p);
                });

                return response([
                    'message'   => 'success',
                    'data'      => $transformedItems,
                    'total_row' => $transformedItems->count(),
                ]);
            }

            $data = $query->orderBy($sortby, $sortDirection)->paginate($paginate);
            $items = collect($data->items())->map(function ($p) {
                $p->validation_type = $p->validation_type ?? 'auto';
                return $this->transformPromoImageUrls($p);
            })->values();

            if ($items->isEmpty()) {
                return response([
                    'message' => 'empty data',
                    'data'    => [],
                ], 200);
            }

            return response([
                'message'   => 'success',
                'data'      => $items,
                'total_row' => $data->total(),
            ]);
        } catch (\Throwable $e) {
            Log::error('Error fetching promos: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error: server side having problem!'
            ], 500);
        }
    }

    /** For dropdown usage */
    public function forDropdown(Request $request)
    {
        try {
            $query = Promo::select('id', 'title', 'code', 'community_id')
                ->when($request->filled('community_id'), function ($q) use ($request) {
                    $q->where('community_id', $request->get('community_id'));
                })
                ->when($request->filled('search'), function ($q) use ($request) {
                    $s = $request->get('search');
                    $q->where(function ($qq) use ($s) {
                        $qq->where('title', 'like', "%{$s}%")
                            ->orWhere('code', 'like', "%{$s}%");
                    });
                });

            $promos = $query->orderBy('title', 'ASC')->get();

            $formattedPromos = $promos->map(function ($promo) {
                return [
                    'value' => $promo->id,
                    'label' => $promo->title,
                    'code' => $promo->code,
                    'community_id' => $promo->community_id,
                ];
            });

            return response()->json([
                'success' => true,
                'message' => 'success',
                'data' => $formattedPromos,
                'count' => $formattedPromos->count(),
            ]);
        } catch (\Throwable $e) {
            Log::error('Error fetching promos for dropdown: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error: server side having problem!'
            ], 500);
        }
    }

    public function show($id)
    {
        $promo = Promo::find($id);
        if (!$promo) {
            return response()->json([
                'message' => 'Data not found'
            ], 404);
        }

        $promo->validation_type = $promo->validation_type ?? 'auto';
        $promo = $this->transformPromoImageUrls($promo);

        return response([
            'message' => 'Success',
            'data'    => $promo
        ]);
    }

    /** Public (QR) */
    public function showPublic($id)
    {
        try {
            Log::info('showPublic called', ['promo_id' => $id]);

            $query = Promo::where('id', $id);

            try {
                $promo = $query->with(['community'])->first();
            } catch (\Exception $e) {
                Log::warning('Relations not available for Promo model, loading without them');
                $promo = $query->first();
            }

            if (!$promo) {
                Log::warning('Promo not found', ['promo_id' => $id]);
                return response()->json([
                    'success' => false,
                    'message' => 'Promo tidak ditemukan'
                ], 404);
            }

            if (isset($promo->status) && $promo->status !== 'active') {
                return response()->json([
                    'success' => false,
                    'message' => 'Promo tidak aktif'
                ], 404);
            }

            $promo = $this->transformPromoImageUrls($promo);

            $responseData = [
                'id' => $promo->id,
                'title' => $promo->title ?? '',
                'description' => $promo->description ?? '',
                'detail' => $promo->detail ?? '',
                'owner_name' => $promo->owner_name ?? '',
                'owner_contact' => $promo->owner_contact ?? '',
                'location' => $promo->location ?? '',
                'promo_type' => $promo->promo_type ?? '',
                'always_available' => $promo->always_available ?? true,
                'start_date' => $promo->start_date ?? null,
                'end_date' => $promo->end_date ?? null,
                'community_id' => $promo->community_id ?? null,
                'stock' => $promo->stock ?? null,
                'created_at' => $promo->created_at ?? null,
                'updated_at' => $promo->updated_at ?? null,
                'image_url' => $promo->image_url,
                'image_url_versioned' => $promo->image_url_versioned ?? $promo->image_url,
                'image' => $promo->image,
                'validation_type' => $promo->validation_type ?? 'auto',
            ];

            if (isset($promo->community)) {
                $responseData['community'] = $promo->community;
            }

            if (isset($promo->original_price)) {
                $responseData['original_price'] = $promo->original_price;
            }
            if (isset($promo->discount_price)) {
                $responseData['discount_price'] = $promo->discount_price;
            }
            if (isset($promo->discount_percentage)) {
                $responseData['discount_percentage'] = $promo->discount_percentage;
            }

            try {
                $claimedCount = method_exists($promo, 'validations')
                    ? $promo->validations()->count()
                    : PromoValidation::where('promo_id', $promo->id)->count();
                $responseData['claimed_count'] = $claimedCount;
            } catch (\Throwable $e) {
                $responseData['claimed_count'] = 0;
            }

            Log::info('Returning promo data', ['response' => $responseData]);

            return response()->json([
                'success' => true,
                'data' => $responseData
            ]);
        } catch (\Exception $e) {
            Log::error('Error showing public promo:', [
                'promo_id' => $id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat mengambil data promo'
            ], 500);
        }
    }

    public function store(Request $request)
    {
        // Default validation_type
        if (!$request->filled('validation_type')) {
            $request->merge(['validation_type' => 'auto']);
        }

        $request->merge([
            'community_id'     => in_array($request->input('community_id'), [null, '', 'null', 'undefined'], true) ? null : $request->input('community_id'),
            'always_available' => in_array($request->input('always_available'), [1, '1', true, 'true'], true) ? '1' : '0',
        ]);

        // TERIMA alias dari FE (owner_manager_id) → map ke owner_user_id
        if ($request->filled('owner_manager_id') && !$request->filled('owner_user_id')) {
            $request->merge(['owner_user_id' => $request->input('owner_manager_id')]);
        }

        // Ambil dari user manager kalau owner_user_id ada
        if ($request->filled('owner_user_id')) {
            $user = User::find($request->input('owner_user_id'));
            [$uname, $uphone] = $this->extractUserNamePhone($user);
            if ($uname && !$request->filled('owner_name')) {
                $request->merge(['owner_name'    => $uname]);
            }
            if ($uphone && !$request->filled('owner_contact')) {
                $request->merge(['owner_contact' => $uphone]);
            }
        }

        $validator = Validator::make($request->all(), [
            'owner_user_id'   => 'nullable|integer|exists:users,id',

            'title'           => 'required|string|max:255',
            'description'     => 'required|string',
            'detail'          => 'nullable|string',
            'promo_distance'  => 'nullable|numeric|min:0',
            'start_date'      => 'nullable|date',
            'end_date'        => 'nullable|date|after_or_equal:start_date',
            'always_available' => 'required|in:0,1,true,false',
            'stock'           => 'required|integer|min:0',
            'promo_type'      => 'required|string|in:offline,online',
            'validation_type' => 'required|string|in:auto,manual',
            'location'        => 'nullable|string',
            'owner_name'      => 'required|string|max:255',
            'owner_contact'   => 'required|string|max:255',
            'image'           => 'nullable|image|mimes:jpeg,png,jpg,gif,svg,webp|max:10240',
            'community_id'    => 'required|exists:communities,id',
            'code'            => 'nullable|string|unique:promos,code|required_if:validation_type,manual',
        ], [
            'validation_type.required' => 'Tipe validasi wajib diisi.',
            'validation_type.in'       => 'Tipe validasi harus auto atau manual.',
            'code.required_if'         => 'Kode wajib diisi untuk tipe validasi manual.',
        ]);

        // Kalau owner_user_id dikirim tapi nomor user kosong → error yang jelas
        $validator->after(function ($v) use ($request) {
            if ($request->filled('owner_user_id') && !$request->filled('owner_contact')) {
                $v->errors()->add('owner_contact', 'Nomor telepon manager pada data user belum diisi.');
            }
        });

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validasi gagal',
                'errors'  => $validator->errors()
            ], 422);
        }

        try {
            DB::beginTransaction();

            $data = $validator->validated();

            // HINDARI mass assignment: buang field yang bukan kolom
            unset($data['owner_user_id']); // <— penting

            $data['always_available'] = in_array($data['always_available'] ?? '0', [1, '1', true, 'true'], true);
            $data['promo_distance']   = isset($data['promo_distance']) ? (float) $data['promo_distance'] : 0;
            $data['stock']            = isset($data['stock']) ? (int) $data['stock'] : 0;

            $data['validation_type'] = strtolower($data['validation_type'] ?? 'auto');
            if (!in_array($data['validation_type'], ['auto', 'manual'], true)) {
                $data['validation_type'] = 'auto';
            }

            if (empty($data['code'])) {
                do {
                    $data['code'] = 'PRM-' . strtoupper(bin2hex(random_bytes(3)));
                } while (Promo::where('code', $data['code'])->exists());
            }

            if ($request->hasFile('image')) {
                $data['image'] = $request->file('image')->store('promos', 'public');
                $data['image_updated_at'] = now(); // versioning trigger
            }

            $promo = Promo::create($data);
            $promo = $this->transformPromoImageUrls($promo);

            DB::commit();
            return response()->json([
                'success' => true,
                'message' => 'Promo berhasil dibuat',
                'data'    => $promo
            ], 201);
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('Error creating promo: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Gagal membuat promo: ' . $e->getMessage()
            ], 500);
        }
    }

    public function update(Request $request, $id)
    {
        $promo = Promo::find($id);
        if (!$promo) {
            return response()->json([
                'success' => false,
                'message' => 'Promo tidak ditemukan'
            ], 404);
        }

        Log::info('Promo update request data:', $request->all());

        $request->merge([
            'community_id'     => in_array($request->input('community_id'), [null, '', 'null', 'undefined'], true) ? null : $request->input('community_id'),
            'always_available' => in_array($request->input('always_available'), [1, '1', true, 'true'], true) ? '1' : (in_array($request->input('always_available'), [0, '0', false, 'false'], true) ? '0' : null),
        ]);

        // TERIMA alias dari FE (owner_manager_id) → map ke owner_user_id
        if ($request->filled('owner_manager_id') && !$request->filled('owner_user_id')) {
            $request->merge(['owner_user_id' => $request->input('owner_manager_id')]);
        }

        // Ambil dari user manager kalau dikirim
        if ($request->filled('owner_user_id')) {
            $user = User::find($request->input('owner_user_id'));
            [$uname, $uphone] = $this->extractUserNamePhone($user);
            if ($uname && !$request->filled('owner_name')) {
                $request->merge(['owner_name'    => $uname]);
            }
            if ($uphone && !$request->filled('owner_contact')) {
                $request->merge(['owner_contact' => $uphone]);
            }
        }

        // Create dynamic validation rules based on whether image is being uploaded
        $validationRules = [
            'owner_user_id'   => 'nullable|integer|exists:users,id',

            'title'           => 'sometimes|required|string|max:255',
            'description'     => 'sometimes|required|string',
            'detail'          => 'nullable|string',
            'promo_distance'  => 'nullable|numeric|min:0',
            'start_date'      => 'nullable|date',
            'end_date'        => 'nullable|date|after_or_equal:start_date',
            'always_available' => 'nullable|in:0,1,true,false',
            'stock'           => 'nullable|integer|min:0',
            'promo_type'      => 'sometimes|required|string|in:offline,online',
            'validation_type' => 'nullable|string|in:auto,manual',
            'location'        => 'nullable|string',
            'owner_name'      => 'sometimes|string|max:255',
            'owner_contact'   => 'sometimes|string|max:255',
            'community_id'    => 'sometimes|nullable|exists:communities,id',
            'code'            => 'nullable|string|unique:promos,code,' . $id . '|required_if:validation_type,manual',
            'image'           => 'sometimes|file|image|mimes:jpeg,png,jpg,gif,svg,webp|max:10240',
        ];

        if (!$request->hasFile('image')) {
            $request->request->remove('image');
        }

        $validator = Validator::make($request->all(), $validationRules, [
            'validation_type.in' => 'Tipe validasi harus auto atau manual.',
            'code.required_if'   => 'Kode wajib diisi untuk tipe validasi manual.',
        ]);

        // Opsional: kalau owner_user_id dikirim tapi nomor tak terisi
        $validator->after(function ($v) use ($request) {
            if ($request->filled('owner_user_id')) {
                if ($request->has('owner_contact') && ($request->input('owner_contact') === null || $request->input('owner_contact') === '')) {
                    $v->errors()->add('owner_contact', 'Nomor telepon manager pada data user belum diisi.');
                }
            }
        });

        if ($validator->fails()) {
            Log::error('Validation failed on promo update', [
                'errors'       => $validator->errors()->toArray(),
                'request_data' => $request->all()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Validasi gagal',
                'errors'  => $validator->errors()
            ], 422);
        }

        try {
            DB::beginTransaction();

            $data = $validator->validated();

            // HINDARI mass assignment
            unset($data['owner_user_id']); // <— penting

            if (isset($data['image']) && is_string($data['image']) && !$request->hasFile('image')) {
                unset($data['image']);
            }

            if (!array_key_exists('community_id', $data) || $data['community_id'] === null) {
                $data['community_id'] = $promo->community_id;
            }

            if (array_key_exists('always_available', $data) && $data['always_available'] !== null) {
                $data['always_available'] = in_array($data['always_available'], [1, '1', true, 'true'], true);
            }
            if (isset($data['promo_distance']) && $data['promo_distance'] !== null) {
                $data['promo_distance'] = (float) $data['promo_distance'];
            }
            if (isset($data['stock']) && $data['stock'] !== null) {
                $data['stock'] = (int) $data['stock'];
            }

            if (!array_key_exists('validation_type', $data) || empty($data['validation_type'])) {
                $data['validation_type'] = $promo->validation_type ?? 'auto';
            } else {
                $data['validation_type'] = strtolower($data['validation_type']);
                if (!in_array($data['validation_type'], ['auto', 'manual'], true)) {
                    $data['validation_type'] = $promo->validation_type ?? 'auto';
                }
            }

            if ($request->hasFile('image')) {
                if (!empty($promo->image)) {
                    Storage::disk('public')->delete($promo->image);
                }
                $data['image'] = $request->file('image')->store('promos', 'public');
                $data['image_updated_at'] = now(); // versioning trigger
            }
            // TAMBAHAN: Update cache buster untuk perubahan non-image
            else if (!$request->hasFile('image')) {
                $significantFields = ['title', 'description', 'detail', 'stock', 'start_date', 'end_date', 'location', 'owner_name', 'owner_contact'];
                $hasSignificantChange = collect($significantFields)->some(function ($field) use ($data, $promo) {
                    return isset($data[$field]) && $data[$field] != $promo->$field;
                });

                if ($hasSignificantChange) {
                    $data['image_updated_at'] = now();
                    Log::info('🔄 Force cache bust for non-image changes');
                }
            }

            $promo->update($data);
            $promo = $this->transformPromoImageUrls($promo->fresh());

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Promo berhasil diperbarui',
                'data'    => $promo
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('Error updating promo', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Gagal memperbarui promo: ' . $e->getMessage()
            ], 500);
        }
    }

    public function destroy($id)
    {
        $promo = Promo::find($id);
        if (!$promo) {
            return response()->json([
                'success' => false,
                'message' => 'Promo tidak ditemukan'
            ], 404);
        }

        try {
            DB::beginTransaction();

            // Hapus semua promo items terkait
            PromoItem::where('promo_id', $id)->delete();

            // Hapus semua riwayat validasi promo terkait
            PromoValidation::where('promo_id', $id)->delete();

            // Hapus file gambar jika ada
            if (!empty($promo->image)) {
                Storage::disk('public')->delete($promo->image);
            }

            // Hapus promo
            $promo->delete();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Promo beserta data terkait berhasil dihapus'
            ]);
        } catch (\Exception $e) {
            DB::rollback();
            Log::error('Error deleting promo with cascade: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Gagal menghapus promo: ' . $e->getMessage()
            ], 500);
        }
    }

    // list promos for a specific community (assigned promos)
    public function indexByCommunity($communityId)
    {
        $promos = Promo::where('community_id', $communityId)->get();
        $promos = $promos->map(function ($promo) {
            $promo->validation_type = $promo->validation_type ?? 'auto';
            return $promo;
        });

        return response()->json([
            'success' => true,
            'data' => $promos
        ]);
    }

    // store promo under a specific community (creates new promo and assigns)
    public function storeForCommunity(Request $request, $communityId)
    {
        if ($request->boolean('attach_existing') || ($request->has('promo_id') && !$request->has('title'))) {
            return $this->assignToCommunity($request, $communityId);
        }

        $request->merge(['community_id' => $communityId]);

        return $this->store($request);
    }

    /** Returns promos available to be assigned to the community. */
    public function availableForCommunity($communityId)
    {
        $promos = Promo::whereNull('community_id')
            ->orWhere('community_id', $communityId)
            ->get();

        return response()->json([
            'success' => true,
            'data' => $promos
        ]);
    }

    public function assignToCommunity(Request $request, $communityId)
    {
        $validator = Validator::make($request->all(), [
            'promo_id' => 'required|exists:promos,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validasi gagal',
                'errors' => $validator->errors()
            ], 422);
        }

        $promo = Promo::findOrFail($request->input('promo_id'));

        if ($promo->community_id && $promo->community_id != $communityId) {
            return response()->json([
                'success' => false,
                'message' => 'Promo sudah ditugaskan ke komunitas lain'
            ], 409);
        }

        $promo->community_id = $communityId;
        $promo->save();

        return response()->json([
            'success' => true,
            'message' => 'Promo berhasil ditugaskan ke komunitas',
            'data' => $promo
        ]);
    }

    public function detachFromCommunity($communityId, $promoId)
    {
        $promo = Promo::where('id', $promoId)->where('community_id', $communityId)->first();

        if (!$promo) {
            return response()->json([
                'success' => false,
                'message' => 'Promo tidak ditemukan atau tidak ditugaskan ke komunitas ini'
            ], 404);
        }

        $promo->community_id = null;
        $promo->save();

        return response()->json([
            'success' => true,
            'message' => 'Promo berhasil dilepas dari komunitas',
            'data' => $promo
        ]);
    }

    public function showForCommunity($communityId, $promoId)
    {
        try {
            Log::info('showForCommunity called', [
                'community_id' => $communityId,
                'promo_id' => $promoId
            ]);

            $community = Community::find($communityId);
            if (!$community) {
                return response()->json([
                    'success' => false,
                    'message' => 'Komunitas tidak ditemukan'
                ], 404);
            }

            $promo = Promo::where('id', $promoId)
                ->where('community_id', $communityId)
                ->first();

            if (!$promo) {
                $promo = Promo::find($promoId);
                if (!$promo) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Promo tidak ditemukan'
                    ], 404);
                }
            }

            if (isset($promo->status) && $promo->status !== 'active') {
                return response()->json([
                    'success' => false,
                    'message' => 'Promo tidak aktif'
                ], 404);
            }

            // Image URLs are now handled by the model accessors

            $responseData = [
                'id' => $promo->id,
                'title' => $promo->title ?? '',
                'description' => $promo->description ?? '',
                'detail' => $promo->detail ?? '',
                'owner_name' => $promo->owner_name ?? '',
                'owner_contact' => $promo->owner_contact ?? '',
                'location' => $promo->location ?? '',
                'promo_type' => $promo->promo_type ?? 'offline',
                'promo_distance' => $promo->promo_distance ?? 0,
                'always_available' => $promo->always_available ?? true,
                'start_date' => $promo->start_date ?? null,
                'end_date' => $promo->end_date ?? null,
                'community_id' => $promo->community_id ?? $communityId,
                'stock' => $promo->stock ?? null,
                'created_at' => $promo->created_at ?? null,
                'updated_at' => $promo->updated_at ?? null,
                'image_url' => $promo->image_url,
                'image_url_versioned' => $promo->image_url_versioned ?? $promo->image_url,
                'image' => $promo->image,
                'validation_type' => $promo->validation_type ?? 'auto',
            ];

            if (isset($promo->original_price)) {
                $responseData['original_price'] = $promo->original_price;
            }
            if (isset($promo->discount_price)) {
                $responseData['discount_price'] = $promo->discount_price;
            }
            if (isset($promo->discount_percentage)) {
                $responseData['discount_percentage'] = $promo->discount_percentage;
            }

            $responseData['community'] = [
                'id' => $community->id,
                'name' => $community->name,
                'location' => $community->location ?? null
            ];

            try {
                $claimedCount = method_exists($promo, 'validations')
                    ? $promo->validations()->count()
                    : PromoValidation::where('promo_id', $promo->id)->count();
                $responseData['claimed_count'] = $claimedCount;
            } catch (\Exception $e) {
                $responseData['claimed_count'] = 0;
            }

            Log::info('Returning community promo data', ['response' => $responseData]);

            return response()->json([
                'success' => true,
                'data' => $responseData
            ]);
        } catch (\Exception $e) {
            Log::error('Error showing community promo:', [
                'community_id' => $communityId,
                'promo_id' => $promoId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat mengambil data promo'
            ], 500);
        }
    }

    /**
     * Idempotent validation dengan logika pencarian yang diperbaiki:
     * - Jika ada item_id, validasi item spesifik (untuk QR code)
     * - Jika tidak ada item_id, cari berdasarkan kode dengan urutan FIFO
     * - Pastikan setiap user mendapat giliran validasi yang tepat
     */
    public function validateCode(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated'], 401);
        }

        $data = $request->validate([
            'code'               => 'required|string',
            'item_id'            => 'sometimes|integer', // Optional: untuk QR code dengan item spesifik
            'item_owner_id'      => 'sometimes|integer',
            'expected_type'      => 'sometimes|in:promo',
            'validator_role'     => 'sometimes|in:tenant',
            'validation_purpose' => 'sometimes|string',
            'qr_timestamp'       => 'sometimes',
        ]);

        $code       = trim((string)$data['code']);
        $itemId     = $data['item_id'] ?? null;
        $ownerHint  = $data['item_owner_id'] ?? null;
        $item       = null;
        $promo      = null;

        // ====== STRATEGI PENCARIAN BERDASARKAN INPUT ======
        
        // 1) Jika ada item_id, ambil item spesifik (untuk QR code)
        if ($itemId) {
            $item = \App\Models\PromoItem::with(['promo', 'user'])
                ->where('id', $itemId)
                ->first();

            if (!$item) {
                return response()->json(['success' => false, 'message' => 'Promo item tidak ditemukan'], 404);
            }

            // Jika FE mengirim owner_hint, pastikan cocok
            if ($ownerHint && (int)$item->user_id !== (int)$ownerHint) {
                return response()->json([
                    'success' => false,
                    'message' => 'Promo item ini bukan milik user yang dimaksud'
                ], 422);
            }

            // Verifikasi kode PERSIS (case-sensitive, termasuk spasi)
            if (!hash_equals((string)$item->code, $code)) {
                return response()->json(['success' => false, 'message' => 'Kode unik tidak valid.'], 422);
            }

            $promo = $item->promo;
        } 
        // 2) Jika tidak ada item_id, cari berdasarkan kode dengan logika yang sederhana
        else {
            // PERBAIKAN: Untuk promo dengan validasi kode unik, setiap user harus punya PromoItem sendiri
            // Jadi pertama cek apakah user yang login sudah punya item dengan kode ini
            $userOwnItem = \App\Models\PromoItem::with(['promo', 'user'])
                ->where('code', $code)
                ->where('user_id', $user->id) // item milik user yang sedang login
                ->first();

            if ($userOwnItem) {
                // User sudah punya item dengan kode ini, gunakan item tersebut
                $item = $userOwnItem;
                $promo = $item->promo;
            } else {
                // User belum punya item dengan kode ini
                // Cek apakah ada master promo dengan kode tersebut
                $promo = \App\Models\Promo::where('code', $code)->first();
                
                if (!$promo) {
                    return response()->json(['success' => false, 'message' => 'Promo dengan kode tersebut tidak ditemukan'], 404);
                }

                // Set ownerHint ke user yang sedang login untuk membuat item baru nanti
                $ownerHint = $user->id;
                $item = null; // akan dibuat di createAndValidateNewItem
            }
        }

        if (!$promo) {
            return response()->json(['success' => false, 'message' => 'Promo tidak ditemukan'], 404);
        }

        // Cek expired
        if (!empty($promo->end_date) && now()->greaterThan($promo->end_date)) {
            return response()->json(['success' => false, 'message' => 'Promo kedaluwarsa'], 422);
        }

        // ===== Tentukan tenant & role validator =====
        $tenantId = $this->resolveTenantUserIdByPromo($promo);
        $validatorRole = $data['validator_role'] ?? null;

        if ($validatorRole === 'tenant') {
            if (!$tenantId || $user->id !== $tenantId) {
                return response()->json([
                    'success' => false,
                    'message' => 'QR ini hanya dapat divalidasi oleh tenant pemilik promo.'
                ], 403);
            }
            $validatorId = $user->id;
        } else {
            // user-initiated (kode unik): kreditkan ke tenant agar tampil di riwayat tenant
            $validatorId = $tenantId ?: $user->id;
        }

        // ====== HANDLING BERDASARKAN KONDISI ITEM ======
        
        // Jika item sudah ada, cek idempotensi dan proses
        if ($item) {
            if (!is_null($item->redeemed_at) || in_array($item->status, ['redeemed', 'used'], true)) {
                return response()->json([
                    'success' => true,
                    'message' => 'Promo sudah divalidasi sebelumnya',
                    'data'    => [
                        'promo_item_id' => $item->id,
                        'promo_item'    => $item,
                        'promo'         => $this->transformPromoImageUrls($promo),
                    ],
                ], 200);
            }

            // Item ada tapi belum di-redeem, lakukan transaksi
            return $this->processExistingItemValidation($item, $promo, $validatorId, $code);
        } 
        // Jika item belum ada, buat item baru
        else {
            return $this->createAndValidateNewItem($promo, $ownerHint, $validatorId, $code);
        }
    }

    /**
     * Proses validasi untuk item yang sudah ada
     */
    private function processExistingItemValidation($item, $promo, $validatorId, $code)
    {
        DB::beginTransaction();
        try {
            $locked = \App\Models\PromoItem::with(['promo'])
                ->where('id', $item->id)
                ->lockForUpdate()
                ->first();

            if (!$locked) {
                DB::rollBack();
                return response()->json(['success' => false, 'message' => 'Promo item tidak ditemukan'], 404);
            }

            // Re-check setelah lock (idempotent)
            if (!is_null($locked->redeemed_at) || in_array($locked->status, ['redeemed', 'used'], true)) {
                DB::commit();
                return response()->json([
                    'success' => true,
                    'message' => 'Promo sudah divalidasi sebelumnya',
                    'data'    => [
                        'promo_item_id' => $locked->id,
                        'promo_item'    => $locked,
                        'promo'         => $locked->promo,
                    ],
                ], 200);
            }

            // Update atomik
            $updates = ['redeemed_at' => now()];
            if (Schema::hasColumn('promo_items', 'status')) $updates['status'] = 'redeemed';

            $affected = \App\Models\PromoItem::where('id', $locked->id)
                ->whereNull('redeemed_at')
                ->update($updates);

            if ($affected === 0) {
                DB::commit();
                return response()->json([
                    'success' => true,
                    'message' => 'Promo sudah divalidasi sebelumnya',
                    'data'    => [
                        'promo_item_id' => $locked->id,
                        'promo_item'    => $locked->fresh(),
                        'promo'         => $locked->promo,
                    ],
                ], 200);
            }

            // Simpan validasi
            $this->savePromoValidation($locked, $validatorId, $code);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Validasi berhasil',
                'data'    => [
                    'promo_item_id' => $locked->id,
                    'promo_item'    => $locked->fresh(),
                    'promo'         => $locked->promo,
                ]
            ], 200);
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('processExistingItemValidation error: ' . $e->getMessage(), ['code' => $code, 'item_id' => $item->id]);
            return response()->json(['success' => false, 'message' => 'Gagal memproses validasi'], 500);
        }
    }

    /**
     * Buat item baru dan lakukan validasi
     */
    private function createAndValidateNewItem($promo, $ownerHint, $validatorId, $code)
    {
        // Cek stok dulu
        if (!is_null($promo->stock)) {
            $affected = \App\Models\Promo::where('id', $promo->id)->where('stock', '>', 0)->decrement('stock');
            if ($affected === 0) {
                return response()->json(['success' => false, 'message' => 'Stok promo habis'], 409);
            }
        }

        DB::beginTransaction();
        try {
            // Buat PromoItem baru
            $item = \App\Models\PromoItem::create([
                'promo_id'     => $promo->id,
                'user_id'      => $ownerHint,
                'code'         => $code,
                'status'       => 'redeemed',
                'redeemed_at'  => now(),
                'expires_at'   => $promo->end_date,
            ]);

            // Simpan validasi
            $this->savePromoValidation($item, $validatorId, $code);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Validasi berhasil',
                'data'    => [
                    'promo_item_id' => $item->id,
                    'promo_item'    => $item->load(['promo', 'user']),
                    'promo'         => $this->transformPromoImageUrls($promo),
                ],
            ], 200);
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('createAndValidateNewItem error: ' . $e->getMessage(), ['promo_id' => $promo->id, 'owner_hint' => $ownerHint]);
            return response()->json(['success' => false, 'message' => 'Gagal membuat dan memvalidasi item promo'], 500);
        }
    }

    /**
     * Helper untuk menyimpan validasi promo
     */
    private function savePromoValidation($item, $validatorId, $code)
    {
        $validationData = [
            'user_id'      => $validatorId,
            'promo_id'     => $item->promo_id,
            'validated_at' => now(),
            'code'         => $code,
            'notes'        => 'Validated item_id=' . $item->id . ' owner=' . $item->user_id,
        ];
        
        // Tambahkan promo_item_id jika kolom tersedia
        if (Schema::hasColumn('promo_validations', 'promo_item_id')) {
            $validationData['promo_item_id'] = $item->id;
        } else {
            // Fallback: tambahkan info detail dalam notes
            $validationData['notes'] = 'item_id:' . $item->id . '|owner_id:' . $item->user_id . '|validator_id:' . $validatorId;
        }

        // Cek apakah sudah ada record untuk promo_item_id ini
        $existingValidation = null;
        if (Schema::hasColumn('promo_validations', 'promo_item_id')) {
            $existingValidation = \App\Models\PromoValidation::where('promo_item_id', $item->id)->first();
        }
        
        if (!$existingValidation) {
            \App\Models\PromoValidation::create($validationData);
        } else {
            // Update jika sudah ada (edge case)
            $existingValidation->update([
                'validated_at' => now(),
                'notes' => $validationData['notes']
            ]);
        }
    }

    public function history($promoId)
    {
        Log::info("Fetching history for promo ID: " . $promoId);

        try {
            $promo = Promo::find($promoId);
            if (!$promo) {
                return response()->json([
                    'success' => false,
                    'message' => 'Promo tidak ditemukan'
                ], 404);
            }

            // PERBAIKAN: Query yang menampilkan semua validasi tanpa mapping yang salah
            if (Schema::hasColumn('promo_validations', 'promo_item_id')) {
                // Mode ideal: gunakan promo_item_id untuk akurasi tinggi
                $rows = PromoValidation::query()
                    ->with(['promo', 'user']) // user = validator (tenant)
                    ->leftJoin('promo_items', 'promo_items.id', '=', 'promo_validations.promo_item_id')
                    ->select('promo_validations.*', 'promo_items.user_id as owner_id')
                    ->where('promo_validations.promo_id', $promoId)
                    ->orderBy('promo_validations.validated_at', 'desc')
                    ->get();
            } else {
                // Fallback: ambil semua record dan filter duplikasi dengan hati-hati
                $allRows = PromoValidation::query()
                    ->with(['promo', 'user']) // user = validator (tenant)
                    ->where('promo_validations.promo_id', $promoId)
                    ->orderBy('promo_validations.validated_at', 'desc')
                    ->get();
                    
                // PERBAIKAN: Filter duplikasi berdasarkan item_id yang sebenarnya dari notes
                // tapi tetap pertahankan semua record validation yang valid
                $seen = [];
                $rows = collect();
                
                foreach ($allRows as $row) {
                    $itemId = null;
                    if (preg_match('/item_id:(\d+)/', $row->notes, $matches)) {
                        $itemId = $matches[1];
                    }
                    
                    // Jika ada item_id dalam notes, gunakan sebagai key unik
                    if ($itemId) {
                        if (!isset($seen[$itemId])) {
                            $seen[$itemId] = true;
                            $rows->push($row);
                        }
                    } else {
                        // Jika tidak ada item_id, gunakan kombinasi code + timestamp sebagai fallback
                        $key = $row->code . '|' . $row->validated_at;
                        if (!isset($seen[$key])) {
                            $seen[$key] = true;
                            $rows->push($row);
                        }
                    }
                }
                    
                // Ambil owner_id dari notes
                $rows = $rows->map(function ($row) {
                    if (preg_match('/owner_id:(\d+)/', $row->notes, $matches)) {
                        $row->owner_id = (int)$matches[1];
                    } else {
                        // Fallback: cari dari promo_items berdasarkan code
                        $item = \App\Models\PromoItem::where('code', $row->code)
                            ->where('promo_id', $row->promo_id)
                            ->first();
                        $row->owner_id = $item ? $item->user_id : null;
                    }
                    return $row;
                });
            }

            $ownerIds = $rows->pluck('owner_id')->filter()->unique()->values();
            $owners = $ownerIds->isNotEmpty()
                ? User::whereIn('id', $ownerIds)->get(['id', 'name'])->keyBy('id')
                : collect();

            $data = $rows->map(function ($r) use ($owners) {
                $owner = null;
                if ($r->owner_id && isset($owners[$r->owner_id])) {
                    $owner = ['id' => $r->owner_id, 'name' => $owners[$r->owner_id]->name];
                }
                return [
                    'id'           => $r->id,
                    'code'         => $r->code,
                    'validated_at' => $r->validated_at,
                    'notes'        => $r->notes,
                    'promo'        => $r->promo,
                    'user'         => $r->user ? ['id' => $r->user->id, 'name' => $r->user->name] : null, // validator (tenant)
                    'owner'        => $owner, // pemilik item (user)
                    'itemType'     => 'promo',
                ];
            });

            return response()->json(['success' => true, 'data' => $data]);
        } catch (\Exception $e) {
            Log::error("Error in history method: " . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Gagal mengambil riwayat validasi: ' . $e->getMessage()
            ], 500);
        }
    }

    public function userValidationHistory(Request $request)
    {
        try {
            $userId = $request->user()?->id ?? auth()->id();
            if (!$userId) {
                return response()->json(['success' => false, 'message' => 'User tidak terautentikasi'], 401);
            }

            // PERBAIKAN: Query yang konsisten dengan method history untuk menghindari mapping yang salah
            if (Schema::hasColumn('promo_validations', 'promo_item_id')) {
                // Mode ideal: gunakan promo_item_id untuk akurasi tinggi
                $rows = PromoValidation::query()
                    ->with(['promo', 'user']) // 'user' = validator (tenant)
                    ->leftJoin('promo_items', 'promo_items.id', '=', 'promo_validations.promo_item_id')
                    ->select('promo_validations.*', 'promo_items.user_id as owner_id')
                    ->where(function ($q) use ($userId) {
                        $q->where('promo_items.user_id', $userId)       // user sebagai owner
                            ->orWhere('promo_validations.user_id', $userId); // user sebagai validator (tenant)
                    })
                    ->orderBy('promo_validations.validated_at', 'desc')
                    ->get();
            } else {
                // Fallback: ambil semua record dan filter dengan hati-hati untuk tidak menghilangkan data
                $allRows = PromoValidation::query()
                    ->with(['promo', 'user']) // 'user' = validator (tenant)
                    ->orderBy('promo_validations.validated_at', 'desc')
                    ->get();
                    
                // PERBAIKAN: Filter user tapi tetap pertahankan semua record validation yang relevan
                $userRows = $allRows->filter(function ($r) use ($userId) {
                    // Cek apakah user adalah owner berdasarkan notes
                    $isOwner = preg_match('/owner_id:' . $userId . '/', $r->notes ?? '');
                    // Cek apakah user adalah validator
                    $isValidator = $r->user_id == $userId;
                    
                    return $isOwner || $isValidator;
                });
                
                // PERBAIKAN: Deduplikasi berdasarkan item_id sebenarnya, bukan kombinasi yang bisa menghilangkan data
                $seen = [];
                $rows = collect();
                
                foreach ($userRows as $row) {
                    $itemId = null;
                    if (preg_match('/item_id:(\d+)/', $row->notes, $matches)) {
                        $itemId = $matches[1];
                    }
                    
                    // Jika ada item_id dalam notes, gunakan sebagai key unik
                    if ($itemId) {
                        if (!isset($seen[$itemId])) {
                            $seen[$itemId] = true;
                            $rows->push($row);
                        }
                    } else {
                        // Jika tidak ada item_id, gunakan kombinasi code + timestamp + validator sebagai fallback
                        $key = $row->code . '|' . $row->validated_at . '|' . $row->user_id;
                        if (!isset($seen[$key])) {
                            $seen[$key] = true;
                            $rows->push($row);
                        }
                    }
                }
                
                // Ambil owner_id dari notes jika tidak ada promo_item_id
                $rows = $rows->map(function ($row) {
                    if (preg_match('/owner_id:(\d+)/', $row->notes, $matches)) {
                        $row->owner_id = (int)$matches[1];
                    } else {
                        // Fallback: cari dari promo_items berdasarkan code
                        $item = \App\Models\PromoItem::where('code', $row->code)
                            ->where('promo_id', $row->promo_id)
                            ->first();
                        $row->owner_id = $item ? $item->user_id : null;
                    }
                    return $row;
                });
            }

            // Ambil info owner (jika ada)
            $ownerIds = $rows->pluck('owner_id')->filter()->unique()->values();
            $owners = $ownerIds->isNotEmpty()
                ? User::whereIn('id', $ownerIds)->get(['id', 'name'])->keyBy('id')
                : collect();

            $data = $rows->map(function ($r) use ($owners) {
                $promo = $r->promo;
                if ($promo) {
                    $promo->title = $promo->title ?? 'Promo';
                }

                // owner (pemilik item) — dari join promo_items
                $owner = null;
                if ($r->owner_id && isset($owners[$r->owner_id])) {
                    $owner = [
                        'id'   => $r->owner_id,
                        'name' => $owners[$r->owner_id]->name,
                    ];
                }

                return [
                    'id'           => $r->id,
                    'code'         => $r->code,
                    'validated_at' => $r->validated_at,
                    'notes'        => $r->notes,
                    'promo'        => $promo,
                    // user = validator (tenant) -> dipakai FE untuk "Divalidasi oleh ..."
                    'user'         => $r->user ? ['id' => $r->user->id, 'name' => $r->user->name] : null,
                    // owner = pemilik promo item -> dipakai FE sisi tenant untuk "Promo milik ..."
                    'owner'        => $owner,
                    'itemType'     => 'promo',
                ];
            });

            return response()->json(['success' => true, 'data' => $data]);
        } catch (\Throwable $e) {
            Log::error("Error in userValidationHistory (promo): " . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Gagal mengambil riwayat validasi: ' . $e->getMessage()], 500);
        }
    }
}
