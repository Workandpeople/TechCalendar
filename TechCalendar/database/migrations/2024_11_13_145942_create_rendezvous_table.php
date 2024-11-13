<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateRendezvousTable extends Migration
{
    public function up()
    {
        Schema::create('rendezvous', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('nom', 100);
            $table->string('prenom', 100);
            $table->string('adresse', 150);
            $table->string('code_postal', 10);
            $table->string('ville', 100);
            $table->string('tel', 20);
            $table->date('date');
            $table->time('start_at');
            $table->string('prestation');
            $table->integer('duree')->nullable();
            $table->text('commentaire')->nullable();
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('rendezvous');
    }
}