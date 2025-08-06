<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Corporate;
use App\Models\World;
use App\Models\WorldAffiliate;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class WorldAffiliateController extends Controller
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
        $model = new WorldAffiliate();
        $query = WorldAffiliate::with('world', 'world.corporate', 'corporate');

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
            'world_id' => 'required|numeric|exists:worlds,id',
            'corporate_id' => 'required|numeric|exists:corporates,id'
        ]);

        if ($validation) return $validation;

        // * Validate not duplicate corporate
        $corporateAffiliate = WorldAffiliate::where('world_id', $request->world_id)
            ->where('corporate_id', $request->corporate_id)
            ->first();

        if ($corporateAffiliate) {
            return response([
                "message" => "Error: Unprocessable Entity!",
                "errors" => [
                    "corporate_id" => [
                        "Duplicate corporate on this world affiliate"
                    ]
                ]
            ], 422);
        }

        // ? Initial
        DB::beginTransaction();
        $model = new WorldAffiliate();

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
        ], 201);
    }

    // ============================================>
    // ## Update the specified resource in storage.
    // ============================================>
    public function update(Request $request, string $id)
    {
        // ? Initial
        DB::beginTransaction();
        $model = WorldAffiliate::findOrFail($id);

        // ? Validate request
        $validation = $this->validation($request->all(), [
            'world_id' => 'required|numeric|exists:worlds,id',
            'corporate_id' => 'required|numeric|exists:corporates,id'
        ]);

        if ($validation) return $validation;

        // * Validate not duplicate corporate
        $corporateAffiliate = WorldAffiliate::where('world_id', $request->world_id)
            ->where('corporate_id', $request->corporate_id)
            ->first();

        if ($corporateAffiliate) {
            return response([
                "message" => "Error: Unprocessable Entity!",
                "errors" => [
                    "corporate_id" => [
                        "Duplicate corporate on this world affiliate"
                    ]
                ]
            ], 422);
        }

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

    // ===============================================>
    // ## Remove the specified resource from storage.
    // ===============================================>
    public function destroy(string $id)
    {
        // ? Initial
        $model = WorldAffiliate::findOrFail($id);

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
        