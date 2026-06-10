<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lots', function (Blueprint $table): void {
            $table->string('original_file_disk', 40)->nullable()->after('original_filename');
            $table->string('original_file_path')->nullable()->after('original_file_disk');
            $table->unsignedInteger('original_file_size')->nullable()->after('original_file_path');
            $table->string('original_file_mime', 120)->nullable()->after('original_file_size');
        });
    }

    public function down(): void
    {
        Schema::table('lots', function (Blueprint $table): void {
            $table->dropColumn([
                'original_file_disk',
                'original_file_path',
                'original_file_size',
                'original_file_mime',
            ]);
        });
    }
};
