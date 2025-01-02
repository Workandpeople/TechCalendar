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
        Schema::create('WAPetGC_Tech', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('user_id');
            $table->string('phone');
            $table->string('adresse');
            $table->string('zip_code');
            $table->string('city');
            $table->time('default_start_at');
            $table->time('default_end_at');
            $table->integer('default_rest_time');
            $table->timestamps();
            $table->softDeletes();
        
            $table->foreign('user_id')->references('id')->on('WAPetGC_Users')->onDelete('cascade');
        });
    }


    /**
     * Reverse the migrations.
     */
    public function down()
    {
        Schema::dropIfExists('WAPetGC_Tech');
    }
};
