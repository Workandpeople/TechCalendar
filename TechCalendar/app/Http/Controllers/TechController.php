<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use App\Models\Rendezvous;
use Carbon\Carbon;

class TechController extends Controller
{
    public function dashboard()
    {
        $userId = Auth::id(); // ID de l'utilisateur connecté

        // Récupérer les rendez-vous de l'utilisateur connecté
        $rendezvous = Rendezvous::where('technician_id', $userId)
            ->orderBy('date')
            ->get();

        log::info($rendezvous);

        return view('tech.dashboard', [
            'rendezvous' => $rendezvous,
        ]);
    }

    public function getUserAppointments(Request $request)
    {
        $userId = Auth::id();
        $weekStart = new Carbon($request->week_start);
        $weekEnd = $weekStart->copy()->addDays(4);

        $appointments = Rendezvous::where('technician_id', $userId)
            ->whereBetween('date', [$weekStart, $weekEnd])
            ->get();

        return response()->json([
            'appointments' => $appointments,
        ]);
    }
}