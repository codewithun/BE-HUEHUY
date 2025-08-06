<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\DynamicContent;
use App\Models\DynamicContentCube;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class DynamicContentController extends Controller
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
        $world_id = $request->get("world_id", null);

        // ? Preparation
        $columnAliases = [];

        // ? Begin
        $model = new DynamicContent();
        $query = DynamicContent::with('dynamic_content_cubes', 'dynamic_content_cubes.cube', 'dynamic_content_cubes.cube.cube_type', 'dynamic_content_cubes.cube.ads');

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

        if ($world_id) {
            $query->where('world_id', $world_id);
        } else {
            $query->whereNull('world_id');
        }

        // ? Sort & executing with pagination
        $query = $query->orderBy('level', 'asc')
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
            'description' => 'required|string|max:255',
            'type' => ['required', Rule::in(['home', 'hunting'])],
            'content_type' => ['required', Rule::in(['nearby', 'horizontal', 'vertical', 'category', 'ad_category', 'recommendation'])],
            'source_type' => ['nullable', Rule::in(['cube', 'ad', 'shuffle_cube'])],
            // 'level' => 'required|numeric',
            // 'is_active' => 'required|boolean',
            'world_id' => 'nullable',

            'dynamic_content_cubes' => [Rule::requiredIf($request->source_type == 'cube')],
            // 'dynamic_content_cubes.*.cube_id' => 'required|numeric|exists:cubes,id'

        ]);

        if ($validation) return $validation;

        // ? Initial
        DB::beginTransaction();
        $model = new DynamicContent();

        $last_level = DynamicContent::where('type', $request->type)->where('world_id', $request->world_id)->orderBy('level', 'DESC')->first('level');

        // ? Dump data
        $model = $this->dump_field($request->all(), $model);
        $model->level = ($last_level && $last_level->level) ? $last_level->level + 1 : 1;
        $model->is_active = 1;

        // ? Executing
        try {
            $model->save();
        } catch (\Throwable $th) {
            DB::rollBack();
            return response([
                "message" => "Error: server side having problem!",
            ], 500);
        }

        // * Handle Dynamic Content Cubes
        if ($request->source_type == 'cube') {
            $cubes = explode(',', $request->dynamic_content_cubes);
            $prepareDynamicContentCubes = [];
            foreach ($cubes as $item) {

                array_push($prepareDynamicContentCubes, [
                    'dynamic_content_id' => $model->id,
                    'cube_id' => $item,
                    'created_at' => Carbon::now()
                ]);
            }

            try {
                DynamicContentCube::insert($prepareDynamicContentCubes);
            } catch (\Throwable $th) {
                DB::rollBack();
                return response([
                    "message" => "Error: failed to insert new dynamic content cubes",
                ], 500);
            }
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
        $model = DynamicContent::findOrFail($id);

        // ? Validate request
        $validation = $this->validation($request->all(), [
            'name' => 'nullable|string|max:255',
            'description' => 'nullable|string|max:255',
            'type' => ['nullable', Rule::in(['home', 'hunting'])],
            'content_type' => ['nullable', Rule::in(['nearby', 'horizontal', 'vertical', 'category', 'ad_category', 'recommendation'])],
            'source_type' => ['nullable', Rule::in(['cube', 'ad', 'shuffle_cube'])],
            'level' => 'nullable|numeric',
            'is_active' => 'nullable|boolean',
            'world_id' => 'nullable',

            'dynamic_content_cubes' => [Rule::requiredIf($request->source_type == 'cube')],
        ]);

        if ($validation) return $validation;

        if($request->level) {
            if($request->level < $model->level) {
                $lowest_stages = DynamicContent::where('type', $model->type)->where('world_id', $model->world_id)->where('level', '<', $model->level)->where('level', '>=', $request->level )->get();
    
                foreach ($lowest_stages as $lowest_stage) {
                    $lowest_stage->update(['level' => $lowest_stage->level + 1]);
                }
            }

            if($request->level > $model->level) {
                $lowest_stages = DynamicContent::where('type', $model->type)->where('world_id', $model->world_id)->where('level', '>', $model->level)->where('level', '<=', $request->level )->get();

                foreach ($lowest_stages as $lowest_stage) {
                    $lowest_stage->update(['level' => $lowest_stage->level - 1]);
                }
            }
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

        // * Handle Dynamic Content Cubes
        if ($request->source_type == 'cube') {
            $cubes = explode(',', $request->dynamic_content_cubes);
            $prepareDynamicContentCubes = [];

            DynamicContentCube::where('dynamic_content_id', $model->id)->delete();

            foreach ($cubes as $item) {

                array_push($prepareDynamicContentCubes, [
                    'dynamic_content_id' => $model->id,
                    'cube_id' => $item,
                    'created_at' => Carbon::now()
                ]);
            }

            try {
                DynamicContentCube::insert($prepareDynamicContentCubes);
            } catch (\Throwable $th) {
                DB::rollBack();
                return response([
                    "message" => "Error: failed to insert new dynamic content cubes",
                ], 500);
            }
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
        $model = DynamicContent::findOrFail($id);

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
        