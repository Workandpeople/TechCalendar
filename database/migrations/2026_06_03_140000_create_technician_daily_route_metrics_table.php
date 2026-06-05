<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('technician_daily_route_metrics', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('technician_id')->constrained('users')->cascadeOnDelete();
            $table->date('service_date');
            $table->unsignedSmallInteger('appointment_count')->default(0);
            $table->decimal('drive_distance_km', 10, 2)->default(0);
            $table->unsignedInteger('drive_duration_minutes')->default(0);
            $table->string('calculation_source', 20)->default('haversine');
            $table->char('route_hash', 64);
            $table->json('route_points')->nullable();
            $table->timestamp('calculated_at')->nullable();
            $table->timestamps();

            $table->unique(['technician_id', 'service_date'], 'tech_route_metrics_unique_day');
            $table->index('service_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('technician_daily_route_metrics');
    }
};
