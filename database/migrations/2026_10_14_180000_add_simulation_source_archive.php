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
            $table->decimal('latitude', 10, 7)->nullable()->change();
            $table->decimal('longitude', 10, 7)->nullable()->change();
        });
        Schema::create('simulation_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->string('fingerprint', 64)->unique();
            $table->json('report');
            $table->timestamp('created_at');
        });
        Schema::create('legacy_records', function (Blueprint $table): void {
            $table->id();
            $table->string('source_table', 50);
            $table->unsignedBigInteger('source_id');
            $table->unsignedBigInteger('dossier_id')->nullable();
            $table->longText('payload');
            $table->unique(['source_table', 'source_id']);
            $table->index(['dossier_id', 'source_table', 'source_id']);
        });
        Schema::table('dossiers', function (Blueprint $table): void {
            $table->string('legacy_label', 256)->nullable();
            $table->string('legacy_reference', 256)->nullable()->index();
            $table->string('legacy_lot_name', 256)->nullable();
        });
        Schema::table('dossier_operations', function (Blueprint $table): void {
            $table->unsignedBigInteger('service_id')->nullable()->change();
            $table->string('legacy_service_name', 256)->nullable();
            $table->string('legacy_status', 256)->nullable();
        });
    }

    public function down(): void
    {
        if (DB::table('appointments')->whereNull('latitude')->orWhereNull('longitude')->exists()) {
            throw new RuntimeException('Des RDV sans coordonnees existent. Retour arriere refuse.');
        }
        if (DB::table('dossier_operations')->whereNull('service_id')->exists()) {
            throw new RuntimeException('Des prestations historiques restent a rapprocher. Retour arriere refuse.');
        }
        Schema::table('dossier_operations', function (Blueprint $table): void {
            $table->unsignedBigInteger('service_id')->nullable(false)->change();
            $table->dropColumn(['legacy_service_name', 'legacy_status']);
        });
        Schema::table('dossiers', fn (Blueprint $table) => $table->dropColumn(['legacy_label', 'legacy_reference', 'legacy_lot_name']));
        Schema::dropIfExists('legacy_records');
        Schema::dropIfExists('simulation_snapshots');
        Schema::table('appointments', function (Blueprint $table): void {
            $table->decimal('latitude', 10, 7)->nullable(false)->change();
            $table->decimal('longitude', 10, 7)->nullable(false)->change();
        });
    }
};
