<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class TechController extends Controller
{
    public function dashboard()
    {
        Log::info("Accès au tableau de bord du technicien.");
        return view('tech.dashboard');
    }

    public function agenda()
    {
        Log::info("Accès à l'agenda du technicien.");
        return view('tech.agenda');
    }
}