<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreatePrestationTable extends Migration
{
    public function up()
    {
        Schema::create('prestation', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->enum('type', ['MAR', 'AUDIT', 'COFRAC']);
            $table->string('name', 100);
            $table->integer('default_time');
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('prestation');
    }
}