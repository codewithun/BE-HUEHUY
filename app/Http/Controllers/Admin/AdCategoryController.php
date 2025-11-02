<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdCategory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;

class AdCategoryController extends Controller
{
    // ========================================>
    // ## Display a listing of the resource.
    // ========================================>
    public function index(Request $request)
    {
        $sortDirection = $request->get('sortDirection', 'DESC');
        $sortby = $request->get('sortBy', 'created_at');
        $paginate = $request->get('paginate', 10);
        $filter = $request->get('filter');

        $columnAliases = [];
        $model = new AdCategory();
        $query = AdCategory::query();

        if ($request->filled('search')) {
            $query = $this->search($request->get('search'), $model, $query);
        }

        if ($filter) {
            $filters = json_decode($filter);
            foreach ($filters as $column => $value) {
                $query = $this->filter($this->remark_column($column, $columnAliases), $value, $model, $query);
            }
        }

        // filter community (global + spesifik jika diisi)
        if ($request->filled('community_id') && $request->community_id !== 'null') {
            $query->where('community_id', $request->community_id);
        }

        $query->orderBy($this->remark_column($sortby, $columnAliases), $sortDirection);

        // transformer agar FE dapat label/value + semua field asli
        $mapRow = function ($row) {
            return [
                'id'                => $row->id,
                'label'             => $row->name,
                'value'             => $row->id,
                'name'              => $row->name,
                'picture_source'    => $row->picture_source,
                'image'             => $row->picture_source ? Storage::url($row->picture_source) : null,
                'parent_id'         => $row->parent_id,
                'is_primary_parent' => (int) $row->is_primary_parent,
                'is_home_display'   => (int) $row->is_home_display,
                'community_id'      => $row->community_id,
                'created_at'        => $row->created_at,
                'updated_at'        => $row->updated_at,
            ];
        };

        // === penting: dukung paginate=all ===
        $isAll = is_string($paginate) && strtolower($paginate) === 'all';
        if ($isAll) {
            $rows = $query->get();
            if ($rows->isEmpty()) {
                return response(['message' => 'empty data', 'data' => []], 200);
            }
            return response([
                'message'   => 'success',
                'data'      => $rows->map($mapRow)->values(),
                'total_row' => $rows->count(),
            ], 200);
        }

        // numeric paginate
        $perPage = (int) $paginate;
        $page = $query->paginate($perPage);

        if (empty($page->items())) {
            return response(['message' => 'empty data', 'data' => []], 200);
        }

        return response([
            'message'   => 'success',
            'data'      => collect($page->items())->map($mapRow)->values(),
            'total_row' => $page->total(),
        ], 200);
    }

    // =============================================>
    // ## Store a newly created resource in storage.
    // =============================================>
    public function store(Request $request)
    {
        // Normalisasi checkbox (array -> 1/0) dan parent_id (array -> scalar)
        $request->merge([
            'is_primary_parent' => $this->normalizeBoolean($request->input('is_primary_parent')),
            'is_home_display'   => $this->normalizeBoolean($request->input('is_home_display')),
            'parent_id'         => $this->normalizeScalar($request->input('parent_id')),
        ]);

        $validation = $this->validation($request->all(), [
            'name' => 'required|string|max:255',
            'image' => 'nullable|image|mimes:jpg,jpeg,png,webp,avif|max:4096',
            'parent_id' => 'nullable|exists:ad_categories,id',
            'is_primary_parent' => 'nullable|boolean',
            'is_home_display' => 'nullable|boolean',
            'community_id' => 'nullable|numeric|exists:communities,id',
        ]);
        if ($validation) return $validation;

        DB::beginTransaction();
        $model = new AdCategory();

        // Batasi field yang di-dump (hindari 'image' & field liar)
        $payload = $request->only([
            'name',
            'parent_id',
            'is_primary_parent',
            'is_home_display',
            'community_id',
        ]);

        $model = $this->dump_field($payload, $model);
        $model->is_primary_parent = $request->input('is_primary_parent') ? 1 : 0;
        $model->is_home_display = $request->input('is_home_display') ? 1 : 0;

        if ($request->hasFile('image')) {
            $model->picture_source = $request->file('image')->store('ad-category', 'public');
            $model->image_updated_at = now();
        }

        try {
            $model->save();
            DB::commit();
            return response(["message" => "success", "data" => $model], 201);
        } catch (\Throwable $th) {
            DB::rollBack();
            return response(["message" => "Error: server side having problem! - " . $th->getMessage()], 500);
        }
    }

