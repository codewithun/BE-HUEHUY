<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Banner;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;

class BannerController extends Controller
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
        $model = new Banner();
        $query = Banner::query();

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

    // ========================================>
    // ## Display the resource.
    // ========================================>
    public function show(Request $request, $id)
    {
        $model = Banner::where('id', $id)->first();

        if (!$model) {
            return response([
                'message' => 'Data not found'
            ], 404);
        }

        // ? When success
        return response([
            "message" => "success",
            "data" => $model,
        ]);
    }

    // =============================================>
    // ## Store a newly created resource in storage.
    // =============================================>
    public function store(Request $request)
    {
        // ? Validate request (mirror PromoController image rules)
        $validator = Validator::make($request->all(), [
            'image' => 'nullable|image|mimes:jpeg,png,jpg,gif,svg,webp|max:10240',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validasi gagal',
                'errors'  => $validator->errors(),
            ], 422);
        }

        // ? Initial
        try {
            DB::beginTransaction();
            $model = new Banner();

            if ($request->hasFile('image')) {
                $path = $request->file('image')->store('banner', 'public');
                $model->picture_source = $path;
            }

            $model->save();
            DB::commit();

            return response([
                'message' => 'success',
                'data'    => $model,
            ], 201);
        } catch (\Throwable $th) {
            DB::rollBack();
            Log::error('Error creating banner: ' . $th->getMessage());
            return response([
                'message' => 'Error: server side having problem!',
            ], 500);
        }
    }

    // ============================================>
    // ## Update the specified resource in storage.
    // ============================================>
    public function update(Request $request, string $id)
    {
        // ? Initial
        DB::beginTransaction();
        $model = Banner::findOrFail($id);
        $oldPicture = $model->picture_source;

        // ? Validate request (mirror PromoController update behavior)
        if (!$request->hasFile('image')) {
            // Remove potential string URL from FE defaultValue to avoid validation error
            $request->request->remove('image');
        }

        $validator = Validator::make($request->all(), [
            'image' => 'sometimes|file|image|mimes:jpeg,png,jpg,gif,svg,webp|max:10240',
        ]);

        if ($validator->fails()) {
            DB::rollBack();
            return response()->json([
                'message' => 'Validasi gagal',
                'errors'  => $validator->errors(),
            ], 422);
        }

        // * Check if has upload file
        if ($request->hasFile('image')) {
            // Delete old file if exists
            if (!empty($oldPicture)) {
                Storage::disk('public')->delete($oldPicture);
            }
            // Store new file
            $model->picture_source = $request->file('image')->store('banner', 'public');
        }

        // ? Executing
        try {
            $model->save();
        } catch (\Throwable $th) {
            DB::rollBack();
            Log::error('Error updating banner: ' . $th->getMessage());
            return response([
                'message' => 'Error: server side having problem!',
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
        $model = Banner::findOrFail($id);

        // * remove picture
        if ($model->picture_source) {
            Storage::disk('public')->delete($model->picture_source ?? '');
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
        