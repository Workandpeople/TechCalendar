<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lot_appointments', function (Blueprint $table): void {
            if (! Schema::hasColumn('lot_appointments', 'global_plus_demand_id')) {
                $table->string('global_plus_demand_id', 120)->nullable()->after('added_to_global_plus');
                $table->index('global_plus_demand_id', 'lot_appointments_global_plus_demand_id_index');
            }

            if (! Schema::hasColumn('lot_appointments', 'global_plus_intervention_id')) {
                $table->string('global_plus_intervention_id', 120)->nullable()->after('global_plus_demand_id');
            }

            if (! Schema::hasColumn('lot_appointments', 'global_plus_status')) {
                $table->string('global_plus_status', 40)->nullable()->after('global_plus_intervention_id');
                $table->index(['lot_id', 'global_plus_status'], 'lot_appointments_lot_global_plus_status_index');
            }

            if (! Schema::hasColumn('lot_appointments', 'global_plus_payload')) {
                $table->json('global_plus_payload')->nullable()->after('global_plus_status');
            }

            if (! Schema::hasColumn('lot_appointments', 'global_plus_created_at')) {
                $table->timestamp('global_plus_created_at')->nullable()->after('global_plus_payload');
            }

            if (! Schema::hasColumn('lot_appointments', 'global_plus_synced_at')) {
                $table->timestamp('global_plus_synced_at')->nullable()->after('global_plus_created_at');
            }

            if (! Schema::hasColumn('lot_appointments', 'global_plus_error_message')) {
                $table->text('global_plus_error_message')->nullable()->after('global_plus_synced_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('lot_appointments', function (Blueprint $table): void {
            if (Schema::hasColumn('lot_appointments', 'global_plus_demand_id')) {
                $table->dropIndex('lot_appointments_global_plus_demand_id_index');
            }

            if (Schema::hasColumn('lot_appointments', 'global_plus_status')) {
                $table->dropIndex('lot_appointments_lot_global_plus_status_index');
            }

            $columns = array_values(array_filter([
                Schema::hasColumn('lot_appointments', 'global_plus_error_message') ? 'global_plus_error_message' : null,
                Schema::hasColumn('lot_appointments', 'global_plus_synced_at') ? 'global_plus_synced_at' : null,
                Schema::hasColumn('lot_appointments', 'global_plus_created_at') ? 'global_plus_created_at' : null,
                Schema::hasColumn('lot_appointments', 'global_plus_payload') ? 'global_plus_payload' : null,
                Schema::hasColumn('lot_appointments', 'global_plus_status') ? 'global_plus_status' : null,
                Schema::hasColumn('lot_appointments', 'global_plus_intervention_id') ? 'global_plus_intervention_id' : null,
                Schema::hasColumn('lot_appointments', 'global_plus_demand_id') ? 'global_plus_demand_id' : null,
            ]));

            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }
};
