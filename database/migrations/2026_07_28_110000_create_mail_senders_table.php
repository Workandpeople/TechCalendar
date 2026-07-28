<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mail_senders', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('mail_host');
            $table->unsignedSmallInteger('mail_port')->default(587);
            $table->string('mail_username')->nullable();
            $table->text('mail_password')->nullable();
            $table->string('mail_encryption', 20)->nullable();
            $table->string('mail_from_address');
            $table->string('mail_from_name');
            $table->string('mail_admin_email')->nullable();
            $table->string('logo_path')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['deleted_at', 'name']);
        });

        Schema::table('mail_templates', function (Blueprint $table): void {
            $table->foreignId('mail_sender_id')
                ->nullable()
                ->after('slug')
                ->constrained('mail_senders')
                ->nullOnDelete();
        });

        $defaultSenderId = DB::table('mail_senders')->insertGetId([
            'name' => 'Expéditeur par défaut',
            'mail_host' => (string) config('mail.mailers.smtp.host', '127.0.0.1'),
            'mail_port' => (int) config('mail.mailers.smtp.port', 587),
            'mail_username' => config('mail.mailers.smtp.username'),
            'mail_password' => config('mail.mailers.smtp.password') !== null
                ? encrypt((string) config('mail.mailers.smtp.password'))
                : null,
            'mail_encryption' => config('mail.mailers.smtp.encryption') ?: 'tls',
            'mail_from_address' => (string) config('mail.from.address', 'hello@example.com'),
            'mail_from_name' => (string) config('mail.from.name', config('app.name', 'Tech Calendar')),
            'mail_admin_email' => env('MAIL_ADMIN_EMAIL'),
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('mail_templates')->whereNull('mail_sender_id')->update([
            'mail_sender_id' => $defaultSenderId,
        ]);
    }

    public function down(): void
    {
        Schema::table('mail_templates', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('mail_sender_id');
        });

        Schema::dropIfExists('mail_senders');
    }
};
