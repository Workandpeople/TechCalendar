<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lot_appointments', function (Blueprint $table): void {
            $table->string('company_name')->nullable()->after('customer_name')->index();
            $table->string('site_name')->nullable()->after('company_name')->index();
        });
    }

    public function down(): void
    {
        Schema::table('lot_appointments', function (Blueprint $table): void {
            $table->dropIndex(['company_name']);
            $table->dropIndex(['site_name']);
            $table->dropColumn(['company_name', 'site_name']);
        });
    }
};
