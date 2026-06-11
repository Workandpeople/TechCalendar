<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('technician_daily_route_metrics', function (Blueprint $table): void {
            $table->unsignedInteger('overtime_minutes')
                ->default(0)
                ->after('drive_duration_minutes');
        });
    }

    public function down(): void
    {
        Schema::table('technician_daily_route_metrics', function (Blueprint $table): void {
            $table->dropColumn('overtime_minutes');
        });
    }
};
