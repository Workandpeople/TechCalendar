<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table): void {
            $table->json('global_plus_review')->nullable();
            $table->unsignedInteger('global_plus_review_version')->default(0);
            $table->boolean('global_plus_review_pending')->default(false);
            $table->index(['global_plus_review_pending', 'updated_at', 'id'], 'appointments_global_plus_review_index');
        });
    }

    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table): void {
            $table->dropIndex('appointments_global_plus_review_index');
            $table->dropColumn(['global_plus_review', 'global_plus_review_version', 'global_plus_review_pending']);
        });
    }
};
