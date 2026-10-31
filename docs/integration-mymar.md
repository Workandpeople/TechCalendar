# Preparation de l'integration MYMAR

Etat au 31 octobre 2026 : passerelle privee et affichage pilote actifs en
lecture seule, aucune ecriture de RDV MYMAR et aucun blocage des disponibilites MAR.
Aucune reprise du CRM MYMAR dans Cofrac. Le premier audit du 29 octobre a
identifie les tables utiles et les risques des endpoints existants.

## Extension demandee : prise de RDV MAR depuis Cofrac

Le besoin valide le 31 octobre depasse l'observation des occupations :

- Afficher les RDV MAR existants dans les plannings Cofrac (pilote deja visible).
- Trois onglets de source : RDV Cofrac, RDV lots, RDV MAR.
- Lire les demandes a placer dans MYMAR, sans les transformer en dossiers Cofrac.
- Choisir un technicien lie, une date et une heure avec les controles habituels.
- Apres confirmation MYMAR, afficher le placement et son statut attente visite.
- Declarer un probleme RDV dans MYMAR depuis le meme parcours de prise de RDV.

Les trois onglets sont prepares dans le code. Pour l'instant l'onglet MAR
explique explicitement que le placement n'est pas active ; il ne montre pas
une fausse liste vide et ne fait aucun appel reseau. La source selectionnee
reste memorisee et les parcours Cofrac/lots ne changent pas. Ce code n'a pas
encore ete deploye. Il n'existe pas encore de mutateur prive actif.

### Contrat d'ecriture a verifier avant activation

MYMAR reste l'unique autorite pour ses dossiers et rendez-vous. Une visite
initiale et une visite apres travaux ne doivent pas etre confondues : les
categories observees sont respectivement 1/2/3 et 14/15/16 (prise/probleme/
attente visite). Le releve statique du 30 octobre confirme que l'ancien
`ApiController::ajouterRDV` impose **3**, sans distinction de phase. Il archive
les anciens RDV avec `is_old=1`. L'utiliser tel quel pour l'apres-travaux
enverrait les dossiers dans la mauvaise categorie. Aucune ancienne route
API d'ecriture ne sera appelee par le nouveau parcours.

Le paquet d'audit `tools/deploy/mymar/workflow` relit seulement les methodes
de mutation, les modeles/hooks et les metadonnees de contraintes/triggers.
Il n'inclut/evalue jamais MYMAR, ne lit ni configuration ni lignes metier,
ne change ni le compte SQL SELECT ni la passerelle existante. Les constantes
metier sont extraites separement des extraits masques : leurs nombres `0`
ne doivent pas etre interpretes comme les valeurs d'origine.

Conditions du futur mutateur, non satisfaites par la seule observation :

- Interface loopback authentifiee avec droits d'ecriture separes de la lecture.
- Permissions internes planning/gerant, liaison technicien valide et active.
- Revision source attendue, verrouillage du dossier et des RDV courants.
- Cle d'idempotence avec refus de reutilisation pour un autre contenu ; en cas
  de timeout, relire le resultat de cette cle, ne pas creer un second RDV.
- Transaction MYMAR pour RDV, statut, archivage et historique. Les regles
  d'archivage entre visite initiale et apres travaux doivent etre confirmees.
- Auteur d'integration explicite et acteur Cofrac tracable ; ne jamais faire
  passer un ID utilisateur Cofrac pour un ID utilisateur MYMAR.
- Dates Europe/Paris avec decalage explicite, duree normalisee et conversion
  vers le format MYMAR, sans reutiliser les exports qui font `addHour()`.
- Pas de succes affiche avant confirmation ; erreurs/concurrence explicites.
- Les RDV MAR occupent les disponibilites ; une position absente/interpretee
  ne doit jamais autoriser le rapprochement <1 km ou un trajet invente.
- Cache invalide apres ecriture confirmee, mais aucun appel distant pendant
  le rendu habituel des pages. Notifications et effets secondaires verifies.

La couverture actuelle reste celle des quatre techniciens lies du pilote,
pas l'ensemble des techniciens MYMAR. L'absence de chevauchement observee
sur les 96 RDV Cofrac controles n'autorise pas a elargir cette couverture.

### Audit de workflows termine le 31 octobre a 08:48 UTC

Les trois empreintes du rapport sont verifiees : 15 fichiers sources stables,
metadonnees completes et aucune ecriture/route appelee. Les transitions de
probleme concernent bien 2 et 15. Les modeles de dossier, RDV et historique
collectes ne declarent pas de hooks de mutation ; les notifications partenaires
et mandataires sont appelees explicitement par `changeStatusPost`. Il ne faut
donc pas appeler ce controleur pour une integration sans envoi implicite.

Les trois tables a modifier sont InnoDB. Le RDV exige un `author_id` MYMAR
existant (cle etrangere vers users) ; le compte Cofrac ne peut pas servir
directement d'auteur. Le choix confirme est un compte technique MYMAR dedie,
desactive et sans role. L'acteur Cofrac et l'UUID de commande restent traces
dans l'historique et dans le journal technique prive.

### Noyau de commande et preparation fermee

La preparation fermee a ete executee avec succes le 31 octobre a 09:55 UTC :
compte technique cree/desactive sans role, nouveau compte SQL verrouille,
journal vide et quatre liens reverifies. Aucun dossier, RDV, ancien compte,
historique ni service modifie. Une seule demande a ete sondee en READ ONLY,
sans exporter ses donnees. Ce succes n'active pas les actions de placement.

Le code `tools/deploy/mymar/booking` est un backend autonome, non installe.
Il n'est pas ajoute au point d'entree d'occupation existant et aucune route
Laravel ou tache ne l'appelle. Le DTO `MymarBookingCommand` valide des entrees
scalaires strictes ; pas d'auteur MYMAR, statut cible, SQL ou forcement arbitraire.
Les ecritures sont desactivees independamment de la lecture, y compris pour
les tests de preparation. Durees entieres en heures : un choix de 45/90 minutes
est refuse, pas arrondi. Les dates sont explicites UTC ou Europe/Paris ; un
mauvais decalage saisonnier ou une heure locale ambigue/non stockable est refuse.

Le moteur implemente :

- Demandes paginees par ID, deux requetes de donnees par page, pas de N+1.
- Revision incluant dossier, beneficiaire et tous les RDV courants : une
  modification intervenue depuis la selection provoque un conflit.
- Transaction unique pour journal, archivage, nouveau RDV, statut et historique.
- Rejeu d'un UUID confirme donnant le meme resultat, sans nouvelle ecriture ;
  contenu ou acteur different pour cet UUID refuse. Lookup du resultat apres
  timeout. Une reponse incertaine ne pretend jamais que l'action a echoue.
- Sous-statut, commentaire texte et rappel pour probleme, sans detruire les RDV.
- Controle des vrais chevauchements MYMAR, dates/durees inconnues bloquantes.
- Verrou nomme par technicien pour serialiser les commandes du pont, verrous
  partages sur les profils et beneficiaires, exclusifs sur le dossier et RDV.
  Aucun UPDATE sur users/clients et aucun droit TRIGGER pour obtenir un verrou.

### Suivi durable cote Cofrac, prepare mais non branche

