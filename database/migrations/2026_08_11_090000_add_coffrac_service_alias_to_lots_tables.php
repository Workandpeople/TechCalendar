<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lots', function (Blueprint $table): void {
            $table->foreignId('coffrac_service_alias_id')
                ->nullable()
                ->after('service_id')
                ->constrained('external_service_aliases')
                ->nullOnDelete();
        });

        Schema::table('lot_import_previews', function (Blueprint $table): void {
            $table->foreignId('coffrac_service_alias_id')
                ->nullable()
                ->after('service_id')
                ->constrained('external_service_aliases')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('lot_import_previews', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('coffrac_service_alias_id');
        });

        Schema::table('lots', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('coffrac_service_alias_id');
        });
    }
};
