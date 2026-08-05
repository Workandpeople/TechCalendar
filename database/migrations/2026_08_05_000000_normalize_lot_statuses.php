<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('lots')
            ->where('status', 'a_commencer')
            ->update(['status' => 'en_cours']);

        DB::table('lots')
            ->where('status', 'complet')
            ->update(['status' => 'a_facturer']);

        Schema::table('lots', function (Blueprint $table): void {
            $table->string('status', 40)->default('en_cours')->change();
        });
    }

    public function down(): void
    {
        Schema::table('lots', function (Blueprint $table): void {
            $table->string('status', 40)->default('a_commencer')->change();
        });

        DB::table('lots')
            ->where('status', 'a_facturer')
            ->update(['status' => 'complet']);

        DB::table('lots')
            ->where('status', 'complet_archive')
            ->update(['status' => 'complet']);
    }
};
