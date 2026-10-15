<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('legacy_records', function (Blueprint $table): void {
            // Null preserves Coffrac permissions without rewriting the source archive.
            $table->boolean('client_visible_override')->nullable();
        });
        Schema::table('dossier_activities', function (Blueprint $table): void {
            $table->timestamp('visibility_reviewed_at')->nullable();
        });
        Schema::table('lot_appointment_documents', function (Blueprint $table): void {
            $table->boolean('client_visible')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('lot_appointment_documents', fn (Blueprint $table) => $table->dropColumn('client_visible'));
        Schema::table('dossier_activities', fn (Blueprint $table) => $table->dropColumn('visibility_reviewed_at'));
        Schema::table('legacy_records', fn (Blueprint $table) => $table->dropColumn('client_visible_override'));
    }
};
