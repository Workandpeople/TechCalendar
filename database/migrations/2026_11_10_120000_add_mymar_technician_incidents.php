<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('technician_incidents', function (Blueprint $table): void {
            $table->unsignedBigInteger('dossier_id')->nullable()->change();
            $table->unsignedBigInteger('appointment_id')->nullable()->change();
            $table->unsignedBigInteger('document_id')->nullable()->change();
            $table->string('source', 16)->default('cofrac');
            $table->unsignedBigInteger('mymar_dossier_id')->nullable()->index();
            $table->unsignedBigInteger('mymar_rdv_id')->nullable()->unique();
            $table->json('source_context')->nullable();
        });
        Schema::create('technician_incident_reviews', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('incident_id')->constrained('technician_incidents')->restrictOnDelete();
            $table->foreignId('author_id')->constrained('users')->restrictOnDelete();
            $table->string('section', 16);
            $table->json('before');
            $table->json('after');
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        if (DB::table('technician_incidents')->where('source', 'mymar')->exists()) {
            throw new RuntimeException('Retour du schema refuse : signalements MAR a conserver.');
        }
        Schema::dropIfExists('technician_incident_reviews');
        Schema::table('technician_incidents', function (Blueprint $table): void {
            $table->unsignedBigInteger('dossier_id')->nullable(false)->change();
            $table->unsignedBigInteger('appointment_id')->nullable(false)->change();
            $table->unsignedBigInteger('document_id')->nullable(false)->change();
            $table->dropIndex(['mymar_dossier_id']);
            $table->dropUnique(['mymar_rdv_id']);
            $table->dropColumn(['source', 'mymar_dossier_id', 'mymar_rdv_id', 'source_context']);
        });
    }
};
