<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\WAPetGCTech;

class GraphController extends Controller
{
    public function graphUser()
    {
        $technicians = WAPetGCTech::with('appointments')->get(); // Charge les techniciens avec leurs rendez-vous
        return view('admin.graph_user', compact('technicians'));
    }
}
