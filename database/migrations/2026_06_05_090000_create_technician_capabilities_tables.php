<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('departments', function (Blueprint $table): void {
            $table->string('code', 3)->primary();
            $table->string('name');
            $table->timestamps();
        });

        foreach (config('departments', []) as $code => $name) {
            DB::table('departments')->insert([
                'code' => $code,
                'name' => $name,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        Schema::create('department_user', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('department_code', 3);
            $table->timestamps();

            $table->foreign('department_code')->references('code')->on('departments')->cascadeOnDelete();
            $table->unique(['user_id', 'department_code']);
            $table->index('department_code');
        });

        Schema::create('service_user', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['user_id', 'service_id']);
            $table->index('service_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_user');
        Schema::dropIfExists('department_user');
        Schema::dropIfExists('departments');
    }
};
