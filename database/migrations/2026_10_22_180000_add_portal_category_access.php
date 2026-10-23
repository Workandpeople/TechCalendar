<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('portal_category_defaults', function (Blueprint $table): void {
            $table->string('kind', 32)->primary();
            $table->json('categories');
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
        Schema::table('external_delegataires', function (Blueprint $table): void {
            // NULL inherits the type; [] explicitly denies every category.
            $table->json('portal_categories')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('external_delegataires', fn (Blueprint $table) => $table->dropColumn('portal_categories'));
        Schema::dropIfExists('portal_category_defaults');
    }
};
