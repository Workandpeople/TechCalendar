<?php

namespace App\Http\Controllers\Crm;

use App\Http\Controllers\Controller;
use App\Models\LotReportExport;
use App\Services\Crm\LotReportExports;
use App\Services\Crm\LotReportSelection;
use Illuminate\Http\Request;

class LotReportExportController extends Controller
{
    public function lots(Request $request, LotReportSelection $selection)
    {
        $data = $request->validate(['q' => ['nullable', 'string', 'max:256']]);

        return response()->json(['lots' => $selection->lots($request->user(), $data['q'] ?? '')]);
    }

    public function index(Request $request, LotReportExports $exports)
    {
        return response()->json(['exports' => $exports->recent($request->user())]);
    }

    public function store(Request $request, LotReportExports $exports)
    {
        $data = $request->validate(['lot' => ['required', 'string', 'max:256']]);

        return response()->json($exports->result($exports->request($request->user(), $data['lot'])), 202);
    }

    public function download(Request $request, LotReportExport $export, LotReportExports $exports)
    {
        return $exports->download($request->user(), $export);
    }
}
