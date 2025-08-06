<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\AppConfig;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AppConfigController extends Controller
{
    // ========================================>
    // ## Display a listing of app config.
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
        $model = new AppConfig();
        $query = AppConfig::query();

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

    // ============================================>
    // ## Update the specified resource in app config storage.
    // ============================================>
    public function update(Request $request, string $id)
    {
        // ? Initial
        DB::beginTransaction();
        $model = AppConfig::findOrFail($id);

        // ? Validate request
        $validation = $this->validation($request->all(), [
            'code' => 'required|string|max:128',
            'name' => 'required|string|max:255',
            'description' => 'required|string|max:255',
            'value' => 'nullable|json'
        ]);

        if ($validation) return $validation;

        // ? Dump data
        $model = $this->dump_field($request->all(), $model);
        // try {
        //     $model->value = json_encode($request->value);
        // } catch (\Throwable $th) {
        //     return response([
        //         'message' => 'Failed to encode json input',
        //         'errors' => $th
        //     ]);
        // }

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

    // ============================================>
    // ## Display specific app config using id or code.
    // ============================================>
    public function show(string $id)
    {
        $model = AppConfig::where(function ($query) use ($id) {
            return $query->where('id', $id)
                ->orWhere('code', $id);
        })->first();

        if (!$model) {
            return response ([
                'message' => 'Data not found'
            ], 404);
        }

        return response([
            'message' => 'Success',
            'data' => $model
        ]);
    }

    // ============================================>
    // ## Update the other category product app config.
    // ============================================>
    public function updateOtherCategoryProduct(Request $request)
    {
        // ? Initial
        DB::beginTransaction();
        $model = AppConfig::where('code', 'OTHER_CATEGORY_PRODUCT')
            ->first();

        if (!$model) {
            return response([
                'message' => 'Config OTHER_CATEGORY_PRODUCT not found'
            ], 404);
        }

        // $oldPicture = $model->value['picture_source'] ?? null;

        // ? Validate request
        $validation = $this->validation($request->all(), [
            // 'code' => 'required|string|max:128',
            // 'name' => 'required|string|max:255',
            // 'description' => 'required|string|max:255',
            // 'value' => 'required',
            // 'value.name' => 'required|string|max:128',
            'value' => 'nullable',
        ]);

        if ($validation) return $validation;

        // ? Dump data
        // $model = $this->dump_field($request->all(), $model);

        $val = [];
        if ($request->value) {

            // $val['name'] = $model;

            if (!is_string($request->value) && $request->hasFile('value')) {

                $val['picture_source'] = $this->upload_file($request->file('value'), 'ad-category');

                // if ($oldPicture) {
                //     $this->delete_file($oldPicture ?? '');
                // }
            }
        }

        $model->value = json_encode($val);

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
            'message' => 'Success',
            'data' => $model
        ]);
    }
}
        