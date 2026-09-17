<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lot_appointments', function (Blueprint $table): void {
            $table->string('internal_reference')->nullable();
            $table->string('customer_email')->nullable();
            $table->string('installer_siren', 20)->nullable();
            $table->string('beneficiary_address', 500)->nullable();
            $table->string('beneficiary_postal_code', 20)->nullable();
            $table->string('beneficiary_city', 120)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('lot_appointments', function (Blueprint $table): void {
            $table->dropColumn(['internal_reference', 'customer_email', 'installer_siren', 'beneficiary_address', 'beneficiary_postal_code', 'beneficiary_city']);
        });
    }
};
