<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lot_appointments', function (Blueprint $table): void {
            $table->boolean('excluded_from_lot_stats')->default(false)->after('unsuccessful_visits_count');
            $table->timestamp('excluded_from_lot_stats_at')->nullable()->after('excluded_from_lot_stats');
            $table->foreignId('excluded_from_lot_stats_by')
                ->nullable()
                ->after('excluded_from_lot_stats_at')
                ->constrained('users')
                ->nullOnDelete();

            $table->index(['lot_id', 'excluded_from_lot_stats']);
        });
    }

    public function down(): void
    {
        Schema::table('lot_appointments', function (Blueprint $table): void {
            $table->dropIndex(['lot_id', 'excluded_from_lot_stats']);
            $table->dropForeign(['excluded_from_lot_stats_by']);
            $table->dropColumn([
                'excluded_from_lot_stats',
                'excluded_from_lot_stats_at',
                'excluded_from_lot_stats_by',
            ]);
        });
    }
};
