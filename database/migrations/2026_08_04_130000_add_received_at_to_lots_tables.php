<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lots', function (Blueprint $table): void {
            $table->date('received_at')->nullable()->after('delegataire')->index();
        });

        Schema::table('lot_import_previews', function (Blueprint $table): void {
            $table->date('received_at')->nullable()->after('delegataire');
        });
    }

    public function down(): void
    {
        Schema::table('lot_import_previews', function (Blueprint $table): void {
            $table->dropColumn('received_at');
        });

        Schema::table('lots', function (Blueprint $table): void {
            $table->dropIndex(['received_at']);
            $table->dropColumn('received_at');
        });
    }
};
