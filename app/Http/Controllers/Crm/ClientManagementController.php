<?php

namespace App\Http\Controllers\Crm;

use App\Http\Controllers\Controller;
use App\Http\Requests\Crm\ClientAccountRequest;
use App\Http\Requests\Crm\ClientRequest;
use App\Http\Requests\Crm\PortalCategoryDefaultRequest;
use App\Models\ExternalDelegataire;
use App\Models\User;
use App\Services\Crm\ClientAccess;
use App\Services\Crm\ClientManagement;
use App\Services\Crm\PortalCategoryAccess;
use Illuminate\Http\Request;

class ClientManagementController extends Controller
{
    public function categories(Request $request, ClientAccess $access, PortalCategoryAccess $categories)
    {
        $access->manage($request->user());

        return view('manager.clients.categories', ['defaults' => collect(ExternalDelegataire::KINDS)
            ->map(fn ($label, $kind) => $categories->defaults($kind))->all()]);
    }

    public function updateCategories(PortalCategoryDefaultRequest $request, string $kind, PortalCategoryAccess $categories)
    {
        $categories->saveDefaults($request->user(), $kind, $request->validated('categories', []));

        return redirect()->route('manager.clients.categories')->with('status', 'Catégories mises à jour. Les accès sont appliqués dès la prochaine requête.');
    }

    public function create(Request $request, ClientAccess $access)
    {
        $access->manage($request->user());

        return view('manager.clients.form', ['client' => new ExternalDelegataire(['kind' => 'client', 'is_active' => true])]);
    }

    public function store(ClientRequest $request, ClientManagement $management)
    {
        $client = $management->save($request->user(), $request->validated());

        return redirect()->route('manager.clients.edit', $client)->with('status', 'Client créé. Tu peux maintenant créer ses accès.');
    }

    public function edit(Request $request, ExternalDelegataire $client, ClientAccess $access)
    {
        $access->manage($request->user());

        return view('manager.clients.form', ['client' => $client,
            'accounts' => $client->accounts()->orderBy('last_name')->paginate(15),
            'defaults' => ClientAccess::defaults($client->kind)]);
    }

    public function update(ClientRequest $request, ExternalDelegataire $client, ClientManagement $management)
    {
        $management->save($request->user(), $request->validated(), $client);

        return redirect()->route('manager.clients.edit', $client)->with('status', 'Client mis à jour.');
    }

    public function storeAccount(ClientAccountRequest $request, ExternalDelegataire $client, ClientManagement $management)
    {
        $management->saveAccount($request->user(), $client, $request->validated());

        return redirect()->route('manager.clients.edit', $client)->with('status', 'Accès créé. Transmets les identifiants par un canal sécurisé ; le mot de passe devra être changé à la première connexion. Aucun email automatique envoyé.');
    }

    public function updateAccount(ClientAccountRequest $request, ExternalDelegataire $client, User $account, ClientManagement $management)
    {
        $management->saveAccount($request->user(), $client, $request->validated(), $account);

        return redirect()->route('manager.clients.edit', $client)->with('status', 'Accès et droits mis à jour.');
    }
}
