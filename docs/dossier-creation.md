# Creation des dossiers

## Doublons

- Une fiche CEE exacte + un telephone ou une adresse complete deja utilises
  bloquent toujours la creation, quel que soit l'etat du dossier existant.
- Seul un compte interne avec `admin = true` peut cocher la derogation dans le
  formulaire de creation. Le role Gerant seul ne suffit pas. Une confirmation
  est demandee avant l'envoi du formulaire ; l'autorisation est reverifiee
  dans la requete et dans le service, pas seulement dans l'interface.
- Le verrou de la fiche est conserve jusqu'a la fin de la transaction. La
  derogation n'autorise ni une reference deja utilisee ni plusieurs fiches.
- Une derogation effective est tracee dans l'activite privee `created` avec
  son auteur, `duplicate_override = true` et `service_id`. Aucun dossier
  existant n'est modifie. Sans doublon reel, l'activite reste ordinaire.

## Adresse et commune

- Le mode adresse precise exige une suggestion Mapbox confirmee cote serveur.
- Le mode "Adresse non trouvee" exige l'adresse libre, un code postal a cinq
  chiffres et une ville. Il est disponible en interne et dans l'espace client.
- La recherche accepte le nom seul, le code postal seul ou les deux, avant ou
  apres le nom. Les abreviations usuelles de communes sont normalisees.
- Une commune unique et exacte fournit un centre approximatif. Une selection
  explicite reste reverifiee : aucun autre resultat ne lui est substitue.
- Sans selection explicite, si la verification echoue (absence, ambiguite,
  indisponibilite), la creation reste possible. Les valeurs saisies sont
  conservees et `inspection_geocode.precision` vaut `unverified`, sans GPS.
- Le badge "Localisation a verifier" apparait dans les listes et fiches
  internes/externes. Une correction confirmee le retire. Aucune coordonnee
  n'est inventee ; les controles existants du planning restent actifs.
- Seules les listes publiques non vides de communes sont cachees un jour.
  Les erreurs et resultats vides peuvent etre retentes sans attendre ce delai.

## Verification et deploiement

```bash
php -d memory_limit=1G vendor/bin/pest --compact
node --test tests/Frontend/*.test.mjs
npm run build
```

Les tests ciblent notamment `DossierDuplicateCreationTest`,
`DossierLocalityFallbackTest`, `FrenchCommuneGeocodingTest`,
`dossier-creation.test.mjs` et `dossier-address.test.mjs`.

Aucune nouvelle migration, dependance ou activation externe n'est necessaire.
Utiliser le paquet applicatif scelle habituel ; les modes mail/SMS et la
configuration MYMAR restent inchanges.