    // ============================================>
    // ## Update the specified resource in storage.
    // ============================================>
    public function update(Request $request, string $id)
    {
        DB::beginTransaction();
        $model = AdCategory::findOrFail($id);
        $oldPicture = $model->picture_source;

        // Normalisasi sebelum validasi
        $request->merge([
            'is_primary_parent' => $this->normalizeBoolean($request->input('is_primary_parent')),
            'is_home_display'   => $this->normalizeBoolean($request->input('is_home_display')),
            'parent_id'         => $this->normalizeScalar($request->input('parent_id')),
        ]);

        $validation = $this->validation($request->all(), [
            'name' => 'required|string|max:255',
            'image' => 'nullable|image|mimes:jpg,jpeg,png,webp,avif|max:4096',
            'parent_id' => 'nullable|exists:ad_categories,id',
            'is_primary_parent' => 'nullable|boolean',
            'is_home_display' => 'nullable|boolean',
            'community_id' => 'nullable|numeric|exists:communities,id',
        ]);
        if ($validation) return $validation;

        // Batasi field yang di-dump
        $payload = $request->only([
            'name',
            'parent_id',
            'is_primary_parent',
            'is_home_display',
            'community_id',
        ]);

        $model = $this->dump_field($payload, $model);
        $model->is_primary_parent = $request->input('is_primary_parent') ? 1 : 0;
        $model->is_home_display = $request->input('is_home_display') ? 1 : 0;

        if ($request->hasFile('image')) {
            $model->picture_source = $request->file('image')->store('ad-category', 'public');
            if ($oldPicture) {
                Storage::disk('public')->delete($oldPicture);
            }
            $model->image_updated_at = now();
        }

        try {
            $model->save();
            DB::commit();
            return response(["message" => "success", "data" => $model]);
        } catch (\Throwable $th) {
            DB::rollBack();
            return response(["message" => "Error: server side having problem! - " . $th->getMessage()], 500);
        }
    }

    // ===============================================>
    // ## Display the specified resource.
    // ===============================================>
    public function show(string $id)
    {
        $model = AdCategory::findOrFail($id);
        return response([
            'message' => 'success',
            'data' => $model,
        ]);
    }

    // ===============================================>
    // ## Get options for select dropdown.
    // ===============================================>
    public function options(Request $request)
    {
        $q = AdCategory::query();

        if ($request->filled('parent_only')) {
            $q->whereNull('parent_id');
        }

        // tampilkan global + spesifik community
        if ($request->filled('community_id') && $request->community_id !== 'null') {
            $q->where(function ($qq) use ($request) {
                $qq->whereNull('community_id')->orWhere('community_id', $request->community_id);
            });
        }

        if ($request->filled('search')) {
            $q->where('name', 'like', '%' . $request->get('search') . '%');
        }

        $rows = $q->orderBy('name')->get();

        // full=1 -> semua field + url gambar
        if ($request->boolean('full')) {
            $data = $rows->map(function ($row) {
                return [
                    'id'                => $row->id,
                    'label'             => $row->name,
                    'value'             => $row->id,
                    'name'              => $row->name,
                    'picture_source'    => $row->picture_source,
                    'image'             => $row->picture_source ? Storage::url($row->picture_source) : null,
                    'parent_id'         => $row->parent_id,
                    'is_primary_parent' => (int) $row->is_primary_parent,
                    'is_home_display'   => (int) $row->is_home_display,
                    'community_id'      => $row->community_id,
                    'created_at'        => $row->created_at,
                    'updated_at'        => $row->updated_at,
                ];
            });
            return response(['message' => 'success', 'data' => $data], 200);
        }

        // default ringan
        return response([
            'message' => 'success',
            'data'    => $rows->map(fn($r) => ['value' => $r->id, 'label' => $r->name]),
        ], 200);
    }

    // ===============================================>
    // ## Remove the specified resource from storage.
    // ===============================================>
    public function destroy(string $id)
    {
        // ? Initial
        $model = AdCategory::findOrFail($id);

        // * Remove picture
        if ($model->picture_source) {
            Storage::disk('public')->delete($model->picture_source);
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
            "data" => $model
        ]);
    }

    // Helper untuk normalisasi checkbox
    private function normalizeBoolean($value)
    {
        if (is_array($value)) {
            return count($value) > 0 ? 1 : 0;
        }
        if ($value === '' || is_null($value)) {
            return null;
        }
        return filter_var($value, FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
    }

    // Helper untuk normalisasi value yang mungkin array
    private function normalizeScalar($value)
    {
        if (is_array($value)) {
            return count($value) ? reset($value) : null;
        }
        return $value !== '' ? $value : null;
    }
}
