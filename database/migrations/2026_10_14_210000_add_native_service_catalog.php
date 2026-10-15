<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->unsignedBigInteger('coffrac_reference')->nullable()->unique();
            $table->timestamp('retired_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->dropUnique(['coffrac_reference']);
            $table->dropColumn(['coffrac_reference', 'retired_at']);
        });
    }
};
