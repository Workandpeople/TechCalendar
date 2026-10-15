<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dossiers', function (Blueprint $table): void {
            $table->id();
            $table->string('reference', 100)->unique();
            $table->string('source', 40)->default('native');
            $table->string('source_reference', 190)->nullable();
            $table->unique(['source', 'source_reference']);
            $table->foreignId('delegataire_id')->nullable()->constrained('external_delegataires')->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('company_name', 190)->nullable()->index();
            $table->string('first_name', 120)->nullable();
            $table->string('last_name', 120)->nullable()->index();
            $table->string('email', 190)->nullable();
            $table->string('phone', 500)->nullable();
            $table->string('site_name', 190)->nullable();
            $table->string('address', 500);
            $table->string('postal_code', 10)->nullable()->index();
            $table->string('city', 190);
            $table->string('beneficiary_address', 500)->nullable();
            $table->string('beneficiary_postal_code', 10)->nullable();
            $table->string('beneficiary_city', 190)->nullable();
            $table->string('installer_name', 190)->nullable();
            $table->string('installer_siren', 9)->nullable()->index();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->index(['created_at', 'id']);
        });

        Schema::create('dossier_operations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('dossier_id')->constrained()->restrictOnDelete();
            $table->foreignId('service_id')->constrained()->restrictOnDelete();
            $table->foreignId('appointment_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $table->string('source', 40)->default('native');
            $table->string('source_reference', 190)->nullable();
            $table->unique(['source', 'source_reference']);
            $table->timestamps();
            $table->index(['dossier_id', 'service_id']);
        });

        Schema::create('dossier_activities', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('dossier_id')->constrained()->restrictOnDelete();
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('kind', 40);
            $table->text('body');
            $table->json('changed_fields')->nullable();
            $table->timestamps();
            $table->index(['dossier_id', 'id']);
        });

        Schema::create('dossier_documents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('dossier_id')->constrained()->restrictOnDelete();
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name', 190);
            $table->string('path')->unique();
            $table->string('mime_type', 120);
            $table->unsignedBigInteger('size');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dossier_documents');
        Schema::dropIfExists('dossier_activities');
        Schema::dropIfExists('dossier_operations');
        Schema::dropIfExists('dossiers');
    }
};
