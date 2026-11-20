<?php

namespace App\Http\Controllers\Planner;

use App\Http\Controllers\Controller;
use App\Services\Crm\AppointmentChangeReviews;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

class AppointmentChangeReviewController extends Controller
{
    public function index(Request $request, AppointmentChangeReviews $reviews): Response
    {
        $filters = $request->validate(['status' => ['sometimes', Rule::in(['pending', 'done', 'all', 'excluded'])], 'q' => ['nullable', 'string', 'max:120']]);

        return response()->view('planner.appointment-changes', ['appointments' => $reviews->search($request->user(), $filters), 'filters' => $filters,
            'bulk' => $reviews->bulkPreview($request->user(), $filters)])->header('Cache-Control', 'private, no-store');
    }

    public function update(Request $request, int $appointment, AppointmentChangeReviews $reviews): RedirectResponse
    {
        $data = $request->validate(['version' => ['required', 'integer', 'min:1'], 'confirmed' => ['required', 'accepted']]);
        $reviews->acknowledge($request->user(), $appointment, (int) $data['version']);

        return back()->with('status', 'Report manuel sur Global+ confirmé. Aucun envoi automatique effectué.');
    }

    public function updateBulk(Request $request, AppointmentChangeReviews $reviews): RedirectResponse
    {
        $data = $request->validate(['snapshot' => ['required', 'string', 'max:131072'], 'confirmed' => ['required', 'accepted']]);
        $count = $reviews->acknowledgeBulk($request->user(), $data['snapshot']);

        return back()->with('status', $count.' report(s) sur Global+ confirmé(s). Aucun envoi automatique effectué.');
    }
}
