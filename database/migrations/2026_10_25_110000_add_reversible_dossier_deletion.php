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
            $table->softDeletes();
            $table->index('deleted_at');
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('deletion_source', 30)->nullable();
        });

        // The operator confirmed that the complete former "unknown" category means deleted.
        // Preserve raw statuses and use no source connection, observers or outgoing side effects.
        $known = ['PRISE DE RDV', 'Problème rendez- vous', 'PROBLÈME RENDEZ-VOUS', 'RDV ATTENTE VISITE',
            'RDV VISITE', 'CONTROLE RT', 'NON CONFORMITES', 'RECONTROLE', 'RAPPORT GENIUS',
            'DOSSIERS FACTURER', 'DOSSIER ANNULE'];
        DB::table('dossiers')->where(function ($query) use ($known): void {
            $query->whereNotExists(fn ($operations) => $operations->selectRaw('1')->from('dossier_operations')
                ->whereColumn('dossier_operations.dossier_id', 'dossiers.id'))
                ->orWhereExists(fn ($operations) => $operations->selectRaw('1')->from('dossier_operations')
                    ->whereColumn('dossier_operations.dossier_id', 'dossiers.id')
                    ->where(fn ($status) => $status->whereNull('legacy_status')->orWhereNotIn('legacy_status', $known)));
        })->update(['deleted_at' => now(), 'deletion_source' => 'legacy_status']);
    }

    public function down(): void
    {
        if (DB::table('dossiers')->whereNotNull('deleted_at')->exists()) {
            throw new RuntimeException('Restauration du schema refusee : dossiers supprimes a conserver.');
        }
        Schema::table('dossiers', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('deleted_by');
            $table->dropIndex(['deleted_at']);
            $table->dropColumn(['deleted_at', 'deletion_source']);
        });
    }
};
