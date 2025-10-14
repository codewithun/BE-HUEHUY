<?php

namespace App\Helpers;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;

trait ControllerHelper
{
    // =========================>
    // ## Remark column
    // =========================>
    protected function remark_column(
        string $column,   //? column alias
        array $remarks   //? sql column
    ) {
        return isset($remarks[$column]) ? $remarks[$column] :  $column;
    }

    // =========================>
    // ## Filter
    // =========================>
    protected function filter(
        string $column,    //? sql column
        string $control,   //? type & value of filter
        $model,            //? eloquent model
        $query = null      //? when use custom query
    ) {
        $type = explode(':', $control)[0];
        $value = explode(':', $control)[1];
        $expColumn = explode('.', $column);
        $queryDump = !is_null($query) ? $query : $model;

        if ($type) {
            // =========================>
            // ## Equal operator
            // =========================>
            if ($type == 'eq') {
                if (count($expColumn) > 1 && $expColumn[0] != $model->getTable()) {
                    $queryDump = $queryDump->join($expColumn[0], function ($join) use ($model, $expColumn, $value) {
                        $join->on($model->getTable() . ".id", "$expColumn[0]." . substr($model->getTable(), 0, -1) . "_id")
                            ->where("$expColumn[0].$expColumn[1]", $value);
                    });
                } else {
                    $queryDump = $queryDump->where($column, $value);
                }

                // =========================>
                // ## Not equal operator
                // =========================>
            } else if ($type == 'ne') {
                if (count($expColumn) > 1 && $expColumn[0] != $model->getTable()) {
                    $queryDump = $queryDump->join($expColumn[0], function ($join) use ($model, $expColumn, $value) {
                        $join->on($model->getTable() . ".id", "$expColumn[0]." . substr($model->getTable(), 0, -1) . "_id")
                            ->where("$expColumn[0].$expColumn[1]", '!=', $value);
                    });
                } else {
                    $queryDump = $queryDump->where($column, '!=', $value);
                }

                // =========================>
                // ## In operator
                // =========================>
            } else if ($type == 'in') {
                if (count($expColumn) > 1 && $expColumn[0] != $model->getTable()) {
                    $queryDump = $queryDump->join($expColumn[0], function ($join) use ($model, $expColumn, $value) {
                        $join->on($model->getTable() . ".id", "$expColumn[0]." . substr($model->getTable(), 0, -1) . "_id")
                            ->whereIn("$expColumn[0].$expColumn[1]", explode(',', $value));
                    });
                } else {
                    $queryDump = $queryDump->whereIn($column, explode(',', $value));
                }

                // =========================>
                // ## Not in operator
                // =========================>
            } else if ($type == 'ni') {
                if (count($expColumn) > 1 && $expColumn[0] != $model->getTable()) {
                    $queryDump = $queryDump->join($expColumn[0], function ($join) use ($model, $expColumn, $value) {
                        $join->on($model->getTable() . ".id", "$expColumn[0]." . substr($model->getTable(), 0, -1) . "_id")
                            ->whereNotIn("$expColumn[0].$expColumn[1]", explode(',', $value));
                    });
                } else {
                    $queryDump = $queryDump->whereNotIn($column, explode(',', $value));
                }

                // =========================>
                // ## Between operator
                // =========================>
            } else if ($type == 'bw' && $expColumn[0] != $model->getTable()) {
                if (count($expColumn) > 1 && $expColumn[0] != $model->getTable()) {
                    $queryDump = $queryDump->join($expColumn[0], function ($join) use ($model, $expColumn, $value) {
                        $join->on($model->getTable() . ".id", "$expColumn[0]." . substr($model->getTable(), 0, -1) . "_id")
                            // ->whereBetween("$expColumn[0].$expColumn[1]", explode(',', $value));
                            ->where("$expColumn[0].$expColumn[1]", '>=', explode(',', $value)[0])
                            ->where("$expColumn[0].$expColumn[1]", '<=', explode(',', $value)[1]);
                    });
                } else {
                    $queryDump = $queryDump->where($column, '>=', explode(',', $value)[0])
                        ->where($column, '<=', explode(',', $value)[1]);
                }

                // =========================>
                // ## Not between operator
                // =========================>
            } else if ($type == 'nb') {
                if (count($expColumn) > 1 && $expColumn[0] != $model->getTable()) {
                    $queryDump = $queryDump->join($expColumn[0], function ($join) use ($model, $expColumn, $value) {
                        $join->on($model->getTable() . ".id", "$expColumn[0]." . substr($model->getTable(), 0, -1) . "_id")
                            ->whereNotBetween("$expColumn[0].$expColumn[1]", explode(',', $value));
                    });
                } else {
                    $queryDump = $queryDump->whereNotBetween($column, explode(',', $value));
                }
            }
        }

        return $queryDump;
    }


