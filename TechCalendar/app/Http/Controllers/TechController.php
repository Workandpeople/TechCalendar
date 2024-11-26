<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class TechController extends Controller
{
    public function dashboard()
    {
        return view('tech.dashboard');
    }

    public function agenda()
    {
        return view('tech.agenda');
    }
}