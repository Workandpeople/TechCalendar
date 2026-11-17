<?php

namespace App\Http\Controllers\Planner;

use App\Http\Controllers\Controller;
use App\Services\Crm\AppointmentChangeReviews;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class AppointmentChangeReviewController extends Controller
{
    public function index(Request $request, AppointmentChangeReviews $reviews): View
    {
        $filters = $request->validate(['status' => ['sometimes', Rule::in(['pending', 'done', 'all'])], 'q' => ['nullable', 'string', 'max:120']]);

        return view('planner.appointment-changes', ['appointments' => $reviews->search($request->user(), $filters), 'filters' => $filters]);
    }

    public function update(Request $request, int $appointment, AppointmentChangeReviews $reviews): RedirectResponse
    {
        $data = $request->validate(['version' => ['required', 'integer', 'min:1'], 'confirmed' => ['required', 'accepted']]);
        $reviews->acknowledge($request->user(), $appointment, (int) $data['version']);

        return back()->with('status', 'Report manuel sur Global+ confirmé. Aucun envoi automatique effectué.');
    }
}
