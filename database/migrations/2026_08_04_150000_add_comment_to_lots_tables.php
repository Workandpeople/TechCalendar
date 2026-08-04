<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lots', function (Blueprint $table): void {
            $table->text('comment')->nullable()->after('received_at');
        });

        Schema::table('lot_import_previews', function (Blueprint $table): void {
            $table->text('comment')->nullable()->after('received_at');
        });
    }

    public function down(): void
    {
        Schema::table('lot_import_previews', function (Blueprint $table): void {
            $table->dropColumn('comment');
        });

        Schema::table('lots', function (Blueprint $table): void {
            $table->dropColumn('comment');
        });
    }
};
