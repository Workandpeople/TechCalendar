<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dossier_operations', function (Blueprint $table): void {
            // Paris wall time, consistently with appointment DATETIME columns.
            $table->dateTime('booking_recall_at')->nullable();
            $table->unsignedBigInteger('booking_recall_activity_id')->nullable();
            $table->index(['booking_recall_at', 'id'], 'dossier_operations_booking_recall_due');
        });
    }

    public function down(): void
    {
        Schema::table('dossier_operations', function (Blueprint $table): void {
            $table->dropIndex('dossier_operations_booking_recall_due');
            $table->dropColumn(['booking_recall_at', 'booking_recall_activity_id']);
        });
    }
};
