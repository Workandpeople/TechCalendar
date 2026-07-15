<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('external_delegataires', function (Blueprint $table): void {
            $table->id();
            $table->string('source', 80)->index();
            $table->string('external_id', 120);
            $table->string('name');
            $table->string('company_name')->nullable();
            $table->string('email')->nullable();
            $table->string('phone', 60)->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->json('payload')->nullable();
            $table->timestamp('last_synced_at')->nullable()->index();
            $table->timestamps();

            $table->unique(['source', 'external_id']);
            $table->index(['source', 'is_active', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('external_delegataires');
    }
};
