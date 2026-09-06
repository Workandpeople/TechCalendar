<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lot_appointment_documents', function (Blueprint $table): void {
            if (! Schema::hasColumn('lot_appointment_documents', 'global_plus_pushed_at')) {
                $table->timestamp('global_plus_pushed_at')->nullable()->after('remote_document');
            }

            if (! Schema::hasColumn('lot_appointment_documents', 'global_plus_remote_document')) {
                $table->json('global_plus_remote_document')->nullable()->after('global_plus_pushed_at');
            }

            if (! Schema::hasColumn('lot_appointment_documents', 'global_plus_error_message')) {
                $table->text('global_plus_error_message')->nullable()->after('global_plus_remote_document');
            }
        });
    }

    public function down(): void
    {
        Schema::table('lot_appointment_documents', function (Blueprint $table): void {
            $columns = array_values(array_filter([
                Schema::hasColumn('lot_appointment_documents', 'global_plus_error_message') ? 'global_plus_error_message' : null,
                Schema::hasColumn('lot_appointment_documents', 'global_plus_remote_document') ? 'global_plus_remote_document' : null,
                Schema::hasColumn('lot_appointment_documents', 'global_plus_pushed_at') ? 'global_plus_pushed_at' : null,
            ]));

            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }
};
