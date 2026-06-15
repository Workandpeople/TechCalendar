<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('system_test_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('triggered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('suite', 20)->default('all');
            $table->string('status', 20)->index();
            $table->json('command')->nullable();
            $table->unsignedSmallInteger('exit_code')->nullable();
            $table->longText('output')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('started_at')->nullable()->index();
            $table->timestamp('finished_at')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('system_test_runs');
    }
};
