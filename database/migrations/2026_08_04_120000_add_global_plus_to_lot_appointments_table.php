<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('lot_appointments', 'added_to_global_plus')) {
            Schema::table('lot_appointments', function (Blueprint $table): void {
                $table->boolean('added_to_global_plus')->default(false)->after('excluded_from_lot_stats');
                $table->index(['lot_id', 'added_to_global_plus']);
            });
        }

        if (Schema::hasColumn('lots', 'global_plus')) {
            $globalPlusLotIds = DB::table('lots')
                ->where('global_plus', true)
                ->pluck('id');

            if ($globalPlusLotIds->isNotEmpty()) {
                DB::table('lot_appointments')
                    ->whereIn('lot_id', $globalPlusLotIds->all())
                    ->update(['added_to_global_plus' => true]);
            }
        }
    }

    public function down(): void
    {
        if (! Schema::hasColumn('lot_appointments', 'added_to_global_plus')) {
            return;
        }

        Schema::table('lot_appointments', function (Blueprint $table): void {
            $table->dropIndex(['lot_id', 'added_to_global_plus']);
            $table->dropColumn('added_to_global_plus');
        });
    }
};
