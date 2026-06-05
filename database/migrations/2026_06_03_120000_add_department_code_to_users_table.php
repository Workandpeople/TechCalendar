<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('department_code', 3)->nullable()->after('address')->index();
        });

        DB::table('users')
            ->whereNotNull('address')
            ->orderBy('id')
            ->chunkById(200, function ($users): void {
                foreach ($users as $user) {
                    if (! preg_match('/\b(\d{2,3})\d{3}\b/', (string) $user->address, $matches)) {
                        continue;
                    }

                    DB::table('users')
                        ->where('id', $user->id)
                        ->update(['department_code' => $matches[1]]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('department_code');
        });
    }
};
