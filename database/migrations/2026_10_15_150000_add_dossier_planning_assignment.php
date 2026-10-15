<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dossiers', function (Blueprint $table): void {
            $table->foreignId('planning_user_id')->nullable()->index()->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('dossiers', fn (Blueprint $table) => $table->dropConstrainedForeignId('planning_user_id'));
    }
};
