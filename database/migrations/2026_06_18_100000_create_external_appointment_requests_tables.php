<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('external_api_syncs', function (Blueprint $table): void {
            $table->id();
            $table->string('source', 50)->unique();
            $table->string('state', 30)->default('never_synced')->index();
            $table->string('message')->nullable();
            $table->timestamp('last_started_at')->nullable();
            $table->timestamp('last_finished_at')->nullable();
            $table->timestamp('last_successful_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('external_appointment_requests', function (Blueprint $table): void {
            $table->id();
            $table->string('source', 50);
            $table->string('external_reference', 120);
            $table->string('status', 30)->index();
            $table->string('source_label', 80)->nullable();
            $table->string('remote_status_name')->nullable();
            $table->string('service_type', 80)->nullable();
            $table->string('service_name')->nullable();
            $table->string('customer_first_name')->nullable();
            $table->string('customer_last_name')->nullable();
            $table->string('customer_name')->nullable();
            $table->string('phone', 40)->nullable();
            $table->string('address')->nullable();
            $table->string('address_line')->nullable();
            $table->string('postal_code', 20)->nullable();
            $table->string('city')->nullable();
            $table->string('department_code', 10)->nullable()->index();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->string('technician_email')->nullable()->index();
            $table->timestamp('starts_at')->nullable()->index();
            $table->unsignedInteger('duration_minutes')->nullable();
            $table->text('comment')->nullable();
            $table->json('documents')->nullable();
            $table->json('payload')->nullable();
            $table->foreignId('appointment_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('remote_updated_at')->nullable()->index();
            $table->timestamp('fetched_at')->nullable()->index();
            $table->timestamps();

            $table->unique(['source', 'external_reference'], 'external_appointment_unique');
            $table->index(['source', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('external_appointment_requests');
        Schema::dropIfExists('external_api_syncs');
    }
};
