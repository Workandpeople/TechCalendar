<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table): void {
            // Dossiers allow 500 street characters, plus postal code and city in the appointment label.
            $table->string('address', 1024)->change();
        });
    }

    public function down(): void
    {
        $length = DB::getDriverName() === 'sqlite' ? 'LENGTH' : 'CHAR_LENGTH';
        if (DB::table('appointments')->whereRaw($length.'(address) > 255')->exists()) {
            throw new RuntimeException('Retour refuse : des adresses de rendez-vous depassent 255 caracteres. Aucune adresse tronquee.');
        }
        Schema::table('appointments', function (Blueprint $table): void {
            $table->string('address')->change();
        });
    }
};
