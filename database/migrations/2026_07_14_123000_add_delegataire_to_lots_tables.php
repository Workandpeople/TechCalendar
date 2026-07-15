<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lots', function (Blueprint $table): void {
            $table->string('delegataire')->nullable()->after('source')->index();
        });

        Schema::table('lot_import_previews', function (Blueprint $table): void {
            $table->string('delegataire')->nullable()->after('sampling_percentage');
        });
    }

    public function down(): void
    {
        Schema::table('lot_import_previews', function (Blueprint $table): void {
            $table->dropColumn('delegataire');
        });

        Schema::table('lots', function (Blueprint $table): void {
            $table->dropIndex(['delegataire']);
            $table->dropColumn('delegataire');
        });
    }
};
