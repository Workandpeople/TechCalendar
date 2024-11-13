<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

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
        return view('assistant.prendre_rdv');
    }

    public function agendaTech()
    {
        Log::info("Accès à l'agenda technique de l'assistant.");
        return view('assistant.agenda_tech');
    }
}