# Reprise actualisee des utilisateurs internes TechCalendar

## Decision du 25 octobre 2026

Reprendre tous les comptes internes du CT `10.100.0.124` dans la V2 existante
`cofrac2production` sur `ns365601`. Identifier d'abord l'application utilisee
par le client : le CT heberge a la fois `/var/www/TechCalendar` (V1) et
`/var/www/TechCalendar-v2` (demo ouverte depuis octobre).
Les doublons sont rapproches par email normalise (espaces retires aux extremites,
insensible a la casse), avec priorite aux valeurs metier TechCalendar.

L'utilisateur confirme de conserver les mots de passe Cofrac2 existants et le
seul super-admin deja defini. Aucun hash de mot de passe TechCalendar n'est
collecte, aucun seeder ou import initial Coffrac n'est a relancer.

## Collecte prealable

Le nouveau mode cible exporte uniquement les roles internes `0`, `1` et `2`,
y compris les comptes desactives, et les six tables necessaires au rapprochement :
`users`, `departments`, `department_user`, `services`, `service_user` et
`external_service_aliases`. Les pivots sont limites aux utilisateurs internes.
Clients/delegataires externes, absences, mails, connexions API et documents
ne sont pas collectes. Les colonnes restent explicites ; mots de passe,
remember tokens et champs ajoutes au schema ne sont jamais inclus implicitement.

Generer localement le collecteur autonome, sans charger de `.env` local :

```bash
php tools/fixtures/prepare-techcalendar-export.php \
    /var/www/TechCalendar --internal-users > collect-techcalendar-internal-users.sh
```

Le paquet prive ajoute `fetch-techcalendar-internal-users.sh` et `SHA256SUMS`.
Son execution sur le Mac collecte la base V1 en lecture seule, puis recupere
le gzip et son manifeste par SSH/SCP. La configuration du CT doit etre de
production, hors base de demonstration. Les fichiers source temporaires et
la destination locale sont prives (`0700`/`0600`). Aucun service ne demarre,
aucun provider applicatif ne s'initialise et aucune commande Artisan n'est lancee.

Le collecteur verifie les moteurs InnoDB et schemas, puis utilise le snapshot
transactionnel READ ONLY existant. Il n'ecrit aucune ligne SQL. La collecte
actualisee est indispensable : ne pas reutiliser les exports du 24 octobre.

### Verification de la source utilisee par le client

L'export V1 du 25 octobre, empreinte
`0e0fb5595dab8595238c3d0bae9a2ec8846853fd74a89fdb7cfcd226ff492ae3`,
contient 14 comptes, dont deux desactives, mais aucune modification des comptes
ou pivots apres le 10 septembre. Une collecte recente ne prouve pas que cette
base contient les saisies recentes : ne pas la fusionner avant de verifier
l'adresse utilisee par le client.

Pour comparer les saisies de la demo du CT, un mode distinct est disponible :

```bash
php tools/fixtures/prepare-techcalendar-export.php \
    /var/www/TechCalendar-v2 --ct-demo-internal-users > collect-techcalendar-internal-users.sh
```

Ce mode reste limite aux six tables et aux comptes internes. Il exige le chemin
exact, l'hote `techcalendar`, `APP_ENV=demo`, la connexion locale et la base
`techcalendar_v2_demo_simulation`. Il ne desactive pas les protections des autres
modes et ne rend pas cet export admissible a l'import initial de production.
Il utilise explicitement `php8.4` et verifie PHP >= 8.4.1 avant de charger
Composer : le PHP par defaut du CT peut rester en 8.3 pour l'ancienne application.
Si le binaire 8.4 manque, l'export s'arrete sans repli ni installation automatique.
Le collecteur affiche le projet source et les dates maximales de modification
des comptes et des deux pivots. Aucun mot de passe ni dossier de test n'est
exporte. Cette comparaison n'autorise aucune fusion automatique.

La collecte V2 suivante contient les saisies du 25 octobre : empreinte
`df01d2afe68977297cf43ffa76644e9ae43e8722f02e04652ff5ba7ef73444ad`,
67 comptes internes, 96 affectations de departements, 713 habilitations et
104 prestations (dont des anciens libelles et libelles historiques).
La derniere modification des trois tables comptes/pivots est a 20:17:16
le 25 octobre dans les dates SQL source. Ne pas supposer les identifiants
de ces utilisateurs ou prestations identiques a ceux de la production.

## Rapprochement cible en lecture seule

Le paquet `cofrac2-internal-users-plan-XXXXXXXX` contient la collecte scellee,
`plan-techcalendar-internal-users.sh`, son controle PHP, sa classe de
rapprochement et `fetch-internal-users-plan.sh` a lancer sur le Mac. Ce dernier
envoie le paquet sur `ubuntu@46.105.110.76`, lance le controle prive via sudo,
puis telecharge son rapport compresse avec verification d'empreinte.

Le controle serveur est limite a `ns365601`, `/var/www/cofrac2` et au compte SQL
`cofrac2_app` sur `cofrac2production`. Il ne charge pas Laravel et n'accede pas
aux identifiants SQL de maintenance. La lecture se fait dans une transaction
`REPEATABLE READ, READ ONLY`, fermee par rollback. Aucun service, cache,
schema, utilisateur, droit ou fichier applicatif n'est modifie. Les seuls
fichiers crees sont le paquet prive et le rapport de diagnostic.

