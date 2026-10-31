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
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mymar_booking_requests');
    }
};
