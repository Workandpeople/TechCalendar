<?php

namespace App\Http\Controllers\Tech;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tech\StoreMymarOnSiteReportRequest;
use App\Models\TechnicianIncident;
use App\Services\Tech\MymarOnSiteReports;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class MymarOnSiteReportController extends Controller
{
    public function show(Request $request, int $rdv, MymarOnSiteReports $reports): Response
    {
        try {
            $page = $reports->page($request->user(), $rdv);
        } catch (RuntimeException $error) {
            if ($error instanceof HttpExceptionInterface) {
                throw $error;
            }
            abort(503, 'Ce rendez-vous MAR ne peut pas être vérifié. Réessayez ou contactez le planning.');
        }

        return response()->view('tech.mymar-on-site-report', [...$page, 'rdv' => $rdv])->header('Cache-Control', 'private, no-store');
    }

    public function store(StoreMymarOnSiteReportRequest $request, int $rdv, MymarOnSiteReports $reports): Response
    {
        $reports->submit($request->user(), $rdv, $request->validated(), $request->file('photo'));
        $url = route('tech.mar.on-site.show', $rdv);

        return $request->expectsJson()
            ? response()->json(['message' => 'Photo et signalement enregistrés. Consultez la confirmation MYMAR.', 'url' => $url])
            : redirect($url);
    }

    public function transmit(Request $request, int $rdv, MymarOnSiteReports $reports): Response
    {
        $reports->transmit($request->user(), $rdv);

        return redirect()->route('tech.mar.on-site.show', $rdv);
    }

    public function photo(Request $request, TechnicianIncident $incident, MymarOnSiteReports $reports): Response
    {
        $reports->authorizeProof($request->user(), $incident);
        $path = $incident->source_context['photo']['path'] ?? '';
        abort_unless(is_string($path) && preg_match('~^crm/mymar/'.$incident->mymar_dossier_id.'/on-site/[a-f0-9-]{36}\.jpg$~D', $path)
            && Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->response($path, 'signalement-mar-'.$incident->id.'.jpg',
            ['Content-Type' => 'image/jpeg', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff'], 'inline');
    }
}