Le rapport compare les emails normalises, comptes desactives compris, et
signale les collisions externes, doublons, identites de test et profils a revoir.
Le compte `contact@lucas-dinnichert.fr` est integralement protege, meme si son
equivalent sur la demo s'appelle « Admin Simulation ». Les autres drapeaux
`admin` source ne sont pas repris. Le rapport ne contient aucun hash de mot
de passe, remember token ou secret de la cible ; seule une empreinte globale
du contenu lu permettra de detecter une cible modifiee avant une future fusion.

Les prestations sont rapprochees par type et libelle exact, puis aliases
explicites si aucun libelle exact ne correspond. Les IDs numeriques ne sont
jamais utilises comme preuve de correspondance entre applications. Une
prestation introuvable, retiree ou ambigue reste en revue. Ce premier rapport
n'est pas une validation de la politique de remplacement des habilitations.
Il ne permet pas d'appliquer une fusion : aucune option `--apply` n'existe.

## Avant toute fusion

Cette collecte n'applique aucun changement dans Cofrac2. Analyser le fichier
fraichement recupere et produire le rapprochement et ses conflits avant import.
La fusion devra conserver les IDs V2 existants et leurs liens dossiers/RDV,
laisser intacts les comptes absents de cette source, ne jamais convertir un
compte externe en compte interne par simple collision d'email, ni recopier le
privilege global `admin` du CT. Les nouveaux comptes doivent avoir une
initialisation individuelle, sans invitation reelle ni mot de passe partage ;
leur acces sera defini dans la gestion des utilisateurs apres la creation.

Les IDs de prestations des deux applications ne sont pas interchangeables.
Rapprocher les habilitations par catalogue et aliases explicites ; une ambiguite
doit rester en revue et ne doit pas donner toutes les prestations par defaut.
Les affectations de departements seront reprises explicitement, sans deduire
des secteurs a partir de l'adresse ou elargir une selection vide. Une sauvegarde
V2 et un import cible transactionnel/idempotent seront requis avant application.

## Fusion cible preparee apres le rapport

Le rapport de production du 25 octobre est scelle par l'empreinte
`1eb5f13600413c7268f237c437e788268870d31a3f24e4d6588f9a1bc758e24a`.
Il retrouve 64 comptes (dont le seul super-admin protege), propose trois
creations et 25 desactivations. Les 224 autres comptes sont hors perimetre.
Les 57 prestations actuelles ont une correspondance exacte avec le CT.

L'utilisateur confirme explicitement de reprendre les desactivations du CT et
de creer les trois comptes manquants, y compris les deux anciens comptes de
test en les conservant desactives. Les nouveaux comptes n'ont pas de mot de
passe partage : un secret individuel aleatoire inconnu est hache puis jete,
`must_change_password` est actif et aucun email n'est envoye. Un administrateur
doit ensuite definir leur acces via la gestion des utilisateurs.

Les anciennes habilitations sans correspondance avec le catalogue actuel
restent dans le journal prive, sans attribution speculative. Cela concerne
quatre comptes inactifs et une ancienne prestation sur le nouveau technicien
actif, a revalider dans sa fiche. Sur le compte de test inactif dont le code
primaire est l'ancien `20`, ce champ est vide ; les secteurs explicites `2A`
et `2B` sont repris. La valeur originale reste dans la source et dans l'audit.
Ces avertissements sont affiches avant la confirmation interactive `FUSIONNER`.

Le paquet autonome de fusion n'exige ni pull du code applicatif ni maintenance
Apache. Il utilise exclusivement le compte SQL limite a `cofrac2production`,
fait un precontrole en READ ONLY puis un dump prive de cette seule base.
L'empreinte du dump est verifiee avant la transaction d'ecriture. Aucune
commande Artisan, migration, seeder, emission API ou modification de service.

La transaction verrouille les comptes, pivots et catalogues lus, recalcule le
rapprochement approuve et refuse toute cible modifiee depuis le controle.
Seuls les profils des comptes rapproches et leurs deux pivots sont synchronises.
Les IDs existants, mots de passe, droits de facturation, notifications et droits
externes sont conserves. Les sessions, remember tokens et jetons mobiles sont
revoques pour les comptes desactives ou dont le role change, pas pour les autres.

Les comparaisons avant commit verifient chaque hash de mot de passe existant,
le super-admin integral et les comptes hors perimetre. Les catalogues restent
intacts et les documents/dossiers/RDV ne sont jamais ecrits. Toute erreur avant
commit annule les profils, pivots et revocations ensemble. Le .env et les
entrees scellees sont reverifies juste avant commit. Une sauvegarde et un rapport
restent sous `/var/lib/cofrac2-production/internal-users-merge-*`, hors HTTP.

Un recu root prive par empreinte des decisions interdit d'appliquer deux fois
la meme fusion. Sans recu, une cible deja modifiee echoue aussi au controle
d'empreinte ; ne pas contourner ce refus, restaurer automatiquement ou reutiliser
l'import initial. Une reexecution avec un nouveau rapprochement approuve est
idempotente et ne recree aucun compte par email.
