<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table): void {
            $table->string('customer_phone', 255)->change();
        });

        Schema::table('external_appointment_requests', function (Blueprint $table): void {
            $table->string('phone', 255)->nullable()->change();
        });

        Schema::table('lot_appointments', function (Blueprint $table): void {
            $table->string('customer_phone', 255)->nullable()->change();
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->string('phone', 255)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table): void {
            $table->string('customer_phone', 30)->change();
        });

        Schema::table('external_appointment_requests', function (Blueprint $table): void {
            $table->string('phone', 40)->nullable()->change();
        });

        Schema::table('lot_appointments', function (Blueprint $table): void {
            $table->string('customer_phone', 30)->nullable()->change();
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->string('phone', 30)->nullable()->change();
        });
    }
};
