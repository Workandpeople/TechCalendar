<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('external_delegataires', 'report_download_naming')) {
            Schema::table('external_delegataires', fn (Blueprint $table) => $table->string('report_download_naming', 30)->default('standard'));
        }

        // Exact known organisation labels only; never infer ownership from a report reference.
        DB::table('external_delegataires')->where('report_download_naming', 'standard')
            ->select(['id', 'name', 'company_name'])->orderBy('id')->chunkById(200, function ($organisations): void {
                $ids = $organisations->filter(function ($organisation): bool {
                    foreach ([$organisation->name, $organisation->company_name] as $label) {
                        $label = Str::upper(Str::squish(Str::ascii($label ?? '')));
                        if (in_array($label, ['ALVEA', 'ALVEA CEE', 'ALVEA DELEGATAIRE'], true)) {
                            return true;
                        }
                    }

                    return false;
                })->pluck('id');
                if ($ids->isNotEmpty()) {
                    DB::table('external_delegataires')->whereIn('id', $ids)->update(['report_download_naming' => 'outcome_prefix']);
                }
            });
    }

    public function down(): void
    {
        Schema::table('external_delegataires', fn (Blueprint $table) => $table->dropColumn('report_download_naming'));
    }
};
