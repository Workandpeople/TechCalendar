<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddTrajectColumnsToRendezvousTable extends Migration
{
    public function up()
    {
        Schema::table('rendezvous', function (Blueprint $table) {
            $table->integer('traject_time')->nullable()->after('commentaire'); // Temps de trajet en minutes
            $table->float('traject_distance', 8, 2)->nullable()->after('traject_time'); // Distance de trajet en km
        });
    }

    public function down()
    {
        Schema::table('rendezvous', function (Blueprint $table) {
            $table->dropColumn(['traject_time', 'traject_distance']);
        });
    }
}