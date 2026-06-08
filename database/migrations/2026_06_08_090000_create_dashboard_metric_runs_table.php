<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dashboard_metric_runs', function (Blueprint $table): void {
            $table->id();
            $table->string('dashboard', 60);
            $table->date('period_start');
            $table->date('period_end');
            $table->string('status', 20)->default('pending');
            $table->unsignedInteger('total_steps')->default(0);
            $table->unsignedInteger('processed_steps')->default(0);
            $table->json('result')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['dashboard', 'period_start', 'period_end', 'status'], 'dashboard_metric_runs_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dashboard_metric_runs');
    }
};
