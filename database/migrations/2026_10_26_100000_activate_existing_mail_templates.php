<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('mail_templates')->whereNull('deleted_at')->where('is_active', false)->update(['is_active' => true]);
    }

    public function down(): void
    {
        // Activation is a business decision; rollback must not disable templates since edited by staff.
    }
};
