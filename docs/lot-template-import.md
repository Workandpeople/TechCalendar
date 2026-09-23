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

Le délégataire reste choisi dans TechCalendar à la création du lot. Pour Global+, le client est proposé uniquement si son nom correspond exactement au délégataire (ou à sa raison sociale Coffrac), après normalisation des accents et de la casse. Les correspondances ambiguës restent à sélectionner. Sans correspondance, le choix manuel requiert une confirmation explicite. Le bénéficiaire et l'installateur ne servent jamais à choisir le client. Le bloc client transmet l'identifiant d'adresse et les coordonnées du client Global+ choisi, sans les remplacer par celles du bénéficiaire.

## Adresses et contrôles

Le nettoyage local retire les suffixes cadastraux, notamment `12 RUE EXEMPLE-000 AB 0152`. Si OpenAI est configuré, seules les adresses dédupliquées lui sont envoyées, sans identité, SIREN ni référence interne. Les réponses incomplètes ou modifiant le lieu sont rejetées. En cas d'indisponibilité, les adresses du fichier nettoyées localement sont conservées.

Mapbox géocode le lieu d'inspection, pas le siège social. Les lignes non géocodées restent dans l'aperçu avec un avertissement. Les avertissements doivent être corrigés ou les lignes décochées avant confirmation. Le fichier original et les valeurs brutes restent disponibles pour le diagnostic.

Les imports libres de l'ancien format sont désormais refusés avec l'indication de la colonne attendue. Les lots existants ne sont pas remappés et les aperçus déjà terminés restent confirmables.

## Coffrac

Le POST de création transmet le nom du lot (`lot_name`, enregistré dans `numero_lot`), la référence interne, les deux adresses, le bénéficiaire, l'email et l'installateur/SIREN. Coffrac ajoute la référence interne au commentaire de création. Le rapprochement par SIREN prend priorité sur le nom ; plusieurs entreprises avec le même SIREN provoquent une erreur explicite plutôt qu'un choix arbitraire.

## Global+

La civilité transmise est `M.` (avec le point), valeur exacte du bouton radio dans l'interface Global+ test consultée le 17 septembre 2026 (`/js/7235.091bf3b2.js`, composant client). Le défaut `Mr` indiqué dans Swagger ne correspond pas à ce bouton. Le sous-titre porte la référence interne complète, y compris après association au dossier Coffrac.

Le DTO de création `POST /api/Demande` ne contient pas de champ `idControleur`. L'affectation du technicien s'effectue donc dans la même action utilisateur, par les appels suivants :

1. Créer la demande et enregistrer immédiatement son identifiant dans TechCalendar.
2. Un second job différé lit directement `GET /api/Intervention/ByDemande/{idDemande}`, route fournie par Global+ et confirmée dans Swagger le 23 septembre 2026. L'identifiant dans l'URL est celui du dossier créé (exemple : `5655`), pas celui du RDV TechCalendar. Les anciennes lectures `GET /api/Demande/{id}` et `ListInterventions` ne sont plus utilisées pour la résolution : la première renvoyait une relation vide, la seconde était refusée avec HTTP 403.
3. Retenir l'unique intervention retournée par `ByDemande`. Cette route est limitée au dossier demandé et peut ne renvoyer que `{id}` : elle suffit pour identifier l'intervention, sans dépendre de `GET /api/Intervention/{id}`, refusé avec HTTP 403. Un `idDemande` explicitement différent, plusieurs candidats ou un identifiant différent de celui déjà enregistré restent bloquants. Envoyer ensuite un JSON Patch vers `PATCH /api/Intervention/Patch/{id}` pour `/idControleur`, `/dateIntervention` et `/dateInterventionEnd`.
4. Relire `ByDemande` et vérifier les champs d'affectation disponibles. Si le technicien et les deux horaires sont présents et conformes, l'affectation est confirmée. Si la réponse ne contient pas ces champs, conserver `appointment_sent` : « Affectation envoyée, non vérifiée », sans faux succès ni répétition inutile du PATCH. Une valeur explicitement différente, y compris `null`, déclenche les reprises prévues. Une synchronisation des documents ne masque pas cet état.

