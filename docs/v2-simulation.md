# Simulation locale V2 avec les donnees de production

La base `techcalendar_v2_simulation` permet de tester la fusion native avec les
references TechCalendar et le dump Coffrac du 14 octobre 2026. La production
n'est jamais contactee pendant le seed. L'ancienne base locale `temp` est conservee.

## Utilisation

Le `.env` local doit contenir :

```dotenv
APP_ENV=local
CRM_V2_ENABLED=true
SIMULATION_MODE=true
SIMULATION_ALLOW_EXTERNAL_APIS=true
DB_CONNECTION=mysql
DB_HOST=mysql
DB_DATABASE=techcalendar_v2_simulation
```

Ne pas definir un `DB_URL` pointant sur une autre base. Le compte MySQL local doit
avoir les droits sur cette base dediee. Les snapshots restent sous
`storage/app/private/simulation/dataset`, hors Git et hors dossier public.

Le compte de connexion utilise `ADMIN_MAIL` et `ADMIN_PASSWORD` du `.env` local.
Les autres comptes importes ont des mots de passe aleatoires, jamais ceux de
production. Les droits administrateur Coffrac ne sont pas recopies.

Pour remettre **uniquement cette simulation** a son etat initial :

```bash
./vendor/bin/sail artisan simulation:reset --force
```

Cette commande efface les modifications locales de la simulation, reconstruit le
schema puis recharge les snapshots. Elle refuse une base sans suffixe
`_simulation`, un serveur distant, un environnement autre que local/testing,
un compte administrateur local invalide ou des fichiers incomplets/corrompus.
Les empreintes, lignes et effectifs source sont controles avant la suppression.
Une seule remise a zero peut tourner a la fois.

Un simple `db:seed` sur une simulation deja chargee avec le meme jeu de donnees
ne duplique rien et ne supprime pas les modifications locales. Pour un vrai reset,
utiliser la commande ci-dessus, pas `migrate:fresh` manuellement.

La configuration precedente est conservee localement dans
`storage/app/private/simulation/env-before-simulation` (fichier confidentiel).
Pour revenir a l'ancienne base, remettre `DB_DATABASE=temp` et
`SIMULATION_MODE=false`, puis redemarrer les processus locaux persistants.
Ne pas restaurer tout le fichier de sauvegarde si d'autres parametres ont evolue.

## Protections des donnees reelles

- Appels HTTP Laravel sortants bloques par defaut. `SIMULATION_ALLOW_EXTERNAL_APIS=true`
  autorise explicitement toutes les API en local (Mapbox, Global+, etc.). Verifier les
  destinations et utiliser les cles de test : ce drapeau ne transforme pas une API
  de production en bac a sable. Coffrac reste desactive en V2.
- Tous les envois de mails annules avant transport, y compris les expediteurs SMTP dynamiques.
- Configuration Mapbox/OpenAI/Firebase/CRM neutralisee si les API ne sont pas autorisees.
- Planifications automatiques desactivees ; aucun lancement de synchronisation en cron.
- Diffusion serveur en mode log par defaut ; avec `SIMULATION_ALLOW_EXTERNAL_APIS=true`,
  le diffuseur configure est conserve. Utiliser le serveur Reverb local, pas celui de production.
- Bandeau de simulation dans l'application.
- Aucun mot de passe, jeton utilisateur ou identifiant SMTP de production importe.
- Fichiers personnels conserves dans un repertoire prive ignore par Git.

Le mode simulation s'active aussi lorsque le nom de la connexion resolue se
termine par `_simulation`, meme si le drapeau a ete oublie. Il refuse de demarrer
en environnement production. Ces protections couvrent les transports applicatifs
actuels ; elles ne remplacent pas un pare-feu pour de nouveaux clients reseau.

### Imports et temps reel en developpement

Les workers et Reverb conservent leur configuration en memoire. Apres modification
du `.env` ou du mode simulation, relancer les processus sans interrompre un import :

```bash
./vendor/bin/sail artisan config:clear
./vendor/bin/sail artisan queue:restart
./vendor/bin/sail artisan reverb:restart
```

Supervisor les relance automatiquement dans Sail. Dans le conteneur unique actuel,
`REVERB_HOST=127.0.0.1` permet aux workers de publier vers Reverb ;
`VITE_REVERB_HOST=localhost` est l'adresse utilisee par le navigateur du Mac.
Les deux utilisent le port 8080 et le schema `http` en local.
Les cles Mapbox et OpenAI restent dans les parametres prives ou le `.env`.
L'IA nettoie uniquement les adresses ; les colonnes Excel restent deterministes.

