<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lot_appointments', function (Blueprint $table): void {
            $table->string('postal_code', 20)->nullable()->after('address')->index();
            $table->string('city', 120)->nullable()->after('postal_code')->index();
        });
    }

    public function down(): void
    {
        Schema::table('lot_appointments', function (Blueprint $table): void {
            $table->dropIndex(['postal_code']);
            $table->dropIndex(['city']);
            $table->dropColumn(['postal_code', 'city']);
        });
    }
};
