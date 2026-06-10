<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lots', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('source', 120)->nullable()->index();
            $table->string('original_filename')->nullable();
            $table->string('import_status', 40)->default('completed')->index();
            $table->unsignedSmallInteger('total_rows')->default(0);
            $table->unsignedSmallInteger('imported_rows')->default(0);
            $table->unsignedSmallInteger('rejected_rows')->default(0);
            $table->string('ai_model', 120)->nullable();
            $table->json('import_summary')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('imported_at')->nullable()->index();
            $table->timestamps();

            $table->index(['created_at', 'source']);
        });

        Schema::create('lot_appointments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('lot_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('appointment_id')->nullable()->constrained()->nullOnDelete();
            $table->string('external_reference')->nullable()->index();
            $table->unsignedSmallInteger('row_number')->nullable();
            $table->string('source', 120)->nullable()->index();
            $table->string('customer_name');
            $table->string('customer_first_name', 120)->nullable();
            $table->string('customer_last_name', 120)->nullable();
            $table->string('customer_phone', 30)->nullable();
            $table->string('address')->nullable();
            $table->string('department_code', 3)->nullable()->index();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->string('service_type', 40)->nullable()->index();
            $table->string('service_name')->nullable();
            $table->unsignedSmallInteger('duration_minutes')->nullable();
            $table->string('status', 40)->default('pending')->index();
            $table->decimal('ai_confidence', 4, 3)->nullable();
            $table->json('ai_warnings')->nullable();
            $table->json('raw_payload')->nullable();
            $table->text('comment')->nullable();
            $table->timestamps();

            $table->index(['lot_id', 'status']);
            $table->index(['lot_id', 'department_code']);
            $table->index(['lot_id', 'service_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lot_appointments');
        Schema::dropIfExists('lots');
    }
};
