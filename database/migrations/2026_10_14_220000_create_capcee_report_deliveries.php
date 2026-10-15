<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('capcee_reports', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('dossier_operation_id')->constrained()->restrictOnDelete();
            $table->string('environment', 20);
            $table->string('reference', 97)->unique();
            $table->string('emmy_reference', 255);
            $table->string('object_type', 30);
            $table->string('inspection_type', 10);
            $table->string('parent_id', 18)->nullable();
            $table->string('document_id', 18)->nullable();
            $table->timestamps();
            $table->unique(['dossier_operation_id', 'environment', 'inspection_type'], 'capcee_report_operation_unique');
        });

        Schema::create('capcee_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('capcee_report_id')->constrained()->restrictOnDelete();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->uuid('submission_token')->unique();
            $table->unsignedInteger('revision');
            $table->json('metadata');
            $table->string('path')->unique();
            $table->unsignedBigInteger('size');
            $table->char('sha256', 64);
            $table->string('status', 20)->default('queued');
            $table->string('stage', 20)->default('resolve');
            $table->unsignedInteger('attempts')->default(0);
            $table->string('version_id', 18)->nullable();
            $table->string('document_id', 18)->nullable();
            $table->string('link_id', 18)->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
            $table->unique(['capcee_report_id', 'revision']);
            $table->index(['status', 'next_attempt_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('capcee_deliveries');
        Schema::dropIfExists('capcee_reports');
    }
};
