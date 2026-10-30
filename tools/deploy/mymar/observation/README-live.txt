MYMAR - OBSERVATION REELLE PONCTUELLE, SANS ACTIVATION

Ce paquet ne deploie aucune application et ne ferme pas Cofrac. Il utilise
le compte local de maintenance pour une collecte d'audit ponctuelle, jamais
pour un connecteur recurrent. Aucun compte SQL n'est cree ou modifie.
Il ne charge aucun .env/cache comme code PHP et ne demarre aucun service.

Depuis le Mac, lancer fetch-live.sh. Il transfere le paquet hors application,
controle ses empreintes, lance la collecte root et recupere un rapport prive.
SOURCE_REVISION doit correspondre a la release V2 deja validee et ouverte.
Les quatre classes de lecture/normalisation sont comparees aux empreintes de
la preparation MYMAR desactivee de cette release, avant toute lecture SQL.

Lectures : configuration declaree/cache litteral, metadonnees MySQL et audit
des references techniciens/statuts, puis requete definitive des occupations.
Perimetre : sept jours depuis minuit Europe/Paris, tous les techniciens
Terrain actifs MYMAR, statuts 3 et 16, sans deduplication par dossier.
La requete provient de MymarReadOnlySource::query, pas d'un nouveau filtre.
SELECT uniquement, transactions READ ONLY, limite 1500 ms par requete,
attente de verrou 2 s, session 20 s, 200 techniciens et 2000 lignes maximum.
EXPLAIN simple avant la lecture, jamais EXPLAIN ANALYZE ni ajout d'index.

Les comparaisons d'emails restent dans MySQL. Aucun mot de passe, adresse,
beneficiaire, document, email brut ou erreur SQL brute n'est exporte.
La revue privee contient noms de techniciens et IDs pour validation humaine.
Les candidats de lien exigent email unique concordant, lien historique unique,
cible identique et technicien interne actif. Ils ne sont jamais approuves ou
appliques. Tous les cofrac_user_id des occupations restent null.

Rapports 0600 dans un dossier 0700 :
- observation-summary.json : compteurs, anomalies et fenetre.
- technicians-review.json.gz : propositions et cas a examiner, sans email.
- occupations-observation.json.gz : horaires interpretes et IDs source.
- query-plan.json : plan technique restreint, sans SQL ni donnees libres.
- SHA256SUMS : empreintes des quatre fichiers.
Les SQL, erreurs brutes et preuves source restent root-prives sur le serveur.
Une erreur SQL, un resultat partiel/perime ou une source modifiee interrompt
la collecte ; aucun nouveau repertoire de rapport partage n'est publie.

Les references et les occupations sont lues dans des transactions distinctes.
Une proposition de lien n'est donc pas une garantie atomique de rapprochement.
Les lignes sans technicien sont comptees separement, pas declarees libres.
Les unites de duree (heures), heures locales Europe/Paris et statuts 3/16
restent des hypotheses non confirmees. Aucun resultat ne rend le planning pret.

Apres la collecte, transmettre seulement LOCAL_DIR, pas les donnees privees.
Suite : revue des correspondances et horaires, puis compte SQL par colonnes,
passerelle privee et observation authentifiee, toujours sans ecriture MYMAR.

Test local reproductible :
MYMAR_LIVE_TEST=1 bash tools/deploy/tests/check-mymar-live-observation.sh
