<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coffrac_import_runs', function (Blueprint $table): void {
            $table->id();
            $table->string('fingerprint', 64)->unique();
            $table->string('dump_sha256', 64);
            $table->longText('report');
            $table->timestamp('completed_at');
        });
        Schema::create('coffrac_user_links', function (Blueprint $table): void {
            $table->unsignedBigInteger('source_user_id')->primary();
            $table->foreignId('user_id')->unique()->constrained('users')->restrictOnDelete();
            $table->unsignedTinyInteger('source_type');
            $table->string('access_state', 30);
        });
    }

    public function down(): void
    {
        if (DB::table('coffrac_import_runs')->exists() || DB::table('coffrac_user_links')->exists()) {
            throw new RuntimeException('Retour arriere refuse : correspondances et historique d import presents.');
        }
        Schema::dropIfExists('coffrac_user_links');
        Schema::dropIfExists('coffrac_import_runs');
    }
};