Les champs du PATCH proviennent du Swagger de l'environnement de test consulté le 17 septembre 2026 : https://cee-api.test.globalplus.fr/swagger/v1/swagger.json. La route `ByDemande` a été ajoutée par Global+ et son accès confirmé par les retours de production du 23 septembre. **Attention : présence dans Swagger ne signifie pas autorisation pour la clé d'intégration.** Un HTTP 403 sur `ByDemande` ou le PATCH reste une erreur de droits à faire corriger par Global+, pas un délai à contourner par des tentatives répétées. Ne jamais utiliser l'identifiant de demande comme identifiant d'intervention.

En cas d'échec de l'affectation ou de vérification non conforme après les reprises automatiques, l'état local est `appointment_failed`, un avertissement apparaît et le bouton permet de reprendre l'affectation sans recréer le dossier. Une synchronisation des documents ne masque pas cet état. Une réponse valide mais insuffisante pour vérifier les champs après un PATCH accepté utilise l'état distinct `appointment_sent`. Les appels de création concurrents sont verrouillés.

Le message et les logs indiquent l'étape (`resolve_intervention`, `assign_technician`, `verify_assignment`), la méthode, la route et le statut HTTP, sans jeton ni contenu des documents. Le diagnostic est conservé dans `global_plus_payload.appointment_assignment`. Un PATCH accepté suivi d'un refus de lecture est distingué d'une affectation jamais envoyée. Une reprise réutilise l'identifiant d'intervention déjà obtenu et le technicien choisi. Elle ne modifie pas le client d'un dossier existant : aucune route de modification du client n'est fournie dans le contrat actuel ; corriger les anciens dossiers directement dans Global+.

Lors d'une reprise, l'installateur et ses coordonnées sont restaurés depuis `global_plus_payload.last_request.entreprise`, y compris en saisie manuelle ou si l'entrée a disparu du référentiel. Ils restent en lecture seule, car seul le technicien et les horaires sont renvoyés. La liste d'installateurs est regroupée avec leurs coordonnées dans la colonne de droite du formulaire de confirmation.

### Diagnostic de l'affectation

Les traces dédiées sont écrites dans `storage/logs/global-plus-YYYY-MM-DD.log`, avec rotation sur 14 jours et niveau `info` indépendant de `LOG_LEVEL`. Elles contiennent l'identifiant d'opération, le dossier, le numéro de tentative, les routes/statuts HTTP, le nombre de candidats et la raison de leur exclusion. Aucun corps de requête/réponse, clé, adresse, email ou document n'est journalisé. Le détail de l'étape reste également en base, même si le journal Laravel principal filtre les avertissements ou utilise un autre canal.

Une liste vide déclenche les tentatives espacées prévues. Une ambiguïté, un mauvais rattachement ou un 403 arrête le traitement sans PATCH. Après épuisement des tentatives, le message indique explicitement l'arrêt et l'identifiant de diagnostic ; le dossier existant n'est pas recréé. La résolution via la nouvelle route est identifiée par `resolution_source=intervention_by_demande`. Aucun repli vers l'ancienne route refusée n'est effectué.

Après déploiement de ces diagnostics :

```sh
php artisan config:cache
php artisan queue:restart
```

Relancer **Réessayer l'affectation du technicien**, puis lire le journal :

```sh
tail -n 100 -f storage/logs/global-plus-$(date +%F).log
```

Si aucun fichier n'est créé après l'essai, vérifier le redémarrage effectif du worker, les permissions de `storage/logs` pour son utilisateur et son journal système. Le `LOG_CHANNEL` du worker peut différer du processus web tant qu'il n'a pas été redémarré.

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
