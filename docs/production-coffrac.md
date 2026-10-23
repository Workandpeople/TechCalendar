# Preparation de la production V2 sur le serveur Coffrac

## Cible

- Serveur : `ubuntu@46.105.110.76`, hostname `ns365601`.
- URL temporaire confirmee : `https://cofrac2.geniuscontrole.com`.
- URL finale : `https://cofrac.geniuscontrole.com`.
- Coffrac actuel : `/home/ubuntu/www/coffrac_preprod`, toujours en service.
- Consigne confirmee : ne modifier ni les fichiers, ni la base, ni les vhosts,
  ni les regles d'acces de Coffrac original. Aucun confinement de l'ancien site.
- Pendant la preparation, ne recharger ni redemarrer Apache ou les services
  existants. L'ajout du nouveau vhost sera une etape distincte, apres recette.
- Preparer une installation V2 distincte : repertoire, identite de service,
  pool PHP-FPM, base, compte SQL, worker et Reverb propres.
- La reprise finale utilisera des donnees Coffrac actualisees. Le snapshot et
  les comptes de test de la demonstration ne constituent pas la base de production.

## Inventaire fourni le 23 octobre 2026

Le rapport a ete produit par `tools/deploy/audit-production-host.sh`, sans
bootstrap Laravel, lecture du `.env`, modification de base ou rechargement de service.

| Element | Constat |
| --- | --- |
| OS | Ubuntu 22.04.5 LTS |
| Disque racine | ext4 sur RAID1, 1.8 To, 335 Go disponibles, 81 % utilises |
| Inodes | 2 % utilises |
| Memoire | 62 Gio au total, 59 Gio disponibles |
| Charge moyenne | 0.01 / 0.08 / 0.20 |
| Documents Coffrac | 217 Go dans `public/documents` |
| MySQL | 8.0.46, ecoute locale ; `/var/lib/mysql` occupe 1.4 Go toutes bases confondues |
| PHP CLI par defaut | 8.4.27 ; PHP 8.3 et 8.5 sont aussi presents |
| PHP-FPM actifs | 7.3, 7.4, 8.3 ; aucun service 8.4 actif dans le rapport |
| PHP 8.4 CLI | `intl` et `bcmath` absents de la liste des extensions chargees |
| Composer | 2.8.9 |
| Node | 12.22.9 : incompatible avec les engines du Vite verrouille dans le projet |

Le `composer.lock` actuel exige PHP >= 8.4.1. Ne pas modifier les handlers des
sites existants ni la version PHP globale pour installer le pool V2.
Les assets peuvent etre compiles hors serveur ; aucune mise a niveau globale
de Node n'est necessaire si ce mode de livraison est retenu.

L'inventaire SQL par socket root sans mot de passe a ete refuse. Aucune taille
par base ni aucun acces administrateur MySQL n'a ete valide par ce controle.

## Sites charges par Apache

Neuf noms possedent un vhost HTTPS :

- `amo.workandpeople.fr`
- `cofrac.geniuscontrole.com`
- `mar.th-inspection.fr`
- `my-audit.fr`
- `mydim.dajtconsulting.fr`
- `mymar.geniuscontrole.com`
- `old.people-manager.fr`
- `rgeaudit.fr`
- `th-inspection.fr`

`preprod.geniuscontrole.com` possede aussi un vhost HTTP. Le vhost HTTP par
defaut est `ns365601.ip-46-105-110.eu`. Ces configurations chargees ne prouvent
pas que chaque site recoit encore du trafic ou peut etre arrete.

La premiere version du script utilisait `grep -r`, qui ne suivait pas les liens
de `sites-enabled`. Les noms sont confirmes par `apache2ctl -S`, mais les
directives detaillees doivent etre relues. Le script utilise desormais `grep -R`.

## Constat de securite sur l'ancien site, conserve sans remediation

Le fichier `public/universign/css/images/108492/index.php` est toujours present,
avec l'empreinte SHA-256 `ef895e9386a21777b94fce3a05d08a14bea9969be84cc36f5bddc9a1cd9a53c7`.
Elle correspond a la copie analysee le 14 octobre, qui contient un gestionnaire
de fichiers permettant notamment lecture, modification et televersement.
Ce controle constate le contenu connu ; il ne prouve pas son accessibilite HTTP
actuelle, son execution ou l'absence d'autres fichiers parasites.

Les trois `.htaccess` suspects deja releves restent aussi presents dans
`public/pdf/875032`, `public/media/440055` et `public/lot_dossier/533657`, avec
leur empreinte connue `b4cdad551a2258c7b743f0dda5075af1f6e9b10f88cf237bfa7c66b15cc93e5d`.

