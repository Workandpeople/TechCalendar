# Import standard des lots et transmission CRM

## Format accepté

Les nouveaux imports utilisent le modèle Excel à 14 colonnes validé le 17 septembre 2026 (ou son export CSV). Les en-têtes doivent être sur la première ligne non vide, dans l'ordre A à N. Les variations d'accents, de casse et de retours à la ligne sont tolérées. Aucun champ métier n'est extrait ou choisi par l'IA.

| Colonne | Destination |
| --- | --- |
| A : référence interne | Référence conservée séparément de l'identifiant Coffrac, sous-titre Global+ et commentaire de création Coffrac |
| B, C : nom, prénom du bénéficiaire | Identité du bénéficiaire |
| D, E, F : adresse, code postal, ville de l'opération | Lieu d'inspection, géocodage Mapbox, lieu d'intervention dans les CRM |
| G, H : téléphone, email du bénéficiaire | Coordonnées du bénéficiaire |
| I : raison sociale du bénéficiaire | Société cliente, jamais l'installateur |
| J, K, L : adresse, code postal, ville du siège | Adresse du bénéficiaire, distincte du lieu d'inspection |
| M : SIREN du professionnel | Rapprochement de l'installateur dans Coffrac et Global+ |
| N : raison sociale du professionnel | Nom de l'installateur |

Le délégataire reste choisi dans TechCalendar à la création du lot. Pour Global+, l'utilisateur sélectionne explicitement le client dans son référentiel. Une raison sociale identique au bénéficiaire n'est pas utilisée pour deviner ce client.

## Adresses et contrôles

Le nettoyage local retire les suffixes cadastraux, notamment `12 RUE EXEMPLE-000 AB 0152`. Si OpenAI est configuré, seules les adresses dédupliquées lui sont envoyées, sans identité, SIREN ni référence interne. Les réponses incomplètes ou modifiant le lieu sont rejetées. En cas d'indisponibilité, les adresses du fichier nettoyées localement sont conservées.

Mapbox géocode le lieu d'inspection, pas le siège social. Les lignes non géocodées restent dans l'aperçu avec un avertissement. Les avertissements doivent être corrigés ou les lignes décochées avant confirmation. Le fichier original et les valeurs brutes restent disponibles pour le diagnostic.

Les imports libres de l'ancien format sont désormais refusés avec l'indication de la colonne attendue. Les lots existants ne sont pas remappés et les aperçus déjà terminés restent confirmables.

## Coffrac

Le POST de création transmet le nom du lot (`lot_name`, enregistré dans `numero_lot`), la référence interne, les deux adresses, le bénéficiaire, l'email et l'installateur/SIREN. Coffrac ajoute la référence interne au commentaire de création. Le rapprochement par SIREN prend priorité sur le nom ; plusieurs entreprises avec le même SIREN provoquent une erreur explicite plutôt qu'un choix arbitraire.

## Global+

La civilité transmise est `M`. Le sous-titre porte la référence interne complète, y compris après association au dossier Coffrac.

Le DTO de création `POST /api/Demande` ne contient pas de champ `idControleur`. L'affectation du technicien s'effectue donc dans la même action utilisateur, par les appels suivants :

1. Créer la demande et enregistrer immédiatement son identifiant dans TechCalendar.
2. Lire `GET /api/Demande/{id}` et identifier son unique intervention.
3. Envoyer un JSON Patch vers `PATCH /api/Intervention/Patch/{id}` pour `/idControleur`, `/dateIntervention` et `/dateInterventionEnd`.
4. Relire `GET /api/Intervention/{id}` et vérifier le technicien, les horaires et le rattachement à la demande.

Ces routes et champs proviennent du Swagger de l'environnement de test consulté le 17 septembre 2026 : https://cee-api.test.globalplus.fr/swagger/v1/swagger.json.

Si la demande existe mais que l'affectation n'est pas confirmée, l'état local est `appointment_failed`, un avertissement apparaît et le bouton permet de reprendre l'affectation sans recréer le dossier. Une synchronisation des documents ne masque pas cet état. Les appels de création concurrents sont verrouillés.

## Déploiement

Déployer les modifications dans **TechCalendar et Coffrac**. Dans TechCalendar :

```sh
php artisan migrate --force
npm ci --ignore-scripts
npm run build
php artisan optimize:clear
php artisan queue:restart
```

Vérifier que le gestionnaire de services relance bien les workers après `queue:restart`. Dans Coffrac, vider les caches applicatifs après déploiement. Aucun nouveau secret n'est nécessaire.

Les tests automatisés simulent les réponses CRM et vérifient les payloads et les reprises. Ils ne remplacent pas une recette sur l'environnement de test Global+ avec son jeton, ses habilitations et un dossier réel.
