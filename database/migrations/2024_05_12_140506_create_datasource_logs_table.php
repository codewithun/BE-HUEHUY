<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('datasource_logs', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('user_id')->nullable();
            $table->string('datasource', 100)->nullable();
            $table->string('ip', 100)->nullable();
            $table->string('url');
            $table->string('request_method');
            $table->timestamp('request_time')->default(DB::raw('CURRENT_TIMESTAMP'));
            $table->timestamp('finish_time')->nullable();
            $table->float('exec_time')->nullable();
            $table->text('additional_headerparams')->nullable();
            $table->text('additional_bodyparams')->nullable();
            $table->text('additional_queryparams')->nullable();
            $table->text('response_data')->nullable();
            $table->enum('log_type', ['auto', 'manual'])->default('manual');
            $table->enum('log_bound', ['inbound', 'outbound'])->default('inbound');
            $table->string('request_status', 15)->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('datasource_logs');
    }
};
