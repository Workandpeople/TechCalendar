<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dossiers', fn (Blueprint $table) => $table->json('inspection_geocode')->nullable());
    }

    public function down(): void
    {
        Schema::table('dossiers', fn (Blueprint $table) => $table->dropColumn('inspection_geocode'));
    }
};