## Donnees chargees

| Source | Donnees |
| --- | ---: |
| TechCalendar | 14 utilisateurs, 20 prestations, 29 alias |
| TechCalendar | 101 departements, 209 habilitations prestation, 86 affectations departement |
| TechCalendar | 10 delegataires, 1 expediteur sans identifiants SMTP |
| TechCalendar | 0 template mail dans l'export fourni |
| Coffrac | 17 348 dossiers, 16 717 fiches/prestations |
| Coffrac | 16 423 lignes de rendez-vous de dossier, 3 755 rendez-vous externes |
| Coffrac | 64 455 commentaires, 128 549 evenements d'historique |
| Coffrac | 103 454 metadonnees de documents |

Le seed cree 19 088 rendez-vous natifs exploitables : 15 333 attaches aux dossiers
et 3 755 occupations externes. Les 1 090 lignes incompletes restent archivees,
sans inventer de date, technicien ou duree. Les 21 tables metier autorisees sont
conservees integralement sous forme assainie dans `legacy_records`
(352 813 lignes), en plus des projections natives.

La selection des documents hors dossiers entierement factures est maintenant
disponible localement : voir la section suivante. Le reste des archives conserve
ses metadonnees sans lien vers la production. Les fichiers legers (`pdf`, `media`,
etc.) recuperes separement ne sont pas automatiquement rattaches.

## Documents collectes sans seconde copie

Verification du 15 octobre 2026 : les 16 897 chemins du manifeste existent, pour
27 805 594 182 octets (25,90 Gio), soit exactement le volume annonce. Les empreintes
des trois fichiers de manifeste sont correctes. Ce controle de presence et volume
ne remplace pas des empreintes individuelles source ni une analyse antivirus.

- 3 fichiers vides sont conserves mais ne sont pas proposes au telechargement.
- 34 fichiers collectes n'ont pas de reference rattachee a un dossier dans le dump
  actuel ; ils sont conserves sans inventer de correspondance.
- 16 981 references documentaires du snapshot ont un fichier disponible, pour
  3 362 dossiers. Plusieurs references peuvent partager le meme fichier ; le
  manifeste et le dump ont ete pris a des heures differentes.
- Les 17 extensions refusees et 16 fichiers absents/illisibles signales lors de
  la collecte initiale ne font pas partie des 16 897 chemins attendus.

`CollectedDossierDocuments` utilise `legacy_records` pour le rattachement exact
et le manifeste NUL-delimite comme liste autorisee. Pas de nouvelle table, de copie
dans `dossier_documents`, ni d'appel HTTP. Les documents sont disponibles dans
le detail du dossier et dans les listes documentaires de prise de RDV et du planning.
Le telechargement exige les droits existants sur le dossier. Les techniciens et
partenaires restent exclus de cet acces historique tant que leurs perimetres ne
sont pas repris. Les chemins traversants, liens symboliques, extensions executables,
fichiers absents ou vides sont refuses ; les fichiers sont servis en piece jointe,
sans cache public, et non executes ou rendus comme HTML.

Sur ce Mac, `compose.override.yaml` (ignore par Git) monte en lecture seule :

- `~/Downloads/coffrac-migration-20261014/fichiers/public/documents` vers
  `/var/www/html/storage/app/private/coffrac-documents` ;
- `~/Downloads/coffrac-migration-20261014/selection-non-factures-20261014` vers
  `/var/www/html/storage/app/private/coffrac-documents-selection`.

Ne pas supprimer ni renommer ces repertoires tant que ces montages sont utilises.
Le reset de simulation ne touche pas aux fichiers ; le snapshot retablit leurs
metadonnees et les liens fonctionnent a nouveau sans import additionnel.

Sur un autre environnement, conserver le stockage hors `public` et definir si besoin :

```dotenv
CRM_COLLECTED_DOCUMENTS_ROOT=/chemin/prive/documents
CRM_COLLECTED_DOCUMENTS_MANIFEST=/chemin/prive/selection/documents.list0
```

Pour preparer le futur transfert, inclure explicitement ces deux repertoires
source et la base V2 : les dossiers de montage cote Mac dans `storage` sont vides.
Un zip du projet seul ou le dump SQL seul ne contient pas les fichiers. Aucun
transfert vers le CT ni changement sur Coffrac n'a ete effectue a cette etape.
Ces archives ne deviennent pas automatiquement des rapports RT valides.

