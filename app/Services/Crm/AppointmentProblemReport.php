<?php

namespace App\Services\Crm;

use App\Services\CoffracAppointmentService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class AppointmentProblemReport
{
    public static function rules(array $types): array
    {
        return [
            'comment' => ['required', 'string', 'min:3', 'max:5000'],
            'problem_type' => ['required', 'string', Rule::in($types)],
            'recall_date' => ['nullable', 'required_with:recall_time', 'required_if:problem_type,'.CoffracAppointmentService::PROBLEM_TYPE_CALLBACK, 'date_format:Y-m-d'],
            'recall_time' => ['nullable', 'required_if:problem_type,'.CoffracAppointmentService::PROBLEM_TYPE_CALLBACK, 'date_format:H:i'],
            ...ContentAudience::rules(),
        ];
    }

    public static function fromRequest(Request $request, array $validated): array
    {
        return [...$validated, ...ContentAudience::fromRequest($request, 'client')];
    }
}
