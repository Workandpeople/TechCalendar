<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('phone', 30)->nullable()->after('admin');
            $table->string('address')->nullable()->after('phone');
            $table->decimal('latitude', 10, 7)->nullable()->after('address');
            $table->decimal('longitude', 10, 7)->nullable()->after('latitude');
            $table->time('day_start_time')->nullable()->after('longitude');
            $table->time('day_end_time')->nullable()->after('day_start_time');
            $table->unsignedSmallInteger('break_duration_minutes')->nullable()->after('day_end_time');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn([
                'phone',
                'address',
                'latitude',
                'longitude',
                'day_start_time',
                'day_end_time',
                'break_duration_minutes',
            ]);
        });
    }
};
