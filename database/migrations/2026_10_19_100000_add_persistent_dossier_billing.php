<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dossier_billings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('dossier_id')->index()->constrained()->restrictOnDelete();
            $table->foreignId('dossier_operation_id')->unique()->constrained()->restrictOnDelete();
            $table->dateTime('marked_at')->nullable();
            $table->foreignId('marked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('invoice_reference', 190)->nullable();
            $table->string('source', 40);
            $table->string('source_reference', 190)->nullable();
            $table->json('evidence')->nullable();
            $table->timestamps();
        });
        Schema::table('dossiers', function (Blueprint $table): void {
            $table->boolean('billing_review_required')->default(false);
            $table->json('billing_review_evidence')->nullable();
        });
    }

    public function down(): void
    {
        if (DB::table('dossier_billings')->exists()
            || DB::table('dossiers')->where('billing_review_required', true)->orWhereNotNull('billing_review_evidence')->exists()) {
            throw new RuntimeException('Retour arriere refuse : historique de facturation ou preuves de revue presents.');
        }

        Schema::drop('dossier_billings');
        Schema::table('dossiers', fn (Blueprint $table) => $table->dropColumn(['billing_review_required', 'billing_review_evidence']));
    }
};
