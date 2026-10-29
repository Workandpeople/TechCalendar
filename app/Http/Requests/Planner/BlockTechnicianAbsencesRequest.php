<?php

namespace App\Http\Requests\Planner;

use App\Models\TechnicianAbsence;
use App\Services\TechnicianAbsenceBlocking;
use Illuminate\Foundation\Http\FormRequest;

class BlockTechnicianAbsencesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', TechnicianAbsence::class) ?? false;
    }

    public function rules(): array
    {
        return TechnicianAbsenceBlocking::rules();
    }

    public function attributes(): array
    {
        return [
            'technician_ids' => 'techniciens',
            'technician_ids.*' => 'technicien',
            'date' => 'date',
            'period' => 'période',
            'reason' => 'motif',
        ];
    }
}
