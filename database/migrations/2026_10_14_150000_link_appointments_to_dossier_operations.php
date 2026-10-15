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
            $table->foreignId('dossier_operation_id')->nullable()->constrained()->restrictOnDelete();
        });

        // Preserve any links created with the first V2 schema, without model events.
        DB::table('dossier_operations')->whereNotNull('appointment_id')->orderBy('id')
            ->chunkById(500, function ($operations): void {
                foreach ($operations as $operation) {
                    DB::table('appointments')->where('id', $operation->appointment_id)
                        ->update(['dossier_operation_id' => $operation->id]);
                }
            });

        Schema::table('dossier_operations', function (Blueprint $table): void {
            $table->dropForeign(['appointment_id']);
            $table->dropUnique(['appointment_id']);
            $table->dropColumn('appointment_id');
        });
    }

    public function down(): void
    {
        if (DB::table('appointments')->whereNotNull('dossier_operation_id')
            ->groupBy('dossier_operation_id')->havingRaw('COUNT(*) > 1')->exists()) {
            throw new RuntimeException('Retour arrière refusé : plusieurs RDV sont liés à une prestation. Aucune liaison ne doit être perdue.');
        }

        Schema::table('dossier_operations', function (Blueprint $table): void {
            $table->foreignId('appointment_id')->nullable()->unique()->constrained()->restrictOnDelete();
        });
        DB::table('appointments')->whereNotNull('dossier_operation_id')->orderBy('id')
            ->chunkById(500, function ($appointments): void {
                foreach ($appointments as $appointment) {
                    DB::table('dossier_operations')->where('id', $appointment->dossier_operation_id)
                        ->update(['appointment_id' => $appointment->id]);
                }
            });
        Schema::table('appointments', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('dossier_operation_id');
        });
    }
};
