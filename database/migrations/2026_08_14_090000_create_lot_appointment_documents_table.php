<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lot_appointment_documents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('lot_appointment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('appointment_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name');
            $table->string('original_name');
            $table->string('disk')->default('local');
            $table->string('path');
            $table->string('mime')->nullable();
            $table->unsignedBigInteger('size')->default(0);
            $table->string('status', 40)->default('pending');
            $table->timestamp('pushed_at')->nullable();
            $table->json('remote_document')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index(['lot_appointment_id', 'status']);
            $table->index(['appointment_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lot_appointment_documents');
    }
};
