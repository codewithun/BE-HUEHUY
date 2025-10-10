<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdCategory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

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
            'image' => 'nullable|image|mimes:jpg,jpeg,png,webp,avif|max:4096',
            'parent_id' => 'nullable|exists:ad_categories,id',
            'is_primary_parent' => 'nullable|boolean',
            'is_home_display' => 'nullable|boolean',
        ]);

        if ($validation) return $validation;

        // ? Initial
        DB::beginTransaction();
        $model = new AdCategory();

        // ? Dump data
        $model = $this->dump_field($request->all(), $model);
        $model->is_primary_parent = !!$request->is_primary_parent;
        $model->is_home_display = !!$request->is_home_display;

        // * Check if has upload file
        if ($request->hasFile('image')) {
            $model->picture_source = $request->file('image')->store('ad-category', 'public');
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
        $model = AdCategory::findOrFail($id);
        $oldPicture = $model->picture_source;

        // ? Validate request
        $validation = $this->validation($request->all(), [
            'name' => 'required|string|max:255',
            'image' => 'nullable|image|mimes:jpg,jpeg,png,webp,avif|max:4096',
            'parent_id' => 'nullable|exists:ad_categories,id',
            'is_primary_parent' => 'nullable|boolean',
            'is_home_display' => 'nullable|boolean',
        ]);

        if ($validation) return $validation;

        // ? Dump data
        $model = $this->dump_field($request->all(), $model);
        $model->is_primary_parent = !!$request->is_primary_parent;
        $model->is_home_display = !!$request->is_home_display;

        // * Check if has upload file
        if ($request->hasFile('image')) {
            $model->picture_source = $request->file('image')->store('ad-category', 'public');
            if ($oldPicture) {
                Storage::disk('public')->delete($oldPicture);
            }
            $model->image_updated_at = now(); // Add cache-busting timestamp
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
            "data" => $model
        ]);
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
}
