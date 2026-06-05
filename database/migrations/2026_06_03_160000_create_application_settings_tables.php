<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('application_settings', function (Blueprint $table): void {
            $table->id();
            $table->string('key')->unique();
            $table->string('group', 80)->index();
            $table->string('label');
            $table->string('type', 40)->default('string');
            $table->text('value')->nullable();
            $table->boolean('is_secret')->default(false);
            $table->boolean('is_active')->default(true)->index();
            $table->text('description')->nullable();
            $table->json('validation_rules')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('application_setting_audits', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('application_setting_id')->nullable()->constrained()->nullOnDelete();
            $table->string('key')->index();
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('had_value_before')->default(false);
            $table->boolean('has_value_after')->default(false);
            $table->timestamp('changed_at')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('application_setting_audits');
        Schema::dropIfExists('application_settings');
    }
};
