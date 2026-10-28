<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\SidebarNotifications;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;

final class LocalCalendarController extends Controller
{
    public function __invoke(): Response
    {
        abort_unless(app()->environment('local'), 404);

        $actor = (new User)->forceFill(['id' => 999999, 'first_name' => 'Camille', 'last_name' => 'Démo locale',
            'role' => 0, 'admin' => false, 'must_change_password' => false]);
        $actor->setRelation('departments', collect());
        Auth::guard('web')->setUser($actor);
        app()->instance(SidebarNotifications::class, new class extends SidebarNotifications
        {
            public function forUser(User $user): array
            {
                return ['settings' => 0, 'today' => 0, 'pending' => 3];
            }
        });
        config(['crm.v2_enabled' => true, 'simulation.enabled' => false, 'mail.delivery_enabled' => false,
            'broadcasting.connections.reverb.key' => null]);

        return response()->view('local.calendar')
            ->header('Cache-Control', 'no-store, private')
            ->header('X-Robots-Tag', 'noindex, nofollow');
    }
}
