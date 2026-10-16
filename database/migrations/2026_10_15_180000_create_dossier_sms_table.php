<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dossier_sms', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('dossier_id')->constrained()->cascadeOnDelete();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('appointment_id')->nullable()->constrained()->nullOnDelete();
            $table->uuid('submission_token')->unique();
            $table->string('fingerprint', 64);
            $table->text('recipient');
            $table->text('body');
            $table->string('sender', 11)->default('');
            $table->string('mode', 16);
            $table->string('status', 24)->default('queued');
            $table->string('provider_ticket', 100)->nullable();
            $table->integer('provider_status')->nullable();
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->string('last_error', 500)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
            $table->index(['dossier_id', 'id']);
            $table->index(['dossier_id', 'fingerprint', 'created_at'], 'dossier_sms_duplicate_lookup');
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dossier_sms');
    }
};
