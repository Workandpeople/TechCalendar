<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dossier_activities', function (Blueprint $table): void {
            $table->index(['dossier_id', 'kind', 'created_at', 'id'], 'dossier_activities_comment_preview_index');
        });
    }

    public function down(): void
    {
        Schema::table('dossier_activities', function (Blueprint $table): void {
            $table->dropIndex('dossier_activities_comment_preview_index');
        });
    }
};
