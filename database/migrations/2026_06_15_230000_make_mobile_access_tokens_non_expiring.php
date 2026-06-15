<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('mobile_access_tokens')->update([
            'expires_at' => null,
        ]);
    }

    public function down(): void
    {
        // No safe rollback: previous expiration dates cannot be reconstructed.
    }
};