`MymarBookingClient` utilise un endpoint loopback distinct (port reserve 19847,
pas la passerelle d'occupations 19846), un autre jeton, des delais courts,
sans proxy/redirection/cookies ni nouvelle tentative automatique de POST.
Lecture des demandes par pages de 1 a 100 avec curseur monotone, contrats/types
stricts et limite de reponse. Une erreur n'est pas presentee comme une liste
vide. Les beneficiaires/adresses restent du texte a echapper au rendu.

`MymarBookingOutbox` conserve dans la base Cofrac une commande chiffree et
son empreinte, auteur authentifie et identifiant source. Le meme UUID ne peut
pas designer un autre contenu/auteur ; une seule action non resolue par dossier.
Le bail de traitement est court et les retours d'un ancien worker ne peuvent
pas ecraser un resultat plus recent. La transaction locale est fermee avant HTTP.

Un resultat confirme exige une reponse source apres COMMIT, meme UUID/dossier/
action et memes heure/duree. Apres timeout, reponse invalide, 5xx ou abandon
d'un worker, la demande reste incertaine et verrouille ce dossier. La reprise
fait uniquement GET du recu avec le meme UUID : ni nouveau POST ni nouvel UUID.
Un recu absent ne prouve pas l'echec. Les droits de l'auteur sont reverifies
avant le premier POST, sans empecher la reconciliation d'une action deja partie.

Ces services sont DESACTIVES par defaut, sans route, job, scheduler ni binding
de production. La nouvelle migration du journal natif est reservee au futur
lot de branchement ; elle n'est pas dans le paquet du correctif de dates.
Elle n'est volontairement pas ajoutee aux migrations autorisees du deploiement
actuel. Les controles de creneaux/absences/trajets et la reservation Cofrac
restent a brancher avant toute activation des ecritures. Aucun blocage MAR
supplementaire ni changement d'occupation n'est revendique par ce lot.

### Lecteur HTTP des demandes, lot serveur separe

`tools/deploy/mymar/booking-http` installe un lecteur sur 127.0.0.1:19847,
avec deux NOUVEAUX services, un compte Linux sans shell et un NOUVEAU compte
SQL `cofrac_mymar_br_*` limite strictement a SELECT par colonnes. Le compte
de placement prepare `cofrac_mymar_rw_*` reste verrouille ; le compte SQL
et les services d'occupations 19846 ne changent pas. Aucun deploiement,
maintenance, migration ou rechargement des applications/Apache public.

La lecture des demandes et des recus est authentifiee. Les POST sont refuses
avant ouverture de connexion SQL ; meme une configuration writes_enabled=true
rend le lecteur indisponible au lieu de l'activer. Un profil SQL trop large est
refuse. L'installation fait des controles HTTP et valide une page d'au plus
une demande sans exporter ses donnees. Le rapport ne contient que des compteurs
et indicateurs. Aucun jeton/secret n'est encore donne au runtime Cofrac.

Une mauvaise release est refusee avant creation du compte SQL/systeme. En cas
d'echec de demarrage, seuls les nouveaux services sont arretes, le lecteur SQL
est verrouille et sa configuration desactivee. L'arret fonctionne independamment
de la validite des anciennes demandes d'installation. Aucun DROP automatique.

Tests locaux : Apache/FPM/MySQL reels dans des conteneurs sans reseau externe,
permissions SELECT, refus des ecritures/passwords, commandes HTTP malformees,
secret non exporte, configuration forcee en ecriture refusee, reexecution refusee,
arret et echec de demarrage. Checksums des tables metier et fichiers applicatifs
inchanges ; ancienne API d'occupations toujours accessible.

```sh
MYMAR_BOOKING_TEST=1 MYMAR_BOOKING_HTTP_TEST=1 bash tools/deploy/tests/check-mymar-booking.sh
MYMAR_BOOKING_TEST=1 MYMAR_BOOKING_HTTP_TEST=1 MYMAR_BOOKING_READER_FAILSTART=1 bash tools/deploy/tests/check-mymar-booking.sh
```

Ce lot ne branche pas encore les onglets, le cache des demandes ou l'outbox.
Il ne modifie ni disponibilites ni reservations et ne permet aucun placement.

### Journal source et activation future

Le journal source vit dans un NOUVEAU schema `cofrac_mymar_integration`, sur la meme
instance MySQL pour rester dans la meme transaction. La nouvelle connexion
utilise des privileges par colonnes verifies exactement. Pas de DELETE, DDL,
mot de passe, role modifie ou GRANT OPTION pour le compte runtime. L'absence
de triggers est verifiee par le preparateur privilegie, pas par une lecture
incomplete de information_schema avec le compte runtime restreint.

Le paquet operateur `prepare-mymar-booking-backend.php` ne deploie pas Cofrac :
il cree seulement le journal neuf, un compte SQL ferme et le compte technique
explicitement approuve (adresse reservee .invalid, secret aleatoire inconnu,
enabled=0, aucun role). Il ouvre une seule connexion pour lire au plus un
dossier, reverrouille les nouveaux logins avant cette lecture et verifie que
la porte d'ecriture refuse toute action. Le journal doit rester vide. Aucun
beneficiaire, email, token ou mot de passe ne sort dans le rapport de preparation.
Configuration root-privee avec `read_enabled=false` ET `writes_enabled=false`.

Tests locaux sur MySQL 8.0.46, conteneurs sans reseau ni montage hote writable :
les deux phases, heures Paris, droits minimaux reels, source modifiee, refus
de technicien inactif/hors scope, chevauchements, rappel, idempotence, rejet
d'une commande concurrente et rollback complet quand l'historique echoue.
Le preparateur est aussi execute de bout en bout, puis relance refusee sans
ressource double. Sources, visites/dossiers et comptes existants inchanges.

Restent avant activation : installation HTTP privee d'ecriture et branchement
du client/outbox prepares, reservations Cofrac, disponibilites
natives/absences/trajets, branchement des onglets et droits planning/gerant,
reconciliation du cache et validation finale du parcours. Le moteur source
seul n'autorise PAS un creneau Cofrac et n'evalue PAS les trajets. Ses verrous
ne rendent pas transactionnelles les anciennes actions MYMAR qui n'utilisent
pas ce protocole ; aucun ancien controleur n'a ete modifie.

## Deuxieme collecte

Le paquet autonome `tools/deploy/mymar` prepare une collecte statique et SQL
en lecture seule, a lancer explicitement par l'operateur. Il ne s'installe pas
dans les applications et ne charge aucun de leurs fichiers PHP.

Les schemas sont fixes : MYMAR et cofrac2production sur le socket MySQL local.
Le collecteur verifie les declarations de connexion, les caches litteraux
eventuels, les tables InnoDB, les colonnes attendues et des limites de volume.
Une configuration sans cache reste une declaration a confirmer, pas une preuve
de la configuration effective des processus MYMAR.

La collecte contient les references de statuts/roles, des statistiques de RDV
groupees et une revue des correspondances de techniciens. Les emails ne sortent
pas de MySQL : seules les concordances sont exportees. Aucun mot de passe,
contact beneficiaire, adresse ou document n'est collecte. Les requetes et les
erreurs SQL restent root-privees. Le rapport final reste prive et provisoire.

Les SELECT sont bornes a 1500 ms, chaque session a 20 s, avec transactions
READ ONLY. Les limites ne rendent pas la lecture gratuite : aucune collecte
en boucle ni synchronisation recurrente n'est installee. Le compte de maintenance
est reserve a cet audit ponctuel ; il ne sera pas utilise par le connecteur.

### Resultat du 30 octobre a 09:48 UTC

Rapport local verifie par SHA-256 : collecte statique complete sur les 11
fichiers cibles, fichiers sources inchanges. La partie SQL n'a pas ete lancee :
la configuration en cache MYMAR n'est pas decodable par le parseur litteral.
Ce refus ne demontre ni une panne MYMAR ni une erreur de sa configuration.
La declaration et le cache de connexion Cofrac sont compatibles avec la cible.

Les routes API en cache confirment le middleware `api` seul pour les anciennes
routes de planning et les mutateurs ; `/api/user` porte `auth:sanctum`.
Les vhosts MYMAR collectes ont `Require all granted`. Ce constat statique
ne prouve pas l'exposition effective ni l'absence de toute autre protection,
mais interdit de reutiliser ces routes comme interface privee authentifiee.
Aucune route n'a ete appelee ou testee.

Les appels du code confirment `status_id IN (3)` et `is_old = 0` dans les
anciennes lectures API ; le role recherche est `2` / `Technicien Terrain`.
Le cron utilise les statuts `2` et `15`, puis attribue `1`. Les libelles des
statuts et la couverture des occupations restent a verifier, pas a deviner.
Les exports planning appellent `addHour()` et dedupliquent par `dossier_id`.
Le fichier source `config/app.php` contient `timezone => UTC` ; cela ne prouve
pas encore le fuseau effectif en cache ni celui des dates stockees.

La prochaine collecte `tools/deploy/mymar/cache-diagnostic` ne lit que le cache
MYMAR comme du texte. Elle exporte taille, ligne et types de tokens du refus,
jamais ses valeurs ou extraits. Aucun SQL et aucun assouplissement du parseur.
Elle ne contient aucun correctif/deploiement MYMAR ou Cofrac.

### Diagnostic du cache du 30 octobre a 10:03 UTC

Le cache est un fichier regulier stable de 29 737 octets. Le parseur strict
refuse la ligne 692 sur une expression `T_NAME_FULLY_QUALIFIED :: T_STRING (`
suivie d'un tableau. Ce motif est compatible avec un objet exporte ; le nom
de la classe et de la methode n'ont pas ete collectes, donc ne sont pas deduits
du diagnostic. Aucun SQL n'a ete execute pendant cette verification.

Le collecteur peut desormais reconnaitre exclusivement la forme
`\\Classe::__set_state(tableau litteral)` et la remplacer par un marqueur
opaque interne, sans charger la classe ni appeler sa methode. Les etats de
ces objets sont jetes, pas deserialises ou exportes. Les fonctions, fabriques
statiques autres que `__set_state`, constructeurs et arguments non litteraux
restent refuses. Le decodage des routes reste strictement litteral.

Un objet dans `database` ou dans `app.timezone` interdit toujours le SQL.
Les objets ailleurs peuvent etre ignores et sont comptes dans le rapport.
Une compatibilite statique n'est pas une preuve du comportement runtime des
objets ou de la configuration des processus MYMAR. Le connecteur reste inactif.

### Collecte du 30 octobre a 10:13 UTC

Le cache MYMAR est maintenant lu statiquement : un objet hors configuration
SQL/fuseau a ete ignore, les declarations de connexion correspondent aux deux
schemas attendus. Le cache indique UTC pour MYMAR et Europe/Paris pour Cofrac.
Cette difference ne suffit pas a conclure sur le fuseau des dates stockees.

La collecte retourne `sql_eligible=true`, mais `sql_complete=false` ; le resume
initial ne distingue pas erreur SQL, validation de metadonnees ou timeout.
Aucun resultat metier n'a ete publie et les fichiers sources sont inchanges.
Il serait incorrect d'assouplir un controle ou de relancer la collecte sans
examiner les diagnostics deja produits.

Le paquet `tools/deploy/mymar/sql-diagnostic` retrouve uniquement ce run termine
par l'empreinte exacte du rapport source (fournie dans le paquet operateur).
Il relit ses fichiers prives, sans SQL ni lecture des applications, et publie
les codes SQL/SQLSTATE, l'etape atteinte et les ecarts de schema. Aucun message
brut, valeur de configuration ou nom de compte ne sort du diagnostic.
Les codes de sortie n'etaient pas traces : leur absence n'est pas une reussite.

### Diagnostic SQL du 30 octobre a 10:26 UTC

Le schema et les metadonnees passent. MySQL `8.0.46` interrompt la requete
techniciens (ligne 14 de l'ancien `data.sql`) avec `3024 / HY000` : limite
d'execution de 1500 ms atteinte. Les lectures precedentes ont termine, mais
le resultat partiel n'a pas ete publie comme audit complet.

La requete utilisait `WHERE id IN (SELECT ... UNION ...)` et un `COUNT` correle
par utilisateur. Le plan local confirme des sous-requetes/UNION dependants.
Le collecteur calcule maintenant les IDs candidats, doublons historiques et
cles email dans des ensembles dedupliques, puis fait les jointures. Aucune
ecriture, aucun index, aucun changement de serveur ni hausse du timeout.

Le collecteur trace desormais les codes de sortie par etape et les codes
MySQL/SQLSTATE dans `summary.json`, sans message brut ni valeur personnelle.
Un resultat interrompu n'est jamais promu en audit complet ou en liaison active.

Validation locale sur MySQL 8.0.46, conteneur sans reseau/ports/montages hote :
256 comptes MYMAR, 293 V2, 5063 dossiers, 8358 RDV synthetiques. Resultats
identiques a la requete precedente dans les populations dense (255 candidats)
et clairsemee (21 candidats). Aucun `DEPENDENT` dans le plan optimise, limite
1500 ms conservee et checksums des tables identiques avant/apres les lectures.
Le test d'echec 3024 confirme que le resume contient le code et l'etape, sans
erreur brute et sans publication du resultat partiel. Ces mesures locales
ne sont pas une garantie de temps d'execution sur le serveur de production.

## Conditions avant implementation

### Audit complet du 30 octobre a 11:07 UTC

Toutes les etapes SQL passent avec la limite de 1500 ms : metadonnees,
validation, donnees et EXPLAIN. Les quatre empreintes des fichiers du rapport
ont ete verifiees. Les 11 fichiers sources correspondent aussi a ceux du
premier audit du 29 octobre ; les conclusions statiques restent applicables.
Aucun compte, mot de passe, application, configuration ou service modifie.

Le comptage reel donne 12 254 RDV, 287 comptes MYMAR, 293 comptes Cofrac,
31 statuts et 7 roles. Les anciennes estimations InnoDB (8358 RDV, 256 comptes
MYMAR) etaient des statistiques de table, pas des comptages exacts.

Le rapport regroupe 68 personnes candidates : 43 profils Technicien Terrain
(41 actifs MYMAR) et 25 profils Auditeur (24 actifs MYMAR). Ce ne sont donc
pas 68 techniciens a relier automatiquement. Pour les seuls techniciens terrain,
10 correspondances satisfont les criteres conservateurs : lien historique
unique, email exact concordant et unique, meme cible, role technicien interne
Cofrac, cible active et source active. Cela reste une proposition de lien,
pas une validation metier ni une modification de compte.

Des comptes terrain MYMAR restent actifs alors que leur cible Cofrac est
desactivee ; les choix Cofrac sont conserves. Un ID historique est partage
par deux profils source. Quatre profils ont un email different de celui de
leur cible historique. Les cas particuliers et noms restent dans la revue
privee locale, jamais dans le depot ou un paquet de deploiement.

Points de couverture et de temps :

- Le statut `3` est bien RDV ATTENTE VISITE, mais aucun RDV de ce snapshot
  n'est rattache a un dossier actuellement dans cet etat.
- Le statut `16` est RDV APT ATTENTE et contient 9 RDV `is_old=0`, dates
  du 30 octobre au 10 novembre. Le filtre historique `status_id=3` les exclut.
  Ils ont `mar_type=0` : ne pas confondre cette colonne avec la phase APT.
- `is_old=1` represente 6489 lignes ; 5765 sont `is_old=0`. Plusieurs lignes
  non anciennes peuvent appartenir au meme dossier : ne pas dedupliquer par
  dossier ni assimiler `is_old=0` a un rendez-vous actif a lui seul.
- Les 12 254 lignes ont une duree strictement positive, minimum 1 et maximum 2.
  L'unite n'est pas prouvee par ce nombre : ne pas convertir implicitement
  en minutes/heures ni remplacer toutes les durees par une heure.
- Les caches indiquent UTC pour MYMAR et Europe/Paris pour Cofrac, MySQL
  utilise SYSTEM/UTC. Un DATETIME ne contient pas de fuseau. Le code audite
  assemble aussi directement date/heure recues : le stockage horaire reste
  a comparer avec un rendez-vous connu avant d'appliquer une conversion.
- Des groupes de RDV portent encore des dates futures alors que le dossier
  est en DEPOT FINI. Une date future ou `is_old=0` ne suffit donc pas a prouver
  une occupation. Les etats bloquants doivent etre explicitement valides.
- Aucun dossier orphelin detecte dans les groupes ; 4 lignes n'ont pas de
  technicien de dossier. Ne pas les attribuer a l'auditeur par defaut.
- Le plan EXPLAIN des occupations parcourt la table RDV ; seul l'index
  dossier apparait comme candidat. Aucun index n'a ete ajoute. Avant une
  synchronisation recurrente, revoir le plan de la requete definitive et
  le cout de la fenetre temporelle choisie.

La prochaine etape est une integration en observation : lectures privees
authentifiees et simulation du rapprochement/occupations, sans modifier les
suggestions ni bloquer un placement tant que durees, horaires, etats et liens
ne sont pas valides. Ne pas ouvrir les anciennes routes API pour cette etape.

- Identifier les etats qui occupent le planning, y compris les visites initiales
  et apres travaux, les annulations et les remplacements. Ne pas supposer que
  `is_old=0` ou un statut unique couvre tout le besoin.
- Confirmer le fuseau des datetime et l'unite de `duree`. Une duree absente ne
  devient pas automatiquement 60 minutes. La politique metier reste a valider.
- Revoir le lien `MYMAR.users.cofrac_id` vers l'ancien ID Cofrac puis
  `coffrac_user_links.user_id`. Un email concordant unique est une preuve
  complementaire, pas une autorisation de fusionner/creer des comptes.
- Confirmer le confinement effectif et l'authentification. L'audit statique de
  routes/vhosts ne prouve pas leur exposition reseau. Aucun mutateur ne sera sonde.

### Calibration ciblee des horaires et durees

La consultation admin en lecture seule a confirme une reference exploitable :
le dossier MYMAR `91` affichait le 2 novembre 2026 a 09:30 avec le technicien
source `26`. Ce numero de dossier n'est pas l'identifiant du rendez-vous.
Le formulaire consulte ne permettait pas de confirmer l'unite de duree ;
le calendrier presentait aussi une erreur JavaScript. Aucun correctif MYMAR
n'a ete applique et aucun formulaire n'a ete enregistre.

Le paquet `tools/deploy/mymar/calibration/` prepare la comparaison suivante :

- Lecture statique des controles de duree et des appels temporels du calendrier
  et de ses dependances locales, limitee a 64 fichiers et 8 Mio. Aucun fichier
  applicatif ou cache PHP n'est execute et aucun code brut n'est exporte.
- Verification du schema, des types et des index avant une requete restreinte
  au dossier `91`, `is_old=0`. Le SELECT est limite a 1500 ms et la session
  a 20 secondes, dans une transaction READ ONLY. Aucun index n'est ajoute.
- Publication des seuls IDs techniques, date brute, duree brute et etat.
  Les noms, coordonnees, documents et secrets ne sont pas selectionnes.
- Une reference absente, multiple ou changee n'est jamais choisie arbitrairement.
  Une concordance entre heure stockee et heure affichee ne prouve pas a elle
  seule le fuseau historique. Aucune conversion de duree/date n'est appliquee.
- Les empreintes des sources selectionnees sont relues avant publication.
  Un echec SQL ou une modification concurrente interdit la publication de la
  reference. Les fichiers SQL et erreurs brutes restent root-prives.

Cette collecte ponctuelle utilise le compte local de maintenance deja present.
Il ne sera pas reutilise pour le connecteur. Le paquet ne cree aucun compte,
ne change aucune configuration et ne demarre/recharge aucun service. Les seuls
fichiers crees sont ses paquets et rapports prives, hors des applications.
Le resultat conserve toujours `connector_enabled=false` et
`integration_ready=false` : il doit etre analyse avant toute implementation.

Validation locale : 48 tests unitaires d'audit/calibration (207 assertions),
plus un test MySQL 8.0.46 avec compte SELECT seul et un test du pipeline shell
en conteneurs jetables sans reseau. Les fixtures couvrent les anciennes lignes,
les autres dossiers, l'echec SQL, les sources modifiees et l'absence d'export
de donnees sensibles. Aucune connexion au serveur reel pendant ces tests.

### Resultat de calibration et hypothese retenue

Rapport du 30 octobre a 15:47 UTC, empreintes verifiees : 28 fichiers sources,
569 399 octets lus, sources inchangees et trois etapes SQL reussies.
Le dossier de reference `91` porte un seul RDV non ancien `12317`, statut `16`,
technicien source `26`, debut brut `2026-11-02 09:30:00`, duree brute `1`.
L'heure correspond a celle vue dans MYMAR. Cela ne prouve pas a lui seul le
fuseau de tous les enregistrements historiques. Aucun controle numerique de
duree n'a ete extrait des formulaires Vue ; leur absence du rapport ne signifie
pas que l'interface MYMAR n'a pas de champ de duree.

L'operateur retient provisoirement **1 = 1 heure et 2 = 2 heures**, faute de
reponse metier. La preparation continue avec cette hypothese explicite, sans
la presenter comme confirmee. Les heures sans fuseau sont interpretees comme
des heures locales Europe/Paris pour cette observation, egalement a titre
provisoire, plutot que de leur appliquer silencieusement un decalage UTC.

## Observation hors ligne implementee

`App\Services\Mymar\MymarObservation` interprete le contrat source propose,
avec une politique explicite `MymarObservationPolicy`. Il n'appelle ni SQL,
ni HTTP, ni service Laravel, ne cree aucun dossier/RDV/compte et ne change
aucun planning. Aucun controleur, job, ordonnanceur, migration, variable
d'environnement ou service de production n'est branche sur cette classe.

- IDs stables par RDV MYMAR, pas par dossier : deux RDV du meme dossier ne
  sont pas fusionnes. Les IDs RDV dupliques sont refuses.
- Fenetre exigeant un fuseau, limitee a 31 jours, 200 techniciens et 2000
  lignes maximum. Les statuts et techniciens hors perimetre sont refuses.
- Statuts source `3` et `16` prepares respectivement pour les visites initiales
  et apres travaux. `mar_type` n'est pas utilise pour deviner cette phase.
  La couverture des autres etats reste a valider avant activation.
- Durees positives bornees et convertibles en minutes entieres ; aucune duree
  par defaut pour les valeurs absentes, invalides ou non numeriques.
- Heures locales invalides, inexistantes ou ambigues au changement d'heure
  signalees explicitement ; aucune normalisation automatique d'un horaire faux.
  Les durees s'ajoutent en minutes ecoulees, pas en heures locales variables.
- Lignes anciennes et intervalles sans intersection avec la fenetre exclus,
  avec semantique `[debut, fin[`. Aucun contenu personnel libre accepte.
- Rapprochements techniciens uniquement fournis explicitement par l'operateur,
  sans recherche de nom/email, fusion, creation ou reactivation de compte.
  Deux sources vers le meme compte cible sont refusees. La validation runtime
  de l'activite et du role du compte cible n'est pas encore implementee.
- Snapshot partiel, perime ou date dans le futur : anomalie visible, jamais
  interpretee comme un ensemble de creneaux libres. Un technicien absent ou
  non rapproche laisse une occupation candidate non attribuee et une anomalie.
- Aucun GPS deduit : precision `unknown`, donc pas de preuve de co-localisation
  ou de calcul routier fiable a ce stade.

Tous les rapports portent `connector_enabled=false`, `planning_ready=false`
et `can_affect_planning=false`, meme si les controles structurels passent.
`quality_checks_passed` n'est jamais une autorisation d'import ou de placement.
La duree reste `duration_estimated=true` tant que son unite est supposee.

### Relecture locale d'une calibration deja collectee

Le script `tools/deploy/mymar/observation/observe-calibration.php` verifie le
manifest et les trois empreintes, puis produit un nouveau JSON prive en 0600
dans un repertoire 0700 hors des applications. Il fonctionne avec `php -n` :
aucun autoload ni bootstrap Laravel, aucune connexion SQL/HTTP. Il refuse les
fichiers indirects, les entrees corrompues et l'ecrasement d'une sortie existante.
Ce script n'est pas une collecte exhaustive de MYMAR ni un endpoint API.

Le test local sur le rapport reel donne une occupation candidate le 2 novembre,
**09:30-10:30 Europe/Paris**, soit **08:30-09:30 UTC**, duree estimee 60 minutes.
L'absence de rapprochement approuve pour le technicien source `26` est conservee.
Le rapport est aussi marque partiel/perime : une calibration d'un seul dossier
ne prouve jamais qu'une fenetre de planning est completement couverte.

Validation : 34 tests unitaires de normalisation (106 assertions), plus un
test CLI hors ligne couvrant conversion, permissions privees, corruption,
symlink de manifest et refus d'ecrasement. Les tests ne contactent aucun serveur.

## Contrat cible a valider

Cette section decrit une **cible non deployee**, pas une API deja disponible.
Le normaliseur precedent est pret pour des snapshots de cette forme :

```json
{
  "format": "cofrac.mymar-occupations-source",
  "version": 1,
  "generated_at": "2026-10-30T15:47:52Z",
  "complete": true,
  "source_timezone": "Europe/Paris",
  "duration_unit": "hours",
  "window_start": "2026-11-01T23:00:00Z",
  "window_end": "2026-11-08T23:00:00Z",
  "technician_ids": [26],
  "status_ids": [3, 16],
  "appointments": [{
    "rdv_id": 777,
    "dossier_id": 91,
    "technician_id": 26,
    "status_id": 16,
    "is_old": 0,
    "stored_start": "2026-11-02 09:30:00",
    "raw_duration": 1
  }]
}
```

L'API source devra garantir l'exhaustivite du perimetre declare avant de poser
`complete=true`. Les dates brutes resteront accompagnees d'une interpretation
explicite ; la normalisation UTC et la confiance sont produites par l'adaptateur.

Deux lectures privees, versionnees et authentifiees : techniciens de reference
et occupations pour une fenetre bornee et un ensemble explicite de techniciens.
Pas de rappel vers l'ancien Cofrac depuis ces endpoints. Pas de route publique
necessaire : loopback ou socket Unix, avec scope de lecture et controle d'acces.

Chaque occupation fournit un ID RDV MYMAR stable, l'ID technicien source, debut
et fin ISO 8601 avec fuseau, etat normalise, duree certaine/estimee et niveau de
confiance de la localisation. Les donnees manquantes restent explicites.
Le resultat indique sa date de collecte, la fenetre couverte et s'il est complet.

Cofrac conserve une projection d'occupations externes distincte de ses dossiers,
unique par source/ID RDV. Synchronisation en arriere-plan, pas de requete MYMAR
par ligne ou par ouverture de calendrier. Une collecte partielle/echouee ne
supprime jamais les occupations connues et n'est pas interpretee comme du libre.

Les occupations doivent etre utilisees par les deux calendriers, les suggestions,
les trajets et toutes les validations (placement, replacement, changement de
technicien, placement force). Elles ne sont pas editables depuis Cofrac.
Fraicheur insuffisante ou identite ambigue : incertitude explicite et pas de
validation automatique fondee sur de fausses disponibilites. Le delai de
fraicheur maximal sera valide avant activation.

Une derniere relecture peut reduire une course entre applications, sans offrir
une reservation atomique : une garantie stricte demanderait un protocole de
reservation partage avec MYMAR. Aucun verrou Cofrac seul ne garantit cela.

## Passerelle et client d'observation implementes localement

La passerelle autonome `tools/deploy/mymar/gateway/serve.php` et le client
`App\Services\Mymar\MymarGatewayClient` implementent maintenant une lecture
`GET /v1/occupations`. Ils ont ete testes ensemble en HTTP sur loopback avec
MySQL 8.0.46 et des donnees synthetiques. **Rien n'est installe ou active sur
le serveur de production.** Le client est desactive par defaut et n'est utilise
par aucun controleur, job, calendrier ou service de placement.

- Authentification Bearer dediee, comparaison a temps constant, acces depuis
  loopback seulement, en-tetes de proxy refuses. Aucun secret dans la query.
- Config JSON privee, non executable, 0600 dans un dossier 0700 hors des
  racines applicatives. Aucun chargement de .env/autoload/bootstrap de MYMAR.
- Uniquement SELECT sur les colonnes utiles de `dossiers` et `dossier_rdv`.
  Le runtime verifie les grants exacts et refuse les roles, droits globaux,
  ecritures, delegations et SELECT sur des tables entieres. Aucun compte SQL
  de production n'est cree dans cette etape.
- Tables InnoDB, transaction READ ONLY, limites MySQL 1500ms/verrous 2s.
  Requete parametree, statuts fixes par politique et techniciens explicites.
  Une enveloppe de 24 heures avec offsets de fuseau couvre les RDV debordant
  sur la fenetre. Les lignes invalides restent signalees, jamais remplacees
  par une duree par defaut ou silencieusement declarees libres.
- Plus de 2000 lignes : erreur 503, jamais de resultat tronque marque complet.
  `complete=true` garantit seulement la couverture de la requete bornee :
  aucune certification de l'unite, des statuts, du mapping ou de la localisation.
- Le contrat non publie ajoute `source_timezone` et `duration_unit` explicites.
  Le client refuse leur divergence avec sa politique, une autre fenetre, un
  autre ensemble de techniciens, une reponse partielle/perimee/future, les
  redirects, les contenus compresses ou superieurs a 2 Mio. Pas de retry cache.
- Seul un rapport `private_gateway_observation` est retourne. Il conserve
  `planning_ready=false`, `connector_enabled=false` et `can_affect_planning=false`.
  Aucune projection, liaison de compte ou ecriture des disponibilites.

L'endpoint de references techniciens reste a preparer. Les propositions de
rapprochement de l'audit ne sont toujours pas des liens approuves. Le perimetre
par technicien ne couvre pas les lignes sans technicien source : ce diagnostic
global restera a traiter avant l'activation. Une API exhaustive par perimetre
ne rend pas fiables des informations source incompletes.

Tests reproductibles :

```sh
php vendor/bin/pest tests/Unit/MymarGatewayTest.php tests/Unit/MymarObservationTest.php
MYMAR_GATEWAY_TEST=1 bash tools/deploy/tests/check-mymar-gateway.sh
```

Le second test cree uniquement des conteneurs jetables sans reseau externe,
monte le code en lecture seule et ne publie aucun port. Il verifie les vrais
grants MySQL, les intervalles en chevauchement, les RDV multiples d'un dossier,
les valeurs invalides, l'authentification HTTP, l'arret logique, le refus des
reponses tronquees et la derive des privileges. Checksums source compares
avant/apres les lectures. Sur une fixture de 12 011 RDV, moyenne locale des
dix lectures d'environ 7 ms, sans nouvel index. Ce chiffre n'est pas une
mesure du serveur de production ; la limite de 1500 ms reste imposee.

Validation globale apres implementation : 134 tests unitaires MYMAR reussis
(451 assertions), plus le test d'integration HTTP/MySQL ci-dessus. Suite PHP
complete : 2656 tests reussis, 20279 assertions, avec une limite memoire de
512 Mio pour le processus de tests local uniquement. Aucun reglage memoire
de production n'est modifie.

### Prochaine etape avant installation privee

`prepare-disabled.php --prepare-disabled REVISION` prepare maintenant un depot
root prive et scelle des seuls huit fichiers necessaires. Il n'est autorise que
sur ns365601, apres validation/reouverture de la release V2 correspondante. Le
marqueur `DISABLED.json` indique explicitement l'absence de configuration, compte
SQL, connexion et changement de services/planning. Il ne copie aucun .env ni
client Laravel et refuse une preparation existante modifiee. Ce depot de code
ne cree pas encore de service ou d'ecoute HTTP, meme en loopback.

Preparer le paquet d'installation desactivee de cette passerelle et un compte
SQL dedie limite aux colonnes necessaires, jamais le compte de maintenance. Valider la
requete definitive sous la limite d'execution existante avant de planifier
des lectures recurrentes. Revoir les liens des techniciens des RDV APT non
rapproches, sans toucher aux droits ou mots de passe. La projection, la
synchronisation et l'utilisation dans les disponibilites restent a implementer.

## Observation ponctuelle sur la source reelle

La release `0ea80832` est ouverte sur les deux domaines. La preparation privee
de la passerelle a ete confirmee, avec `enabled=false` et `configured=false`.
Cela ne constitue toujours pas un branchement MYMAR au planning.

Le paquet autonome `observation/collect-live.sh` reutilise les controles de
source et le rapprochement SQL de l'audit deja valide, puis execute une fois
la requete definitive `MymarReadOnlySource::query` sur sept jours depuis minuit
Europe/Paris. Un EXPLAIN simple precede la lecture. La limite de 1500 ms,
les transactions READ ONLY et les bornes de volume restent imposees.
Le compte de maintenance sert uniquement a cette collecte ponctuelle ; aucun
compte SQL, index, service ou configuration n'est cree ou change.

Les techniciens Terrain actifs MYMAR sont tous observes, meme si leur cible
Cofrac est absente ou desactivee. Le rapport propose seulement les liens dont
les preuves concordent ; aucun n'est approuve, et aucun `cofrac_user_id` n'est
renseigne dans les occupations. Les rapprochements et les occupations sont
lus dans des transactions distinctes, ce qui est explicitement indique.
Les RDV sans technicien sont comptes separement et la couverture globale
n'est jamais annoncee comme validee. Les hypotheses de duree, fuseau et
statuts ne sont pas promues en decisions de planning.

Le paquet verifie la release deja ouverte et les empreintes des quatre classes
de la preparation desactivee avant SQL. Il n'execute aucun code des applications,
ne lit aucun mot de passe utilisateur et ne publie aucun email, beneficiaire,
adresse ou document. Les noms et IDs des techniciens restent dans une revue
privee pour validation humaine. Les requetes/erreurs brutes restent root-privees.
Une source modifiee, un echec SQL ou un resultat partiel/perime bloque la
publication. Seuls les quatre rapports valides et leurs empreintes sont
transferrables a l'operateur, en 0600 dans un dossier 0700.

Le test MySQL confirme que `JSON_OBJECT` ajoute `.000000` a un DATETIME meme
sans precision fractionnaire. L'enveloppe SQL utilise `CAST(... AS CHAR)` pour
retrouver le meme texte que PDO, sans modifier l'heure ni tronquer une valeur
reellement fractionnaire. Les lignes invalides restent des anomalies.

Test reproductible :
`MYMAR_LIVE_TEST=1 bash tools/deploy/tests/check-mymar-live-observation.sh`.
Le test utilise un compte SELECT seul sur des schemas synthetiques, compare
les checksums avant/apres et repete la chaine de publication en conteneur
isole, avec refus d'un SQL interrompu, d'une source modifiee et d'une reponse
tronquee. Il n'appelle jamais le serveur de production.

## Correspondances humaines confirmees

L'observation reelle du 30 octobre a termine sans ecriture ni erreur SQL :
41 techniciens Terrain actifs, 10 propositions concordantes et 5 occupations
apres travaux sur la fenetre. Le plan definitif utilise une selection indexee
des dossiers puis une jointure indexee sur les RDV. Cela ne mesure pas la
latence d'une future synchronisation et ne valide pas encore tous les statuts.

Quatre identites presentes dans ces occupations ont ensuite ete confirmees
par l'operateur. Une lecture ponctuelle des comptes a retrouve quatre profils
sources actifs Terrain et quatre cibles Cofrac actives internes techniciens,
sans doublon dans cette selection. Les noms/IDs reels restent dans les donnees
privees de preparation, pas dans le depot. Les dix propositions concordantes
restantes ne deviennent pas automatiquement des liens approuves.

`tools/deploy/mymar/mapping` prepare maintenant un fichier de correspondances
inactif a partir d'une liste explicite d'IDs et identites approuves. Il relit
les deux cotes dans une meme transaction READ ONLY avant de creer le fichier
root-prive. Comptes manquants, desactives, externes, non techniciens, identites
changees, donnees incompletes/perimees et schema non transactionnel sont refuses.
Le script ne met jamais a jour `MYMAR.users.cofrac_id` ni un compte Cofrac.
Les empreintes de configuration sont relues avant publication ; la validation
est liee a la release deja ouverte et a la preparation MYMAR desactivee.

Le fichier porte `requires_runtime_revalidation=true` : son contenu ne dispense
pas de verifier de nouveau les profils avant une lecture utilisee par le CRM.
Il conserve `connector_enabled=false` et `can_affect_planning=false`, n'ecrit
aucun pointeur de configuration active et n'est charge par aucun service.
Le compte SQL restreint et la passerelle authentifiee restent a installer
separement ; aucun envoi mail/SMS ou changement des suggestions n'est declenche.

## Acces SQL dedie, toujours sans activation

Les quatre correspondances approuvees ont ete preparees et confirmees le
30 octobre. Le paquet autonome `tools/deploy/mymar/sql-gateway` prepare
l'etape suivante sans deploiement applicatif ni maintenance du site :

- Revalidation fraiche des profils et empreintes avant tout CREATE USER.
- Nouveau compte SQL local aleatoire, SELECT uniquement sur les huit colonnes
  necessaires des deux tables d'occupations MYMAR, sans role ni GRANT OPTION.
- Compte cree verrouille, deverrouille seulement pour ouvrir une connexion,
  puis reverrouille avant la lecture ponctuelle. Aucun compte existant change.
- Lecture reelle par ce compte, via le gestionnaire authentifie en processus,
  sur sept jours et uniquement pour les techniciens explicitement approuves.
- Mauvais jeton refuse sans lecture ; controle des grants, lecture READ ONLY
  bornee et verification du verrouillage a la sortie, meme apres un echec.

La creation et les grants modifient les comptes techniques MySQL ; ce n'est
donc pas une operation entierement sans ecriture SQL. Les bases metier,
fichiers de configuration des applications et services restent inchanges.
Le compte de maintenance sert uniquement au controle ponctuel et a cette
creation ; le test des occupations utilise le compte restreint.

Le mot de passe et le jeton generes restent root-prives sur le serveur.
La configuration conserve `enabled=false`. Un registre prive interdit la
recreation aveugle pour la meme empreinte de correspondances. Un echec apres
CREATE laisse le nouveau compte verrouille et un marqueur a revoir ; aucun
DROP USER ou nettoyage des donnees originales n'est automatique.

Le rapport distingue `authenticated_handler_tested=true` de
`production_http_tested=false`. Aucun listener HTTP ou service n'est demarre.
Cette etape n'integre toujours pas MYMAR au planning : pas de synchronisation,
projection CRM, ecriture des disponibilites ou changement des suggestions.
La couverture globale, le fuseau, les durees et les statuts restent a valider.

Test reproductible :
`MYMAR_SQL_GATEWAY_TEST=1 bash tools/deploy/tests/check-mymar-sql-gateway.sh`.
Il utilise uniquement des identites synthetiques et MySQL 8.0.46 jetable,
sans reseau externe. Il exerce la chaine serveur complete, les grants reels,
le maintien d'une session apres ACCOUNT LOCK, le refus de nouvelles connexions,
la reexecution, une cible desactivee et un echec de lecture apres CREATE.
Les checksums des tables metier sont compares avant/apres ; les fichiers
applicatifs et les appels interdits sont controles, sans bootstrap Laravel.

Validation locale de cette etape : 212 tests unitaires MYMAR reussis
(657 assertions), plus la chaine serveur integrale sur MySQL 8.0.46.
Les refus d'empreinte et de code modifie ont aussi ete verifies avant CREATE,
ainsi que le chemin independant de reverrouillage d'un compte ouvert.
Il ne s'agit pas encore d'une execution de ce nouveau paquet en production.

## Transport HTTP prive, sans appel par le planning

L'operateur a ensuite confirme la preparation SQL reelle : quatre occupations
lues avec le nouveau compte SELECT par colonnes, quatre liens revalides,
compte reverrouille et fichiers/services inchanges. Les trois anomalies
globales restent les hypotheses de duree/fuseau/statuts, pas des erreurs SQL.

Le lot autonome `tools/deploy/mymar/runtime` regroupe l'installation isolee
et le controle du vrai transport HTTP. Il prepare un Apache autonome sur
127.0.0.1:19846 et un master PHP-FPM dedie, sans include, modification ou reload
de l'Apache public ni de ses vhosts. Deux nouvelles unites et un utilisateur
Linux sans shell sont crees ; le compte SQL dedie existant est deverrouille
pour ce service, sans changer ses droits. Aucun paquet n'est installe.

Les credentials restent separes de Cofrac dans un repertoire prive 0700,
le code est root/lecture seule et les processus ont des limites de ressources.
L'API refuse les techniciens hors de la liste humainement approuvee en plus
du jeton obligatoire, du loopback et des gardes de perimetre existantes.
Elle ne passe jamais par les anciens endpoints MYMAR non proteges.

Le script compare les fichiers des applications et l'etat des services
existants avant/apres. Il n'execute pas Laravel, ne fait aucun git pull,
migration, import, envoi, synchronisation ou appel par une page du CRM.
Le marqueur du transport HTTP est distinct de toute activation du connecteur.
Les suggestions et les disponibilites restent donc exactement inchangees.

L'installation publie seulement un rapport apres un vrai GET authentifie
et six refus HTTP attendus. Un echec arrete les nouvelles unites uniquement
et reverrouille le compte SQL par un chemin independant des approbations.
Le script d'arret est fourni dans le diagnostic root-prive et le rapport.
Il ne supprime aucune donnee et ne restaure pas un ancien CRM.

Tests reproductibles : `MYMAR_PRIVATE_HTTP_TEST=1 bash
tools/deploy/tests/check-mymar-private-http.sh`, puis la meme commande avec
`MYMAR_PRIVATE_HTTP_FAILSTART=1`. Apache/FPM/MySQL/HTTP sont reels dans les
conteneurs jetables, sans reseau externe ; la gestion des PIDs remplace
systemd, tandis que systemd-analyze verifie les unites. Le refus de relance,
le nettoyage apres echec et l'arret apres derive du fichier d'approbation
sont controles avec les checksums des donnees metier et des applications.

La projection/cache CRM et la prise en compte des occupations dans les
disponibilites restent a implementer dans le lot suivant. Aucune lecture
MYMAR n'est prevue directement pendant le rendu des pages du planning.

Validation locale de ce lot : 214 tests unitaires MYMAR (683 assertions),
plus les deux scenarios complets Apache/FPM/MySQL ci-dessus. La premiere
execution systemd sur le serveur reste a confirmer par le rapport operateur ;
aucun demarrage de production n'a ete effectue depuis le poste de developpement.

## Pilote cache et affichage, sans blocage

Le rapport operateur du transport prive confirme une lecture HTTP authentifiee
et quatre correspondances. Le lot suivant ajoute une projection locale minimale
et un affichage distinct dans les calendriers de prise de RDV et de suivi.
Il ne cree aucun `Appointment` et ne modifie ni la detection de conflit ni les
suggestions : `blocking_enabled` reste obligatoirement `false`.

`mymar:sync-observation` est execute par un service/timer dedie toutes les
60 secondes environ, jamais par une requete utilisateur. Il appelle seulement
l'API loopback existante, avec un jeton separe. Le mot de passe SQL MYMAR et les
credentials de maintenance ne sont pas transmis au CRM. Le scheduler global,
les mails, les SMS et CAPCEE ne sont pas executes par ce timer.

Le cache contient uniquement les identifiants de liaison, les horaires, la phase
de visite et les dates de synchronisation/couverture. Il ne reprend ni beneficiaire,
adresse, document ou trajet MYMAR. La fenetre est bornee a 7 jours passes et
21 jours futurs, avec 2000 occupations maximum. Les pages effectuent une lecture
groupee du cache et une seule requete cible pour les profils/noms Cofrac actifs,
sans HTTP, jeton ou SQL source. Aucun cache n'est touche lorsque le pilote est
absent/desactive. Les droits d'acces aux calendriers restent inchanges.

Un verrou de synchronisation de 120 secondes evite les executions concurrentes.
Seule une reponse complete et valide remplace atomiquement le cache. Une panne
conserve le dernier instantane valide, au maximum 24 heures, avec un avertissement
explicite. Une anciennete de cinq minutes, un echec recent, une correspondance
modifiee, l'absence de cache ou une fenetre partiellement couverte ne sont jamais
presentes comme des disponibilites MAR fiables. Les doublons d'ecriture MySQL
sans ligne affectee sont acceptes seulement si la relecture est identique.

La configuration root:cofrac2-app est stockee sous `/etc/cofrac2-mymar`,
repertoire 0750, fichiers 0640, sans inclusion de secrets dans `.env` ou le
cache de configuration Laravel. Les quatre profils sources et cibles sont
revalides lors de l'installation ; les profils cibles actifs sont controles
ensuite a chaque synchronisation et lecture calendrier. Les hypotheses
Europe/Paris, durees en heures et statuts 3/16 restent visibles et non confirmees.
Cette etape est une observation avant validation humaine, pas une autorisation
de bloquer les creneaux.

Le paquet est construit avec `tools/deploy/prepare-mymar-pilot-delivery.php`
depuis les fichiers commites. Il reutilise les controles de release avant
maintenance, sauvegarde V2, assets precompiles et reouverture des deux domaines.
L'API privee deja installee n'est ni remplacee ni arretee. Le pilote est active
seulement apres une premiere synchronisation valide, sans nouvelle fermeture
du site. Un echec du pilote desactive ses propres flags/timer ; il ne ferme
pas le CRM. `mymar:observation-status` controle uniquement le cache local.
Le rapport fournit un `stop-pilot.sh` root-prive pour masquer les cartes et
arreter seulement le nouveau timer/service, sans suppression SQL ni arret API.

Test de chaine complete, uniquement dans les conteneurs jetables :

```bash
MYMAR_PRIVATE_HTTP_TEST=1 MYMAR_PILOT_CACHE_TEST=1 \
  bash tools/deploy/tests/check-mymar-private-http.sh
```

Ce test utilise une vraie API Apache/FPM, MySQL 8.0.46, les migrations Laravel
et son cache SQL. Il controle un echec de premiere synchronisation apres staging,
la desactivation de securite, le fonctionnement nominal, puis l'arret volontaire.
Les checksums des donnees metier sont compares avant/apres. Le cycle systemd
est simule par un gestionnaire de PIDs restreint et la syntaxe des unites est
verifiee avec `systemd-analyze` ; son execution reelle reste a confirmer par
le rapport operateur. Aucun test ne contacte la production.

Validation locale de ce lot : 2761 tests PHP (20595 assertions), 394 tests
frontend et la chaine complete API/cache/affichage/arret ci-dessus reussis.
Les cartes en vue mois et les compteurs distinguent aussi les observations
MYMAR des RDV confirmes Cofrac et des suggestions.

## Controle navigateur et previsualisation des conflits

Le retour operateur confirme le pilote de cache en production : quatre
techniciens approuves, cinq occupations, deux domaines rouverts, mail reel
conserve et SMS/CAPCEE desactives. La verification navigateur des deux espaces
a retrouve les cinq dates de debut sans decalage, et un cache qui continue de
s'actualiser. Les cartes et le detail MYMAR sont bien en lecture seule ; aucune
modification ou prise de rendez-vous de test n'a ete effectuee. Cela ne vaut pas
confirmation globale des durees ou de tous les statuts/techniciens de MYMAR.

`MymarOccupancySnapshot` prepare un calcul pur de chevauchements semi-ouverts,
sans service de placement branche. Un cache absent, perime, futur, invalide,
une synchronisation en echec ou une plage hors couverture restent des resultats
inconnus, jamais des disponibilites confirmees. Un technicien hors pilote est
explicitement distingue. Les instants absolus sont compares, y compris pendant
les changements d'heure ; aucun rapprochement par adresse n'est suppose.

Le paquet autonome `tools/deploy/mymar/conflicts` compare cet instantane avec
les seuls RDV Cofrac operationnels du pilote. Il ne deploie pas le site et ne
necessite pas de maintenance. Il n'amorce pas Laravel ni les providers applicatifs
(qui peuvent sinon reecrire leur cache de parametres), ne charge pas de jeton
API et ne contacte pas MYMAR. Il utilise les composants SQL/Eloquent avec les
events desactives et une transaction MySQL READ ONLY bornee. La lecture brute
des deux cles de cache evite aussi toute purge des lignes expirees.

Les empreintes des huit contrats de la release runtime, des fichiers de
configuration et l'etat des services sont controles. Aucune activation ou
modification des comptes, jobs, rendez-vous, documents, envois ou privileges.
Le rapport ne contient que des identifiants techniques et horaires, maximum
30 paires de conflits, plus les compteurs globaux ; aucun contact/beneficiaire.
Les durees/fuseau/statuts restent indiques comme hypotheses, les trajets ne sont
pas evalues. Les RDV deja en conflit sont signales sans suppression/deplacement.

Construction depuis le code commite :
`php tools/deploy/prepare-mymar-conflict-review.php REVISION_RUNTIME`.
La commande Mac du paquet est `bash ./fetch-conflicts.sh` ; pas de git pull
serveur. Le pilote reste en observation, `blocking_enabled=false`.

Tests : `tests/Unit/MymarOccupancySnapshotTest.php`, puis
`MYMAR_PRIVATE_HTTP_TEST=1 MYMAR_PILOT_CACHE_TEST=1 MYMAR_CONFLICT_REVIEW_TEST=1
bash tools/deploy/tests/check-mymar-private-http.sh` dans les conteneurs jetables.
Le test reel injecte un conflit synthetique, verifie sa detection sous MySQL
8.0.46 et compare les checksums des tables natives/source ET du cache avant/apres.
Un cache expire est refuse sans purge ni fallback HTTP. Aucun test n'appelle
la production ou ne relance le scheduler global.

## Liste des demandes MAR en lecture seule

La passerelle des demandes est independante de celle des occupations :
127.0.0.1:19847, SELECT seulement, POST refuse. Le compte SQL d'ecriture reste
verrouille. L'onglet RDV MAR charge une projection paginee (25 lignes, curseur
d'identifiant) uniquement a son ouverture. Le rendu initial des pages Cofrac
n'appelle pas MYMAR. Les noms/adresses sont affiches en texte, jamais en HTML.

La route interne est reservee aux comptes actifs gerant/planning/super-admin,
avec limitation de debit et reponses privees sans cache navigateur. La projection
serveur est chiffree, conservee 30 secondes, avec verrou court non bloquant et
actualisation manuelle bornee. Une panne, une reponse invalide ou un timeout ne
sont jamais presentes comme une liste vide valide. Le changement d'onglet annule
la requete et les anciennes reponses ne remplacent pas la page courante.

Le paquet se construit depuis HEAD commite :
`php tools/deploy/prepare-mymar-booking-ui.php REVISION_RUNTIME RAPPORT_LECTEUR`.
Il precontrole le lecteur et le verrouillage SQL, deploie le lot applicatif
sans nouvelle migration avec une courte maintenance, rouvre les deux domaines,
puis autorise la lecture via `/etc/cofrac2-mymar-booking/reader.json` et un jeton
separe root:cofrac2-app 0640. Le runtime ne recoit aucun identifiant SQL MYMAR.
Un echec de validation desactive cette seule liste sans fermer Cofrac ni arreter
les passerelles. Le rapport prive fournit un `disable.sh` independant des anciens
contrats de deploiement. Aucun placement, signalement de probleme, message de test
ou modification des donnees MYMAR n'est active dans ce lot.

La migration du futur journal `mymar_booking_requests` reste volontairement dans
`tools/deploy/mymar/booking/inactive-migrations`, hors migrations automatiques.
Elle devra etre reintroduite et autorisee dans un lot d'activation des placements
distinct, apres integration aux controles de disponibilite/reservation Cofrac.

Tests : `MymarPendingBookingsTest`, `MymarBookingOutboxTest`, tests frontend
`mymar-pending`/`booking-source-tabs`, et chaine conteneur jetable
`MYMAR_BOOKING_TEST=1 MYMAR_BOOKING_HTTP_TEST=1 bash tools/deploy/tests/check-mymar-booking.sh`.
