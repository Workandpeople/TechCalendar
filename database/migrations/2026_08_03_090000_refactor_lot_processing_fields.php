<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lots', function (Blueprint $table): void {
            $table->boolean('global_plus')->default(false)->after('delegataire');
            $table->decimal('physical_sampling_percentage', 5, 2)->nullable()->after('sampling_percentage');
            $table->decimal('contact_sampling_percentage', 5, 2)->nullable()->after('physical_sampling_percentage');
        });

        Schema::table('lot_import_previews', function (Blueprint $table): void {
            $table->boolean('global_plus')->default(false)->after('delegataire');
            $table->decimal('physical_sampling_percentage', 5, 2)->nullable()->after('sampling_percentage');
            $table->decimal('contact_sampling_percentage', 5, 2)->nullable()->after('physical_sampling_percentage');
        });

        Schema::table('lot_appointments', function (Blueprint $table): void {
            $table->string('processing_mode', 30)->nullable()->after('status')->index();
            $table->boolean('contact_satisfaction')->nullable()->after('processing_mode');
            $table->text('contact_comment')->nullable()->after('contact_satisfaction');
            $table->timestamp('contact_processed_at')->nullable()->after('contact_comment');
            $table->foreignId('contact_processed_by')->nullable()->after('contact_processed_at')->constrained('users')->nullOnDelete();
            $table->boolean('physical_satisfaction')->nullable()->after('contact_processed_by');
            $table->timestamp('physical_satisfaction_synced_at')->nullable()->after('physical_satisfaction');

            $table->index(['lot_id', 'processing_mode']);
            $table->index(['lot_id', 'contact_satisfaction']);
            $table->index(['lot_id', 'physical_satisfaction']);
        });
    }

    public function down(): void
    {
        Schema::table('lot_appointments', function (Blueprint $table): void {
            $table->dropForeign(['contact_processed_by']);
            $table->dropIndex(['processing_mode']);
            $table->dropIndex(['lot_id', 'processing_mode']);
            $table->dropIndex(['lot_id', 'contact_satisfaction']);
            $table->dropIndex(['lot_id', 'physical_satisfaction']);
            $table->dropColumn([
                'processing_mode',
                'contact_satisfaction',
                'contact_comment',
                'contact_processed_at',
                'contact_processed_by',
                'physical_satisfaction',
                'physical_satisfaction_synced_at',
            ]);
        });

        Schema::table('lot_import_previews', function (Blueprint $table): void {
            $table->dropColumn(['global_plus', 'physical_sampling_percentage', 'contact_sampling_percentage']);
        });

        Schema::table('lots', function (Blueprint $table): void {
            $table->dropColumn(['global_plus', 'physical_sampling_percentage', 'contact_sampling_percentage']);
        });
    }
};
