<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up()
    {
        Schema::create('WAPetGC_Services', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->enum('type', ['MAR', 'AUDIT', 'COFRAC']);
            $table->string('name');
            $table->integer('default_time');
            $table->timestamps();
            $table->softDeletes();
        });
    }


    /**
     * Reverse the migrations.
     */
    public function down()
    {
        Schema::dropIfExists('WAPetGC_Services');
    }
};
