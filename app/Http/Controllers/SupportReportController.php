<?php

namespace App\Http\Controllers;

use App\Http\Requests\SupportFeedbackRequest;
use App\Services\Support\ReportContext;
use App\Services\Support\SupportReports;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class SupportReportController extends Controller
{
    public function feedback(SupportFeedbackRequest $request, ReportContext $context, SupportReports $reports)
    {
        $data = $request->validated();
        $actor = $request->user();
        $report = ['id' => $data['submission_id'], 'kind' => $data['kind'], 'title' => $data['title'], 'message' => $data['message'],
            'actor_id' => $actor->id, 'actor_name' => $actor->full_name, 'actor_email' => $actor->email,
            'viewport' => $data['viewport'] ?? null, 'browser' => $this->browser($request),
            ...$context->page($data['page_path'])];

        return $this->respond($request, $reports->submit($report, $request->file('screenshots', []), $request));
    }

    public function error(Request $request, ReportContext $context, SupportReports $reports)
    {
        $validator = Validator::make($request->all(), ['error_token' => ['required', 'string', 'max:16384']]);
        if ($validator->fails()) {
            return response()->json(['message' => 'Diagnostic manquant ou invalide. Rechargez la page d’erreur.'], 422);
        }
        $report = $context->open($request->input('error_token'));
        $report += ['title' => 'Erreur HTTP '.$report['status'], 'message' => 'Diagnostic transmis depuis la page d’erreur.',
            'browser' => $this->browser($request)];

        return $this->respond($request, $reports->submit($report, [], $request, anonymous: true));
    }

    private function browser(Request $request): string
    {
        return mb_substr(preg_replace('/[\x00-\x1f\x7f]/', ' ', $request->userAgent() ?? ''), 0, 300);
    }

    private function respond(Request $request, array $result)
    {
        $status = $result['delivery'] === 'failed' ? 503 : 200;

        return $request->expectsJson()
            ? response()->json($result, $status)->header('Cache-Control', 'no-store, private')
            : response()->view('errors.report-receipt', $result, $status)->header('Cache-Control', 'no-store, private');
    }
}
