<?php

namespace App\Http\Controllers;

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Models\Prestation;
use App\Models\User;

class AssistantController extends Controller
{
    public function dashboard()
    {
        Log::info("Accès au tableau de bord de l'assistant.");
        return view('assistant.dashboard');
    }

    public function prendreRdv()
    {
        Log::info("Accès à la prise de rendez-vous de l'assistant.");

        // Récupérer les prestations depuis la base de données
        $prestations = Prestation::all();

        return view('assistant.prendre_rdv', compact('prestations'));
    }

    public function agendaTech()
    {
        Log::info("Accès à l'agenda technique de l'assistant.");

        // Récupérer uniquement les techniciens
        $techniciens = User::whereHas('role', function ($query) {
            $query->where('role', 'technicien');
        })->get();

        return view('assistant.agenda_tech', compact('techniciens'));
    }
}