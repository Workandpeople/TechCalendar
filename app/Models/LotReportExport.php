<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LotReportExport extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'file_count' => 'integer', 'unavailable_count' => 'integer', 'archive_bytes' => 'integer'];
    }
}