Ces observations restent documentees. Ne pas visiter ni executer le script
suspect. A la demande de l'utilisateur, ne modifier aucune regle de l'ancien
site : la preparation porte uniquement sur la nouvelle installation V2.

### Complement de configuration recu le 23 octobre

Les trois vhosts Coffrac (HTTP, HTTPS et preprod HTTP) servent bien cette racine
avec `AllowOverride All` et `Require all granted`. Coffrac HTTP/HTTPS utilise
explicitement PHP 8.3 FPM pour les fichiers `.php`. Les `.htaccess` de chacun
des quatre niveaux `universign`, `css`, `images` et `108492` contiennent une
exception `Allow from all` pour `index.php`, apres un refus PHP general.
Aucune regle de confinement de ce chemin n'apparait dans le complement fourni.
L'URL suspecte n'a pas ete appelee ; aucune exploitation n'est confirmee.

PHP 8.4 FPM, intl et bcmath sont non installes et disponibles en version
`8.4.27-1+ubuntu22.04.1+deb.sury.org+1` dans les depots deja configures.
Examiner une simulation d'installation avant modification du serveur partage.

### Proposition de confinement retiree

Les scripts de blocage proposes ont ete retires du workspace apres la consigne
explicite de ne pas modifier Coffrac. L'agent ne les a jamais executes sur le
serveur. Ne pas executer une copie qui aurait ete transferee auparavant.

## Preparation isolee de l'acces GitHub et du clone

`tools/deploy/prepare-production-checkout.sh` cible uniquement le nouveau compte
`cofrac2-deploy`, son home prive et le nouveau repertoire `/var/www/cofrac2`.
Il ne lance ni Apache, ni PHP-FPM, ni Composer, ni Laravel, ni commande SQL.
Il ne lit pas le `.env` de Coffrac et n'accorde pas de droits sur ses documents.

1. Executer `--prepare-ssh` en root sur `ns365601`. Le compte de deploiement
   a un mot de passe verrouille, aucun acces sudo ajoute et une cle sortante
   Ed25519 dediee. Seule la cle publique est affichee.
2. Ajouter cette cle dans les Deploy keys de `Workandpeople/TechCalendar`, en
   lecture seule. La cle d'hote GitHub Ed25519 est epinglee depuis sa documentation
   officielle dans un fichier propre a ce compte, sans modifier les autres acces SSH.
3. Publier les correctifs V2 valides avant le clone. Executer `--clone-v2`
   uniquement lorsque la cle est ajoutee et la revision cible disponible.
   Le script refuse une destination deja presente et conserve une copie
   partielle en cas d'echec, sans lancer l'application.

Le 23 octobre, l'utilisateur confirme l'execution de `--prepare-ssh` sur
`ns365601` et l'ajout de la cle publique comme Deploy key du depot. Le clone
reste a executer par l'utilisateur ; aucun service V2 n'est encore demarre.

L'utilisateur PHP de la V2 sera distinct du compte de deploiement et des
processus `www-data` de l'ancien site. Cette separation ne constitue pas une
validation de l'integrite de l'ensemble du serveur.

Verification dans un conteneur Linux ephemere sans reseau : creation et
reeexecution sans regeneration de cle, permissions 0700/0600, refus de lecture
par `www-data`, refus d'un compte non reconnu et absence de modification des
fichiers/configurations Coffrac factices. Un second controle clone un depot
factice via un transport SSH simule, verifie la branche V2, les permissions,
l'absence de `.env` et le refus d'ecraser une destination existante. Aucun appel
GitHub et aucun compte ou fichier cree sur le vrai serveur pendant ces tests.

## Stockage documentaire et suites

Le lecteur `CollectedDossierDocuments` permet de lire les pieces existantes a
partir d'une racine et d'un manifeste. Etudier un raccordement en lecture seule
du repertoire historique, avec un manifeste regenere a partir de la reprise
finale, pour eviter une seconde copie des 217 Go.
Le nouveau stockage d'uploads doit rester prive et distinct. Aucun lien vers
`public/storage` ni import des `.htaccess` et executables historiques.

Apres les controles : preparer la cle de deploiement GitHub limitee au depot,
cloner la revision V2 publiee, installer le runtime dedie et preparer la reprise
sur une base distincte. Les scripts `*-demo` du CT 124 ne ciblent pas ce serveur
et ne doivent pas y etre executes. Configurer ensuite le nouveau vhost, DNS et
TLS pour l'URL temporaire ; conserver la bascule du domaine historique pour
la fin de recette et la derniere reprise coherente.

Au stade de cet inventaire, aucun acces GitHub, clone, installation de paquet,
migration, changement DNS ou modification de Coffrac n'a ete effectue par l'agent.
