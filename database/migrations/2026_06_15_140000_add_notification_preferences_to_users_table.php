<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('notification_mail_enabled')->default(true)->after('must_change_password');
            $table->boolean('notification_push_enabled')->default(true)->after('notification_mail_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['notification_mail_enabled', 'notification_push_enabled']);
        });
    }
};
