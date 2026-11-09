<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('technician_incidents', function (Blueprint $table): void {
            $table->id();
            $table->uuid('request_id')->unique();
            $table->foreignId('dossier_id')->constrained()->restrictOnDelete();
            $table->foreignId('appointment_id')->constrained()->restrictOnDelete();
            $table->foreignId('technician_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('document_id')->constrained('dossier_documents')->restrictOnDelete();
            $table->string('reason', 32);
            $table->text('comment')->nullable();
            $table->dateTime('appointment_starts_at')->nullable();
            $table->dateTime('reported_at');
            $table->string('planning_status', 16)->default('pending');
            $table->text('planning_note')->nullable();
            $table->foreignId('handled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('handled_at')->nullable();
            $table->string('billing_status', 16)->default('review');
            $table->text('billing_note')->nullable();
            $table->foreignId('billing_reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('billing_reviewed_at')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->index(['planning_status', 'reported_at', 'id'], 'tech_incidents_planning');
            $table->index(['billing_status', 'reported_at', 'id'], 'tech_incidents_billing');
            $table->index(['dossier_id', 'billing_status'], 'tech_incidents_dossier_billing');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('technician_incidents');
    }
};
