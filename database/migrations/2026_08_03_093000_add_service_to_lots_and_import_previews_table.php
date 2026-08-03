<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lots', function (Blueprint $table): void {
            $table->foreignId('service_id')
                ->nullable()
                ->after('type')
                ->constrained()
                ->nullOnDelete();
        });

        Schema::table('lot_import_previews', function (Blueprint $table): void {
            $table->foreignId('service_id')
                ->nullable()
                ->after('type')
                ->constrained()
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('lot_import_previews', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('service_id');
        });

        Schema::table('lots', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('service_id');
        });
    }
};
