<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table): void {
            $table->string('status', 30)->default('scheduled')->after('comment')->index();
            $table->timestamp('problem_reported_at')->nullable()->after('status');
            $table->string('external_source', 50)->nullable()->after('problem_reported_at');
            $table->string('external_reference', 120)->nullable()->after('external_source');
            $table->json('external_payload')->nullable()->after('external_reference');

            $table->unique(['external_source', 'external_reference'], 'appointments_external_unique');
        });
    }

    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table): void {
            $table->dropUnique('appointments_external_unique');
            $table->dropColumn([
                'status',
                'problem_reported_at',
                'external_source',
                'external_reference',
                'external_payload',
            ]);
        });
    }
};
