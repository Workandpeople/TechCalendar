<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lots', function (Blueprint $table): void {
            $table->decimal('sampling_percentage', 5, 2)->nullable()->after('type');
        });

        Schema::create('lot_import_previews', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('status', 40)->default('pending')->index();
            $table->unsignedTinyInteger('progress')->default(0);
            $table->string('name')->nullable();
            $table->string('type', 80)->index();
            $table->decimal('sampling_percentage', 5, 2)->nullable();
            $table->string('original_filename');
            $table->string('original_file_disk', 40);
            $table->string('original_file_path');
            $table->unsignedInteger('original_file_size')->nullable();
            $table->string('original_file_mime', 120)->nullable();
            $table->unsignedSmallInteger('total_rows')->default(0);
            $table->unsignedSmallInteger('normalized_rows')->default(0);
            $table->unsignedSmallInteger('rejected_rows')->default(0);
            $table->string('ai_model', 120)->nullable();
            $table->json('payload')->nullable();
            $table->text('error_message')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('confirmed_lot_id')->nullable()->constrained('lots')->nullOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();

            $table->index(['created_by', 'status']);
            $table->index(['created_at', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lot_import_previews');

        Schema::table('lots', function (Blueprint $table): void {
            $table->dropColumn('sampling_percentage');
        });
    }
};
