<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('technician_absences', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('technician_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->string('reason')->nullable();
            $table->timestamps();

            $table->index(['technician_id', 'starts_at', 'ends_at'], 'tech_absences_overlap_idx');
            $table->index(['starts_at', 'ends_at'], 'tech_absences_period_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('technician_absences');
    }
};
