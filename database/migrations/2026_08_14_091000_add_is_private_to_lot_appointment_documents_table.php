<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lot_appointment_documents', function (Blueprint $table): void {
            $table->boolean('is_private')->default(false)->after('size');
        });
    }

    public function down(): void
    {
        Schema::table('lot_appointment_documents', function (Blueprint $table): void {
            $table->dropColumn('is_private');
        });
    }
};
