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
        Schema::create('WAPetGC_Appointments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tech_id');
            $table->uuid('service_id');
            $table->string('client_fname');
            $table->string('client_lname');
            $table->string('client_adresse');
            $table->string('client_zip_code');
            $table->string('client_city');
            $table->string('client_phone');
            $table->dateTime('start_at');
            $table->integer('duration');
            $table->dateTime('end_at');
            $table->text('comment')->nullable();
            $table->integer('trajet_time');
            $table->integer('trajet_distance');
            $table->timestamps();
            $table->softDeletes(); // Ajout de la colonne soft delete

            $table->foreign('tech_id')->references('id')->on('WAPetGC_Tech')->onDelete('cascade');
            $table->foreign('service_id')->references('id')->on('WAPetGC_Services')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down()
    {
        Schema::dropIfExists('WAPetGC_Appointments');
    }
};
