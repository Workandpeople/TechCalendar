<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('external_api_syncs')) {
            return;
        }

        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE external_api_syncs MODIFY message TEXT NULL');
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('external_api_syncs')) {
            return;
        }

        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE external_api_syncs MODIFY message VARCHAR(255) NULL');
        }
    }
};
