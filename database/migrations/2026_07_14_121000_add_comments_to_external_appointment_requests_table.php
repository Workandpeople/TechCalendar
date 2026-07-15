<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('external_appointment_requests', function (Blueprint $table): void {
            $table->json('comments')->nullable()->after('comment');
        });
    }

    public function down(): void
    {
        Schema::table('external_appointment_requests', function (Blueprint $table): void {
            $table->dropColumn('comments');
        });
    }
};
