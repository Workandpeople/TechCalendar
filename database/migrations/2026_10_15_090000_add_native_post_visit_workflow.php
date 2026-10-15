<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dossier_operations', function (Blueprint $table): void {
            $table->foreignId('visit_appointment_id')->nullable()->constrained('appointments')->restrictOnDelete();
            $table->date('inspected_on')->nullable();
            $table->string('control_outcome', 30)->nullable();
            $table->text('control_comment')->nullable();
            $table->timestamp('control_submitted_at')->nullable();
            $table->timestamp('control_validated_at')->nullable();
            $table->foreignId('control_validated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('report_document_id')->nullable()->constrained('dossier_documents')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('dossier_operations', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('visit_appointment_id');
            $table->dropConstrainedForeignId('control_validated_by');
            $table->dropConstrainedForeignId('report_document_id');
            $table->dropColumn(['inspected_on', 'control_outcome', 'control_comment', 'control_submitted_at', 'control_validated_at']);
        });
    }
};
