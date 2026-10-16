<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dossiers', function (Blueprint $table): void {
            $table->foreignId('client_id')->nullable()->constrained('external_delegataires')->restrictOnDelete();
            $table->json('client_contact')->nullable();
            $table->json('delegataire_contact')->nullable();
        });
        // Move only an explicitly identified client, never infer ownership from a name/email.
        DB::table('dossiers')->whereIn('delegataire_id', DB::table('external_delegataires')->where('kind', 'client')->select('id'))
            ->update(['client_id' => DB::raw('delegataire_id'), 'delegataire_id' => null]);

        foreach (['dossier_documents', 'dossier_activities', 'lot_appointment_documents'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->boolean('delegataire_visible')->default(false));
            // Preserve explicitly shared content; private documents remain private.
            DB::table($name)->where('client_visible', true)->update(['delegataire_visible' => true]);
        }
        Schema::table('legacy_records', function (Blueprint $table): void {
            $table->boolean('delegataire_visible_override')->nullable();
            $table->timestamp('hidden_at')->nullable();
        });
        DB::table('legacy_records')->whereNotNull('client_visible_override')
            ->update(['delegataire_visible_override' => DB::raw('client_visible_override')]);
        Schema::table('dossier_activities', fn (Blueprint $table) => $table->timestamp('hidden_at')->nullable());
        Schema::table('dossier_documents', function (Blueprint $table): void {
            $table->text('description')->nullable();
            $table->string('document_type', 20)->default('free');
            $table->foreignId('dossier_operation_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('control_reference', 190)->nullable();
            $table->date('inspected_on')->nullable();
            $table->date('issued_on')->nullable();
            $table->string('control_outcome', 30)->nullable();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        // Reversing this migration would discard distinct owners and sharing restrictions.
        throw new RuntimeException('Migration non reversible automatiquement : restaurer une sauvegarde pour revenir au modele precedent.');
    }
};
