<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Keep historical references and integrations attached to the same client record.
        Schema::table('external_delegataires', function (Blueprint $table): void {
            $table->string('kind', 20)->default('delegataire')->index();
        });
        Schema::table('users', function (Blueprint $table): void {
            $table->foreignId('external_client_id')->nullable()->constrained('external_delegataires')->restrictOnDelete();
            $table->json('portal_permissions')->nullable();
            $table->boolean('portal_enabled')->default(false);
        });
        foreach (['lots', 'lot_import_previews'] as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->foreignId('delegataire_id')->nullable()->constrained('external_delegataires')->restrictOnDelete();
            });
            // Never grant access using a fuzzy match or an ambiguous company name.
            $clients = DB::table('external_delegataires')->get(['id', 'name'])->groupBy('name');
            foreach (DB::table($name)->whereNull('delegataire_id')->orderBy('id')->lazyById(200) as $row) {
                $matches = $clients->get($row->delegataire, collect());
                if (filled($row->delegataire) && $matches->count() === 1) {
                    DB::table($name)->where('id', $row->id)->whereNull('delegataire_id')
                        ->update(['delegataire_id' => $matches->first()->id]);
                }
            }
        }
        foreach (['dossier_documents', 'dossier_activities'] as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->boolean('client_visible')->default(false);
            });
        }
    }

    public function down(): void
    {
        foreach (['dossier_documents', 'dossier_activities'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->dropColumn('client_visible'));
        }
        foreach (['lots', 'lot_import_previews'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->dropConstrainedForeignId('delegataire_id'));
        }
        Schema::table('users', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('external_client_id');
            $table->dropColumn(['portal_permissions', 'portal_enabled']);
        });
        Schema::table('external_delegataires', function (Blueprint $table): void {
            $table->dropIndex(['kind']);
            $table->dropColumn('kind');
        });
    }
};
