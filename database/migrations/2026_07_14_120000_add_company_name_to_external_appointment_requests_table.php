<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('external_appointment_requests', function (Blueprint $table): void {
            $table->string('company_name')->nullable()->after('customer_name');
        });
    }

    public function down(): void
    {
        Schema::table('external_appointment_requests', function (Blueprint $table): void {
            $table->dropColumn('company_name');
        });
    }
};
