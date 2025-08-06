<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class SuperController extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'make:super-controller {name} {--model=}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Make the Super Controller';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $initial_name = $this->argument('name');
        $model = $this->option('model') ? $this->option('model') : "// model";
        $base_path = 'app/Http/Controllers';

        if ($initial_name === '' || is_null($initial_name) || empty($initial_name)) {
            $this->error('Controller Name Invalid..!');
            return 0;
        }

        $names = explode('/', $initial_name);
        $name = $names[count($names)-1];
        array_pop($names);
        $folder = implode('/', $names);

        if (file_exists("$base_path/$initial_name.php")) {
            $this->error('Controller is exist..!');
            return 0;
        }

        if (!file_exists("$base_path/$folder")) {
            mkdir("$base_path/$folder", 0775, true);
            $this->info("Create folder $base_path/$folder...");
        }

        file_put_contents("$base_path/$initial_name.php", '<?php

namespace App\Http\Controllers\\'.implode('\\', $names).';

use App\Http\Controllers\Controller;
use App\Models\\'.$model.';
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class '.$name.' extends Controller
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
        $model = new '.$model.'();
        $query = '.$model.'::query();

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
        $validation = $this->validation($request->all(), []);

        if ($validation) return $validation;

        // ? Initial
        DB::beginTransaction();
        $model = new '.$model.'();

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
        $model = '.$model.'::findOrFail($id);

        // ? Validate request
        $validation = $this->validation($request->all(), []);

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

    // ===============================================>
    // ## Remove the specified resource from storage.
    // ===============================================>
    public function destroy(string $id)
    {
        // ? Initial
        $model = '.$model.'::findOrFail($id);

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
        ');

    $this->info("Successfully Create Controller $base_path/$initial_name...");
    }
}
