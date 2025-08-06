<?php

namespace App\Http\Controllers\Corporate;

use App\Http\Controllers\Controller;
use App\Models\OpeningHour;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class OpeningHourController extends Controller
{
    // =============================================>
    // ## Store a newly created resource in storage.
    // =============================================>
    public function store(Request $request)
    {
        // ? Validate request
        $validation = $this->validation($request->all(), [
            'cube_id' => 'required|numeric|exists:cubes,id',
            'data.*.day' => ['required', 'string', Rule::in(['senin', 'selasa', 'rabu', 'kamis', 'jumat', 'sabtu', 'minggu'])],
            'data.*.open' => 'nullable|date_format:H:i',
            'data.*.close' => 'nullable|date_format:H:i',
            'data.*.is_24hour' => 'nullable|boolean',
            'data.*.is_closed' => 'nullable|boolean',
        ]);

        if ($validation) return $validation;

        // ? Initial & Execute
        DB::beginTransaction();
        $preparedOpeningHourData = [];
        if (count($request->data) > 0) {

            foreach($request->data as $data) {
                array_push($preparedOpeningHourData, [
                    'cube_id' => $request->cube_id,
                    'day' => $data['day'],
                    'open' => $data['open'] ?? null,
                    'close' => $data['close'] ?? null,
                    'is_24hour' => $data['is_24hour'] ?? null,
                    'is_closed' => $data['is_closed'] ?? null,
                    'created_at' => Carbon::now(),
                    'updated_at' => Carbon::now(),
                ]);
            }

            try {
                OpeningHour::insert($preparedOpeningHourData);
            } catch (\Throwable $th) {
                DB::rollBack();
                return response([
                    "message" => "Error: server side having problem!",
                ], 500);
            }
        }

        DB::commit();

        return response([
            "message" => "success",
            "data" => $preparedOpeningHourData
        ], 201);
    }

    // ============================================>
    // ## Update the specified resource in storage.
    // ============================================>
    public function update(Request $request, string $id)
    {
        // ? Initial
        DB::beginTransaction();
        $model = OpeningHour::findOrFail($id);

        // ? Validate request
        $validation = $this->validation($request->all(), [
            'cube_id' => 'required|numeric|exists:cubes,id',
            'day' => ['required', 'string', Rule::in(['senin', 'selasa', 'rabu', 'kamis', 'jumat', 'sabtu', 'minggu'])],
            'open' => 'nullable|date_format:H:i',
            'close' => 'nullable|date_format:H:i',
            'is_24hour' => 'nullable|boolean',
            'is_closed' => 'nullable|boolean',
        ]);

        if ($validation) return $validation;

        // ? Dump data
        $model = $this->dump_field($request->all(), $model);
        if (!$request->is_24hour) {
            $model->is_24hour = null;
        }
        if (!$request->is_closed) {
            $model->is_closed = null;
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
    // ## Remove the specified resource from storage.
    // ===============================================>
    public function destroy(string $id)
    {
        // ? Initial
        $model = OpeningHour::findOrFail($id);

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
        