## Correspondances et nettoyage

- Utilisateurs : email normalise exact, uniquement s'il est non ambigu. Les
  techniciens historiques absents sont crees sans droit admin ni mot de passe connu.
- Prestations : les 57 definitions `fiche_type` Coffrac deviennent le catalogue natif,
  avec un identifiant source unique. Les operations et RDV importes sont relies via
  l'identifiant de la fiche source, sans rapprochement approximatif des libelles.
  Les durees et habilitations des prestations prealablement liees sont conservees.
  Une duree de 60 minutes sert de valeur initiale lorsqu'aucune duree n'existe ;
  elle doit etre verifiee avant utilisation. Les anciens services/alias restent en
  base pour les references historiques, mais ne sont plus proposes dans les formulaires.
- Delegataires : identifiant source Coffrac, pas de rapprochement approximatif.
- Dossiers : reference native `CFR-ID`. La reference d'origine, le nom du lot et
  le libelle beneficiaire sont conserves separement. Les references historiques
  homonymes ne sont pas fusionnees. Un nom non structure n'est pas devine comme
  raison sociale ou decoupe arbitrairement dans la fiche beneficiaire.
- RDV : le dernier identifiant de RDV par fiche est le courant, et non la date
  la plus lointaine. Seuls les statuts source 2/10 et dossiers non supprimes sont
  gardes actifs pour calculer les disponibilites. La page Les RDV affiche aussi
  l'historique importe, passe et futur, en calendrier ou en liste paginee. Les
  anciens RDV sont identifies comme historiques et restent en lecture seule.
- Technicien historique : l'export donne le technicien actuel du dossier, pas celui
  de chaque ancienne visite. Cette limite est indiquee dans le detail.
- Date/heure de RDV : heure locale Europe/Paris ; microsecondes MySQL acceptees.
  Horodatages SQL : conversion explicite depuis UTC.
- GPS absent : reste NULL. Pas de point 0,0 ni de calcul de trajet invente. Pas de
  proposition automatique sur une journee comportant un RDV sans coordonnees.
- Codes postaux invalides et valeurs hors schema : conserves dans l'archive et
  signales, pas corriges au hasard.
- Anciens templates Coffrac mal encodes : octets conserves en base64 avec charset ;
  aucun PHP/HTML historique execute ni transforme en template actif.

Le rapport complet est en `simulation_snapshots.report` : effectifs, anomalies
avec identifiants source, correspondances, empreinte du dump. Une copie privee
peut etre exportee pour poursuivre les rapprochements.

Pour mettre a niveau le catalogue d'une simulation existante sans reinitialiser
ses donnees, appliquer les migrations puis lancer `php artisan crm:reconcile-services`
dans le conteneur applicatif. Cette commande utilise uniquement le snapshot local
et preserve les corrections natives lors des relances. Le seed l'appelle aussi.

## Preparation hors ligne

Le dump SQL ne doit jamais etre execute sur une base applicative. Le preparateur
utilise un MySQL jetable sans reseau, sans port expose ni montage de fichiers hote,
avec un utilisateur limite a une seule base. Il detruit ce conteneur a la fin.

```bash
bash tools/fixtures/prepare-coffrac-snapshot.sh /chemin/coffrac.sql.gz /nouveau/repertoire/coffrac
```

Il faut l'image Docker `mysql:8.4` deja presente, PHP local et un repertoire parent
existant. Le dossier de sortie ne doit pas exister. `manifest.json` n'est ecrit
qu'apres validation de toutes les tables assainies.

Le jeu utilise par le seed contient `references.json.gz`, `matching.json` (objets
`users` et `services`, identifiant source vers identifiant TechCalendar explicite),
`coffrac/manifest.json`, les JSONL Coffrac et `dataset.json` portant les SHA256 des
trois premiers fichiers. Modifier les rapprochements necessite de recalculer
l'empreinte de `matching.json` dans `dataset.json`, puis de relancer le reset.
Ne jamais publier ce jeu, meme sans mots de passe : il contient des donnees personnelles.

## Perimetre restant

Ce seed fournit une simulation reinitialisable, pas une bascule de production.
Les ecrans de rapport, facturation, litiges, permissions historiques et la reprise
des fichiers doivent encore etre finalises. Les archives conservent ces donnees
pour poursuivre la reconstruction sans perte ni dedoublement de dossiers.
