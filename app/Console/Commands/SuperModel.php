<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class SuperModel extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'make:super-model {name}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Make the Super Model';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $name = $this->argument('name');
        $base_path = 'app/Models';

        if (file_exists("$base_path/$name.php")) {
            $this->error('Model is exist..!');
            return 0;
        }

        file_put_contents("$base_path/$name.php", '<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class '.$name.' extends Model
{
    use HasFactory;

    // =========================>
    // ## Fillable
    // =========================>
    protected $fillable = [];

    // =========================>
    // ## Hidden
    // =========================>
    protected $hidden = [];

    // =========================>
    // ## Searchable
    // =========================>
    public $searchable = [];

    // =========================>
    // ## Selectable
    // =========================>
    public $selectable = [];
}
');
    }
}
