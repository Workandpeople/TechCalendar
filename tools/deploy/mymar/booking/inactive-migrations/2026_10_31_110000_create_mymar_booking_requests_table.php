<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mymar_booking_requests', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('author_id')->constrained('users')->restrictOnDelete();
            $table->unsignedBigInteger('source_dossier_id')->index();
            $table->unsignedBigInteger('active_dossier_id')->nullable()->unique();
            $table->foreignId('cofrac_technician_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('reserved_starts_at')->nullable();
            $table->dateTime('reserved_ends_at')->nullable();
            $table->char('fingerprint', 64);
            $table->text('command');
            $table->string('status', 20);
            $table->string('reason', 100)->nullable();
            $table->json('result')->nullable();
            $table->uuid('claim_id')->nullable();
            $table->unsignedBigInteger('lease_until')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'lease_until']);
            $table->index(['cofrac_technician_id', 'status', 'reserved_starts_at'], 'mymar_booking_reservation_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mymar_booking_requests');
    }
};
