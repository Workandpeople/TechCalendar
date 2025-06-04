use Illuminate\Support\Str;
use App\Models\User;
use App\Models\Tech;

$users = [
    ['nom' => 'AMRANE', 'prenom' => 'ANIS', 'zip' => '92600', 'ville' => 'Asnières-sur-Seine', 'adresse' => '1 Place de l’Hôtel de Ville, 92600 Asnières-sur-Seine'],
    ['nom' => 'ELGYCMI', 'prenom' => 'SAID', 'zip' => '95000', 'ville' => 'Cergy', 'adresse' => '3 Place de l’Olympe-de-Gouges, 95801 Cergy-Pontoise Cedex'],
    ['nom' => 'DAOUD', 'prenom' => 'YACHOUROUTUA', 'zip' => '54000', 'ville' => 'Nancy', 'adresse' => '1 Place Stanislas, 54000 Nancy'],
    ['nom' => 'SULEYMAN', 'prenom' => 'ILHAN', 'zip' => '57645', 'ville' => 'Retonfey', 'adresse' => '21 Place du Gué, 57645 Retonfey'],
    ['nom' => 'BALLEKENS', 'prenom' => 'BERTRAND', 'zip' => '59553', 'ville' => 'Cuincy', 'adresse' => '15 Rue François-Anicot, 59553 Cuincy'],
    ['nom' => 'RUVEN', 'prenom' => 'BENJAMIN', 'zip' => '27000', 'ville' => 'Évreux', 'adresse' => 'Place du Général de Gaulle, 27000 Évreux'],
    ['nom' => 'ILLIEN', 'prenom' => 'ANTOINE', 'zip' => '35720', 'ville' => 'Châteaubourg', 'adresse' => 'Place de la Mairie, 35720 Châteaubourg'],
    ['nom' => 'RAYNAUD', 'prenom' => 'BENJAMIN', 'zip' => '63000', 'ville' => 'Clermont-Ferrand', 'adresse' => '10 Rue Philippe Marcombes, 63000 Clermont-Ferrand'],
    ['nom' => 'CHRISNACH', 'prenom' => 'EMMANUEL', 'zip' => '22140', 'ville' => 'Bégard', 'adresse' => 'Place de la République, 22140 Bégard'],
    ['nom' => 'JEBARI', 'prenom' => 'RAMZY', 'zip' => '69000', 'ville' => 'Lyon', 'adresse' => '1 Place Louis Pradel, 69001 Lyon'],
    ['nom' => 'DUPUIS', 'prenom' => 'FABRICE', 'zip' => '33980', 'ville' => 'Audenge', 'adresse' => 'Place du Général de Gaulle, 33980 Audenge'],
    ['nom' => 'FEREC', 'prenom' => 'SAMUEL', 'zip' => '13000', 'ville' => 'Marseille', 'adresse' => 'Place Daviel, 13002 Marseille'],
    ['nom' => 'SERIGN', 'prenom' => 'MBOW', 'zip' => '66000', 'ville' => 'Perpignan', 'adresse' => 'Place de la Loge, 66000 Perpignan'],
    ['nom' => 'VIVIER', 'prenom' => 'RODOLPHE', 'zip' => '22450', 'ville' => 'La Roche-Derrien', 'adresse' => 'Place du Martray, 22450 La Roche-Derrien'],
    ['nom' => 'AKIR', 'prenom' => 'NASSIM', 'zip' => '77270', 'ville' => 'Villeparisis', 'adresse' => 'Place du Marché, 77270 Villeparisis'],
    ['nom' => 'COHEN', 'prenom' => 'ETHAN', 'zip' => '92600', 'ville' => 'Asnières-sur-Seine', 'adresse' => '1 Place de l’Hôtel de Ville, 92600 Asnières-sur-Seine'],
    ['nom' => 'DELALLEAU', 'prenom' => 'NATHALIE', 'zip' => '16330', 'ville' => 'Saint-Amant-de-Boixe', 'adresse' => 'Place de la Mairie, 16330 Saint-Amant-de-Boixe'],
    ['nom' => 'SAUVETRE', 'prenom' => 'CELINE', 'zip' => '33660', 'ville' => 'Salles', 'adresse' => 'Place de la Mairie, 33660 Salles'],
    ['nom' => 'BOUZALIM', 'prenom' => 'YOUSSEF', 'zip' => '45570', 'ville' => 'Dampierre-en-Burly', 'adresse' => 'Place de l’Église, 45570 Dampierre-en-Burly'],
    ['nom' => 'FATMI', 'prenom' => 'YASSINE', 'zip' => '02200', 'ville' => 'Soissons', 'adresse' => 'Place de l’Hôtel de Ville, 02200 Soissons'],
];

foreach ($users as $data) {
    $user = User::create([
        'id' => Str::uuid(),
        'nom' => $data['nom'],
        'prenom' => $data['prenom'],
        'email' => strtolower($data['prenom'] . '.' . $data['nom'] . '@geniuscontrole.fr'),
        'password' => bcrypt('password'),
        'role' => 'tech',
    ]);

    Tech::create([
        'id' => Str::uuid(),
        'user_id' => $user->id,
        'phone' => '0102030405',
        'adresse' => $data['adresse'],
        'zip_code' => $data['zip'],
        'city' => $data['ville'],
        'default_start_at' => '08:00:00',
        'default_end_at' => '18:00:00',
        'default_rest_time' => 60,
    ]);
}
