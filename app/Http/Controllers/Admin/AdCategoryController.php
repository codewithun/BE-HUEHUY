<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdCategory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log; // tambahkan

class AdCategoryController extends Controller
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
        $model = new AdCategory();
        $query = AdCategory::query();

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

        // Filter opsional berdasarkan community (tampilkan global + spesifik jika tidak diisi)
        if ($request->filled('community_id') && $request->community_id !== 'null') {
            $query->where('community_id', $request->community_id);
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
            return response([ "message" => "success", "data" => $model ], 201);
        } catch (\Throwable $th) {
            DB::rollBack();
            Log::error('AdCategory store failed', [
                'error' => $th->getMessage(),
                'trace' => $th->getTraceAsString(),
                'payload' => $payload,
            ]);
            return response([ "message" => "Error: server side having problem! - ".$th->getMessage() ], 500);
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
            return response([ "message" => "success", "data" => $model ]);
        } catch (\Throwable $th) {
            DB::rollBack();
            Log::error('AdCategory update failed', [
                'error' => $th->getMessage(),
                'trace' => $th->getTraceAsString(),
                'payload' => $payload,
                'id' => $id,
            ]);
            return response([ "message" => "Error: server side having problem! - ".$th->getMessage() ], 500);
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
        // Basic: return id + name; filter by parent or top-level if needed
        $q = AdCategory::query();

        if ($request->filled('parent_only')) {
            $q->whereNull('parent_id'); // for top-level
        }

        // Tambahan: filter by community agar konsisten dengan create/update
        if ($request->filled('community_id') && $request->community_id !== 'null') {
            $q->where(function ($qq) use ($request) {
                $qq->whereNull('community_id')
                   ->orWhere('community_id', $request->community_id);
            });
        }

        if ($request->filled('search')) {
            $q->where('name', 'like', '%' . $request->get('search') . '%');
        }

        return response([
            'message' => 'success',
            'data' => $q->orderBy('name')->get(['id as value', 'name as label']),
        ]);
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
