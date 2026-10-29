<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Http\Requests\Account\UpdatePasswordRequest;
use App\Http\Requests\Account\UpdatePortalNotificationsRequest;
use App\Http\Requests\Account\UpdateProfileRequest;
use App\Services\AccountProfile;
use App\Services\Crm\PortalNotificationSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class ProfileController extends Controller
{
    public function show(Request $request, PortalNotificationSettings $notifications): Response
    {
        $user = $request->user()->loadMissing('externalClient');

        return response()->view('account.profile', [
            'account' => $user,
            'preferences' => $notifications->values($user),
            'notificationCategories' => $user->isExternal() ? $notifications->categories($user) : [],
        ])->header('Cache-Control', 'private, no-store');
    }

    public function update(UpdateProfileRequest $request, AccountProfile $profile): RedirectResponse
    {
        $profile->update($request->user(), $request->validated());

        return redirect()->route('profile')->with('status', 'Vos informations personnelles ont été enregistrées.');
    }

    public function password(UpdatePasswordRequest $request, AccountProfile $profile): RedirectResponse
    {
        $profile->changePassword($request->user(), $request->validated(), $request->session()->getId());
        $request->session()->regenerate();

        return redirect()->route('profile')->withFragment('securite')->with('status', 'Votre mot de passe a été modifié.');
    }

    public function notifications(UpdatePortalNotificationsRequest $request, PortalNotificationSettings $notifications): RedirectResponse
    {
        $notifications->save($request->user(), $request->validated());

        return redirect()->route('profile')->withFragment('notifications')->with('status', 'Vos préférences de notification ont été enregistrées.');
    }
}
