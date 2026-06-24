<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('external_service_aliases', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('service_id')->constrained()->cascadeOnDelete();
            $table->string('source', 50);
            $table->string('external_type', 80)->nullable();
            $table->string('external_name');
            $table->string('normalized_external_type', 80);
            $table->string('normalized_external_name');
            $table->timestamps();

            $table->unique(
                ['source', 'normalized_external_type', 'normalized_external_name'],
                'external_service_alias_unique'
            );
            $table->index(['service_id', 'source']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('external_service_aliases');
    }
};