    // =========================>
    // ## Search
    // =========================>
    protected function search(
        string $keyword,        //? keyword of search
        $model,                 //? eloquent model
        $query = null,          //? when use custom query
        array $searchable = []  //? when include custom searchable
    ) {
        $model = (!is_null($query) ? $query : $model)->where(function ($query) use ($keyword, $model, $searchable) {
            foreach (isset($model->searchable) && count($model->searchable) ? [...$searchable, ...$model->searchable] : $searchable as $search_column) {
                $expColumn = explode('.', $search_column);

                if (count($expColumn) > 1 && $expColumn[0] != $model->getTable()) {
                    $initial_where = "";
                    $column = $expColumn[count($expColumn) - 1];

                    $tables = array_slice($expColumn, 0, count($expColumn) - 1);

                    $closers = '';
                    foreach ($tables as $key => $table) {
                        $expTable = explode(':', $table);

                        $last_letter_table = strtolower($expTable[0][strlen($expTable[0]) - 1]);
                        $table_name = '';
                        switch ($last_letter_table) {
                            case 'y':
                                $table_name = substr($expTable[0], 0, -1) . 'ies';
                                break;
                            case 's':
                                $table_name = $expTable[0] . 'es';
                                break;
                            default:
                                $table_name = $expTable[0] . 's';
                                break;
                        }

                        if ($key == count($tables) - 1) {
                            $initial_where .= $expTable[0] . "_id " . "IN (" . "SELECT id FROM $table_name WHERE $column LIKE '%$keyword%')";
                        } else {
                            $initial_where .= (isset($expTable[1]) ? 'id' : substr($table, 0, -1) . "_id ") . " IN (" . "SELECT " . (isset($expTable[1]) ? $expTable[1] : 'id') . " FROM $expTable[0] where ";
                            $closers .= ')';
                        }
                    }
                    $initial_where .= $closers;
                    $query->orWhereRaw($initial_where);
                } else {
                    $query->orWhere($search_column, 'LIKE', "%" . $keyword . "%");
                }
            }
        });

        return $model;
    }

    // =========================>
    // ## Dumping field
    // =========================>
    protected function dump_field(
        $request,  //? request field
        $model     //? eloquent model
    ) {
        foreach ($model->getFillable() as $key_field) {
            isset($request[$key_field]) && $model->setAttribute($key_field, $request[$key_field]);
        }

        return $model;
    }

    // =========================>
    // ## Upload file
    // =========================>
    public function upload_file(
        \Illuminate\Http\UploadedFile $file,  //? file
        string $folder = ''                   //? storage folder name
    ) {
        Log::info('ControllerHelper@upload_file called', [
            'original_name' => $file->getClientOriginalName(),
            'folder' => $folder,
            'size' => $file->getSize(),
            'mime' => $file->getMimeType(),
            'temp_path' => $file->getPathname(),
            'is_valid' => $file->isValid(),
        ]);

        try {
            // Generate a unique filename
            $originalName = $file->getClientOriginalName();
            $extension = $file->getClientOriginalExtension();
            $filename = pathinfo($originalName, PATHINFO_FILENAME) . '_' . time() . '_' . uniqid() . '.' . $extension;

            // Create full path with folder
            $fullPath = $folder ? $folder . '/' . $filename : $filename;

            // Store the file with specific name
            $stored = $file->storeAs($folder, $filename, 'public');

            Log::info('ControllerHelper@upload_file completed', [
                'original_name' => $originalName,
                'folder' => $folder,
                'filename' => $filename,
                'stored_path' => $stored,
                'full_path' => storage_path('app/public/' . $stored),
                'exists' => Storage::disk('public')->exists($stored),
                'is_temp_path' => strpos($stored, 'Temp') !== false || strpos($stored, 'tmp') !== false
            ]);

            // Validate that we didn't get a temporary path
            if (strpos($stored, 'Temp') !== false || strpos($stored, 'tmp') !== false) {
                Log::error('ControllerHelper@upload_file received temporary path', [
                    'stored_path' => $stored,
                    'expected_path' => $fullPath
                ]);
                throw new \Exception('File upload failed: received temporary path instead of storage path');
            }

            return $stored;
        } catch (\Throwable $e) {
            Log::error('ControllerHelper@upload_file failed', [
                'original_name' => $file->getClientOriginalName(),
                'folder' => $folder,
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            throw $e;
        }
    }

    // =========================>
    // ## Delete file
    // =========================>
    public function delete_file(
        string|null $file_name  //? filename
    ) {
        if (Storage::disk('public')->exists($file_name)) {
            Storage::disk('public')->delete($file_name);
        }
    }

    // =========================>
    // ## Response file
    // =========================>
    public function response_file(
        string $file_name  //? filename
    ) {
        $file_path = storage_path('app/public/' . $file_name);

        if (Storage::disk('public')->exists($file_name)) {
            return response()->file($file_path);
        }

        return response(['message' => 'File not found'], 404);
    }


    // =========================>
    // ## Validation
    // =========================>
    public function validation(
        $request, //? http request body
        $rules    //? validator rules
    ) {
        $validate = Validator::make($request, $rules);

        if ($validate->fails()) {
            return response()->json([
                'message' => "Error: Unprocessable Entity!",
                'errors' => $validate->errors(),
            ], 422);
        }
    }
}
