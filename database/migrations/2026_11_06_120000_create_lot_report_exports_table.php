<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lot_report_exports', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('external_client_id')->constrained('external_delegataires')->cascadeOnDelete();
            $table->string('lot_name', 256);
            $table->string('status', 16)->default('queued');
            $table->unsignedInteger('file_count')->default(0);
            $table->unsignedInteger('unavailable_count')->default(0);
            $table->unsignedBigInteger('archive_bytes')->default(0);
            $table->string('failure_message', 512)->nullable();
            $table->timestamp('expires_at')->index();
            $table->timestamps();
            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lot_report_exports');
    }
};
