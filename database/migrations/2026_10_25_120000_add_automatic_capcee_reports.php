<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('external_delegataires', fn (Blueprint $table) => $table->boolean('capcee_enabled')->default(false));
        Schema::table('dossier_documents', function (Blueprint $table): void {
            $table->string('capcee_emmy_reference', 255)->nullable();
            $table->string('capcee_object_type', 30)->nullable();
            $table->string('capcee_inspection_type', 20)->nullable();
        });
        Schema::create('capcee_automatic_submissions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('dossier_document_id')->unique()->constrained('dossier_documents')->restrictOnDelete();
            $table->foreignId('delegataire_id')->constrained('external_delegataires')->restrictOnDelete();
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('capcee_delivery_id')->nullable()->constrained('capcee_deliveries')->restrictOnDelete();
            $table->uuid('submission_token')->unique();
            $table->string('environment', 20);
            $table->string('block_reason', 60)->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->index(['capcee_delivery_id', 'id']);
        });
    }

    public function down(): void
    {
        if (DB::table('capcee_automatic_submissions')->exists()) {
            throw new RuntimeException('Retour arriere refuse : suivi de transmissions CAPCEE a conserver.');
        }
        Schema::dropIfExists('capcee_automatic_submissions');
        Schema::table('dossier_documents', fn (Blueprint $table) => $table->dropColumn(['capcee_emmy_reference', 'capcee_object_type', 'capcee_inspection_type']));
        Schema::table('external_delegataires', fn (Blueprint $table) => $table->dropColumn('capcee_enabled'));
    }
};
