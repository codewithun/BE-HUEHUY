<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CubeType;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CubeTypeController extends Controller
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
        $model = new CubeType();
        $query = CubeType::query();

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
        $paginator = $query
            ->orderBy($this->remark_column($sortby, $columnAliases), $sortDirection)
            ->select($model->selectable)
            ->paginate($paginate);

        // ? Standardized response shape for FE
        return response([
            "message" => "success",
            "data" => $paginator->items(),
            "total_row" => $paginator->total(),
        ], 200);
    }

    // ============================================>
    // ## Update the specified resource in storage.
    // ============================================>
    public function update(Request $request, string $id)
    {
        // ? Initial
        DB::beginTransaction();
        $model = CubeType::findOrFail($id);

        // ? Validate request
        $validation = $this->validation($request->all(), [
            'name' => 'required|string|max:255',
            'code' => 'required|string|max:255',
            'color' => 'required|string|max:10',
            'description' => 'required|string|max:255'
        ]);

        if ($validation) return $validation;

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

    // ============================================>
    // ## Store a newly created resource in storage.
    // ============================================>
    public function store(Request $request)
    {
        DB::beginTransaction();

        // ? Validate request
        $validation = $this->validation($request->all(), [
            'name' => 'required|string|max:255',
            'code' => 'required|string|max:255',
            'color' => 'required|string|max:10',
            'description' => 'required|string|max:255',
        ]);
        if ($validation) return $validation;

        try {
            $model = new CubeType();
            $model = $this->dump_field($request->all(), $model);
            $model->save();

            DB::commit();
            return response([
                "message" => "success",
                "data" => $model,
            ], 201);
        } catch (\Throwable $th) {
            DB::rollBack();
            return response([
                "message" => "Error: server side having problem!",
            ], 500);
        }
    }

    // ============================================>
    // ## Display the specified resource.
    // ============================================>
    public function show(string $id)
    {
        $model = CubeType::findOrFail($id);
        return response([
            "message" => "success",
            "data" => $model,
        ], 200);
    }

    // ============================================>
    // ## Remove the specified resource from storage.
    // ============================================>
    public function destroy(string $id)
    {
        DB::beginTransaction();

        try {
            $model = CubeType::findOrFail($id);
            $model->delete();
            DB::commit();

            return response([
                "message" => "success",
            ], 200);
        } catch (\Throwable $th) {
            DB::rollBack();
            return response([
                "message" => "Error: server side having problem!",
            ], 500);
        }
    }
}
        