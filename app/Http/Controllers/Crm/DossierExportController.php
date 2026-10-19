<?php

namespace App\Http\Controllers\Crm;

use App\Http\Controllers\Controller;
use App\Http\Requests\Crm\DossierListRequest;
use App\Services\Crm\DossierSpreadsheet;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DossierExportController extends Controller
{
    public function __invoke(DossierListRequest $request, DossierSpreadsheet $export): StreamedResponse
    {
        return $export->download($request->user(), $request->validated());
    }
}
