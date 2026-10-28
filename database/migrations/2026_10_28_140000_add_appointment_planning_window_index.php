<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appointments', fn (Blueprint $table) => $table->index(
            ['ends_at', 'starts_at', 'technician_id'], 'appointments_planning_window_index'
        ));
    }

    public function down(): void
    {
        Schema::table('appointments', fn (Blueprint $table) => $table->dropIndex('appointments_planning_window_index'));
    }
};
