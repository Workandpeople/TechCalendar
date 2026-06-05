<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('system_health_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->string('overall_status', 20)->index();
            $table->unsignedTinyInteger('score')->default(100);
            $table->json('summary')->nullable();
            $table->timestamp('checked_at')->index();
            $table->timestamps();
        });

        Schema::create('system_health_checks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('system_health_snapshot_id')->constrained()->cascadeOnDelete();
            $table->string('name', 80)->index();
            $table->string('label');
            $table->string('status', 20)->index();
            $table->string('value')->nullable();
            $table->text('message')->nullable();
            $table->json('meta')->nullable();
            $table->unsignedInteger('duration_ms')->default(0);
            $table->timestamp('checked_at')->index();
            $table->timestamps();
        });

        Schema::create('system_error_events', function (Blueprint $table): void {
            $table->id();
            $table->string('source', 80)->index();
            $table->string('severity', 20)->index();
            $table->char('fingerprint', 64)->unique();
            $table->text('message');
            $table->json('context')->nullable();
            $table->timestamp('occurred_at')->nullable()->index();
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable()->index();
            $table->unsignedInteger('occurrences')->default(1);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('system_error_events');
        Schema::dropIfExists('system_health_checks');
        Schema::dropIfExists('system_health_snapshots');
    }
};
