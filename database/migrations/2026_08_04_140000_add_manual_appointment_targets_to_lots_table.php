<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lots', function (Blueprint $table): void {
            $table->unsignedInteger('physical_appointment_target_count')->nullable()->after('contact_sampling_percentage');
            $table->unsignedInteger('contact_appointment_target_count')->nullable()->after('physical_appointment_target_count');
        });
    }

    public function down(): void
    {
        Schema::table('lots', function (Blueprint $table): void {
            $table->dropColumn([
                'physical_appointment_target_count',
                'contact_appointment_target_count',
            ]);
        });
    }
};
