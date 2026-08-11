<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lot_appointments', function (Blueprint $table): void {
            $table->string('installer_name')->nullable()->after('site_name')->index();
        });
    }

    public function down(): void
    {
        Schema::table('lot_appointments', function (Blueprint $table): void {
            $table->dropIndex(['installer_name']);
            $table->dropColumn('installer_name');
        });
    }
};
