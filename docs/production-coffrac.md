# Preparation de la production V2 sur le serveur Coffrac

## Cible

- Serveur : `ubuntu@46.105.110.76`, hostname `ns365601`.
- URL temporaire confirmee : `https://cofrac2.geniuscontrole.com`.
- URL finale : `https://cofrac.geniuscontrole.com`.
- Coffrac actuel : `/home/ubuntu/www/coffrac_preprod`, toujours en service.
- Consigne initiale : ne modifier ni les fichiers, ni la base, ni les vhosts,
  ni les regles d'acces de Coffrac original. Exception confirmee le 25 octobre :
  mise en maintenance immediate des seuls vhosts de `cofrac.geniuscontrole.com`,
  avec page statique vers Cofrac 2 et sauvegarde de retour arriere (section 14).
  Le code, les donnees, les documents et les processus originaux restent intacts.
- Ne redemarrer aucun service existant. L'ajout du seul vhost `cofrac2` et du
  certificat a ete demande separement le 23 octobre : il autorise le rechargement
  gracieux d'Apache partage, apres controle de configuration, sans modifier les
  vhosts existants. L'application V2 reste fermee pendant la reprise des donnees.
- Preparer une installation V2 distincte : repertoire, identite de service,
  pool PHP-FPM, base, compte SQL, worker et Reverb propres.
- La reprise finale utilisera des donnees Coffrac actualisees. Le snapshot et
  les comptes de test de la demonstration ne constituent pas la base de production.

## Perimetre de reprise confirme, 23 octobre 2026

Cette section remplace le projet initial de lecture des documents en place.
Ne pas lancer d'import ni de copie partielle avant adaptation et validation du
pipeline de production sur ce perimetre.

- La source metier est la base Coffrac complete et actualisee : tous les
  dossiers, toutes leurs fiches/prestations et tous les statuts, y compris
  factures, annules, archives ou incomplets. Reprendre les RDV passes/presents/
  futurs, commentaires, documents, rapports, controles, marquages de facturation,
  affectations, liens clients/delegataires/installateurs et historiques.
- Tous les types d'utilisateurs Coffrac entrent dans la reprise : internes,
  techniciens, clients, delegataires et autres profils existants. Conserver les
  comptes inactifs/archives comme tels. Traduire les droits dans la V2 sans
  elevation automatique et sans fusionner les homonymes ou emails ambigus.
- Le seul compte issu d'un seeder sera le super-administrateur V2. Ne charger
  aucun seeder de demo/simulation, utilisateurs fictifs, rendez-vous exemples ou
  snapshot de references TechCalendar a la place des referentiels Coffrac.
  Les referentiels techniques initialises par les migrations ne sont pas des
  donnees metier de demonstration ; les rapprocher du referentiel source.
- Les hashes de mots de passe compatibles pourront etre conserves apres
  verification du format. Les comptes incompatibles ou incomplets doivent
  ressortir dans le rapport et suivre un parcours de reinitialisation explicite,
  sans remplacement silencieux par des mots de passe aleatoires.
- Toute table ou colonne non projetee nativement doit etre identifiee dans le
  rapport de couverture et conservee dans la sauvegarde source. Le dump complet
  ne prouve pas a lui seul que l'import natif est complet. Verifier effectifs,
  correspondances stables par identifiants source, liens, droits, audiences,
  etats et blocages historiques de double facturation avant ouverture.
- Les anciennes sessions, jetons actifs, caches et files de jobs/SMS/emails
  restent dans la sauvegarde mais ne sont pas reactives : pas de session heritee
  ni d'envoi rejoue. Les identifiants d'integration sont traites separement des
  donnees metier, sans publication ni reutilisation automatique.

### Controle prealable du snapshot SQL

Le controle serveur fourni le 23 octobre confirme la table `clients` en MyISAM
et les 25 autres tables en InnoDB. La preparation conserve ces moteurs tels quels.
Le collecteur refuse ce cas par defaut ; l'option explicite
`--allow-live-myisam-clients` permet uniquement la collecte de preparation de
ce schema mixte connu. Tout autre cas non InnoDB reste refuse.

`--single-transaction` ne garantit pas la coherence des tables MyISAM modifiees
pendant la collecte. Tant que les employes travaillent, un export de preparation
ne doit donc pas etre presente comme une photographie transactionnelle de toute
la base si ce moteur est encore present. La strategie de collecte et le gel des
ecritures a la bascule finale devront etre valides separement ; aucun verrouillage
ou arret de Coffrac n'est autorise par la preparation actuelle.

Controle deja execute sur le serveur, sans lecture des donnees personnelles ni
modification :

```bash
sudo mysql --defaults-file=/etc/mysql/debian.cnf \
    --protocol=socket --connect-timeout=5 --batch \
    --execute="SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES
               WHERE TABLE_SCHEMA='fiche_crm' AND TABLE_TYPE='BASE TABLE'
               ORDER BY TABLE_NAME;"

df -h /var/www/cofrac2 /home/ubuntu/www/coffrac_preprod/public/documents
```

Le disque a toujours 335 Go disponibles au moment de ce controle. Ne pas
convertir `clients`, changer de serveur SQL ou ajouter un verrou global pour
effectuer la collecte de preparation.

### Copie documentaire complete et independante

- Dupliquer physiquement toutes les pieces Coffrac dans un stockage prive V2,
  tous statuts compris. Pas de filtre « hors factures », pas de lien symbolique,
  hardlink ou bind mount vers les originaux comme stockage definitif.
- Inventorier `public/documents`, `public/pdf`, `public/media`,
  `public/signature`, `public/lot_dossier` et `storage/app`, en distinguant les
  pieces metier du code/configuration. Les fichiers non references, manquants,
  vides, liens symboliques et formats non reconnus restent signales ; ne rien
  ignorer silencieusement. Le code PHP, les `.htaccess` et `.user.ini` de
  l'ancien site ne sont pas des pieces a reutiliser par la nouvelle application.
- Ne pas utiliser la selection de simulation du 14 octobre ni son manifeste :
  `tools/coffrac/collect-document-manifest.php` exclut les dossiers entierement
  factures et ne correspond donc pas au besoin de production confirme.
- Conserver les metadonnees, proprietaires metier, dates, commentaires, liens
  aux dossiers/fiches et audiences de chaque piece. Une copie sur disque ne
  suffit pas a valider les droits ni le rattachement dans la V2.
- Prevoir une copie directe et reprenable, sans ZIP/tar intermediaire de 217 Go,
  puis verification des contenus par empreintes et rapprochement DB/fichiers.
  Les nouvelles pieces natives restent distinctes de cette archive historique.
- Recontroler l'espace juste avant copie. Dernier releve : 335 Go libres,
  environ 217 Go dans `public/documents`, soit environ 118 Go restants avant les
  autres repertoires, la croissance et les fichiers temporaires. Ce n'est pas
  une garantie de marge suffisante au moment effectif de la copie.
- Les employes continuant a travailler sur Coffrac, refaire un passage
  differentiel et une collecte SQL coherente a la bascule. La copie initiale
  n'est pas une synchronisation definitive entre les deux applications.
- Ne supprimer aucune piece originale pendant les essais. Toute suppression
  future demandera une validation distincte apres recette, verification de
  l'independance de la V2 et d'une sauvegarde restaurable hors de l'ancien site.
  La duplication sur le meme serveur ne remplace pas une sauvegarde externe.

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
`ns365601`, l'ajout de la cle publique comme Deploy key du depot et le clone
de `v2` dans `/var/www/cofrac2`, initialement au commit `18a91efc`.
Aucun service V2 n'est encore demarre.

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

## Installation des dependances de la nouvelle V2

La simulation fournie par l'utilisateur confirme uniquement trois nouveaux
paquets : `php8.4-fpm`, `php8.4-intl`, `php8.4-bcmath`, en version
`8.4.27-1+ubuntu22.04.1+deb.sury.org+1`, sans mise a niveau ni suppression.
Le paquet FPM exact du PPA a ete telecharge et examine localement, sans execution :
SHA-256 `6593e784c734ad73940d0d69a1d61f26f0736fe672a6b8be5c1d027f7f20c98f`.
Son `postinst` n'active pas la configuration Apache par defaut et passe par
`invoke-rc.d` pour le demarrage du nouveau FPM.

`tools/deploy/install-production-dependencies.sh --install-v2-dependencies` :

- Exige le bon serveur et un checkout V2 propre, appartenant au compte de
  deploiement, sans `.env` et sans PHP 8.4 FPM actif.
- Refuse tout plan APT qui ajoute un autre paquet, supprime un paquet ou met
  a niveau un paquet deja installe ; les trois versions sont epinglees.
- Telecharge avant de bloquer temporairement les actions de service via
  `policy-rc.d`. La politique precedente (y compris un lien symbolique) est
  preservee puis restauree, egalement en cas d'echec ordinaire ou d'interruption
  captee. `needrestart` est suspendu pour cette seule commande APT.
- Compare les configurations Apache actives et les proprietes systemd des
  services existants avant/apres. Aucun demarrage, arret ou rechargement explicite.
- Verifie PHP et ses extensions, puis execute Composer avec PHP 8.4 comme
  `cofrac2-deploy`, sans plugin ni script, avec les versions verrouillees,
  optimisation de l'autoload et audit des dependances installees.
- N'execute ni Artisan, ni generation de cle, ni migration, ni connexion SQL.
  PHP 8.4 FPM reste arrete ; le pool dedie, le `.env` et la base viennent ensuite.

Le diagnostic est prive dans `/var/tmp/cofrac2-dependencies-*`. Le test
`tools/deploy/tests/check-production-dependencies.sh` ne peut fonctionner que
dans un conteneur Docker jetable explicitement marque. Il simule APT/systemd/
Composer sans reseau : restauration de politique absente, reguliere ou symbolique,
echec d'installation, refus d'une mise a niveau inattendue, execution Composer
non-root et conservation des fichiers Coffrac factices.

### Reprise apres installation des paquets

Les trois paquets ont ete installes sur le serveur. Le premier controle a
interrompu la suite avant Composer : seul l'ancien resultat d'execution
`ExecReload` d'Apache est passe d'un rechargement historique a un resultat vide
(`start_time=[n/a]`, `pid=0`). Les PID, demarrages et etats actifs des anciens
services ainsi que les configurations Apache sont restes identiques. Le nouveau
FPM est inactif, et la politique temporaire a ete restauree.

La comparaison accepte desormais uniquement cette remise a zero d'historique,
avec definition de commande et identite de service inchangees. Un nouveau
resultat de rechargement, un changement de PID, de demarrage, de commande ou
d'etat reste bloquant. Les snapshots bruts restent dans le diagnostic prive.

Apres un fast-forward du clone V2, utiliser `--composer-only` pour verifier les
versions exactes des trois paquets deja installes et installer les dependances
verrouillees. Ce mode n'execute aucune commande APT et ne touche pas a
`policy-rc.d`. Il conserve les controles de checkout propre, absence de `.env`,
FPM 8.4 inactif, extensions PHP et isolation des services/configurations, y
compris apres Composer. Les tests simulent la remise a zero observee, refusent
les vrais changements et verifient la reprise sans APT ainsi que le refus
d'une version de paquet differente.

La reprise `--composer-only` a termine sur le serveur : 91 dependances installees,
extensions et plateforme PHP 8.4.27 conformes, aucune alerte connue a l'audit
Composer. Les controles finaux de services et de configurations Apache ont passe.

## Nouvelle base et configuration initiale

Le compte root MySQL sans mot de passe est refuse, mais l'acces existant de
maintenance via `/etc/mysql/debian.cnf` fonctionne. `debian-sys-maint@localhost`
possede les droits de creation et d'attribution necessaires. Aucun changement
d'authentification, second moteur SQL ou redemarrage MySQL n'est necessaire.

`tools/deploy/prepare-production-database.php --create-new-v2-database` s'execute
en root, avec PHP 8.4 sur `ns365601`, sans bootstrap Laravel :

- Exige le bon repertoire, le proprietaire de deploiement, une branche V2
  propre et l'absence de `.env`, de config cachee et d'etat de preparation.
- Utilise le client MySQL et sa configuration de maintenance existante sans
  extraire ni afficher son mot de passe. Verifie le compte, le port et MySQL 8.0.
- Refuse une base `cofrac2production` ou un utilisateur `cofrac2_app` deja present,
  y compris sur un autre host. Le nom de base sans joker ni underscore evite les
  ambiguites de portee des GRANT de base.
- Genere les secrets sur le serveur et les conserve avant tout DDL dans
  `/var/lib/cofrac2-production/initial-production.env`, prive root 0700/0600.
- Cree uniquement cette nouvelle base utf8mb4 et les deux comptes locaux
  `cofrac2_app@localhost` / `cofrac2_app@127.0.0.1`, avec droits sur cette base
  seule, aucun privilege global ni GRANT OPTION.
- Verifie une connexion applicative TCP, la base vide et ses droits, puis
  installe le nouveau `.env` en 0600 pour `cofrac2-deploy`. Le futur runtime
  recevra uniquement les droits necessaires lors de sa preparation distincte.
- Ne lance aucune migration, import, seed, API, notification ou commande de
  service. Le compte de maintenance ne figure jamais dans le `.env` applicatif.

Le modele `tools/deploy/production.env.example` active la V2 native en production,
desactive le debug et configure une session HTTPS propre au nouveau domaine.
Les identifiants API restent vides, les emails sont en log, SMSFactor desactive
et en mode simulation, CAPCEE desactive. Le connecteur Coffrac reste sans URL
ni jeton. Reverb est prepare sur loopback, avec ses nouveaux secrets et une
origine HTTPS dediee ; aucun port n'est ouvert et aucun processus n'est demarre.

Les operations DDL MySQL ne sont pas transactionnelles. En cas d'echec partiel,
les secrets restent dans le dossier prive ; aucune base ni aucun compte n'est
supprime ou reinitialise automatiquement. Examiner l'etape signalee avant
toute reprise, sans publier les fichiers prives. Le fichier `DATABASE-READY.json`
atteste seulement la base vide et le `.env` initial, pas une recette applicative.

Le test `tools/deploy/tests/check-production-database.sh` exige un environnement
Docker jetable explicitement marque. Test reel sur MySQL 8.0.46, sans port publie
ni base utilisateur montee : refus des cibles existantes, droits limites,
interdiction de lecture d'une autre base et de creation d'utilisateurs, secrets
non affiches, refus de regeneration et conservation des fichiers/configurations
Coffrac factices. Un echec DDL force conserve les secrets sans publier de `.env`
invalide ni effacer la base partiellement preparee.

La creation a ete confirmee par l'utilisateur sur le serveur : base
`cofrac2production` vide, connexion TCP du compte limite validee et `.env`
installe en 0600. Aucun import, migration ou service lance a cette etape.

## Runtime et initialisation du schema

`tools/deploy/initialize-production-schema.sh --initialize-empty-v2-schema`
prepare seulement la nouvelle installation :

- Verifie le bon serveur, un checkout V2 propre, les empreintes du `.env` et
  de la preparation privee, et l'absence de configuration cachee. La connexion
  applicative doit encore viser une base V2 completement vide.
- Cree `cofrac2-app`, compte systeme a mot de passe verrouille et shell nologin,
  sans groupe supplementaire. Le marqueur root dans le dossier de preparation
  permet de reconnaitre cette seule identite ; aucun compte inconnu n'est adopte.
- Laisse le code a `cofrac2-deploy`, lisible mais non modifiable par le runtime.
  `.git` reste prive au deploiement. Le `.env` devient root:cofrac2-app 0640,
  lisible par PHP seulement, sans modification de son contenu.
- Rend `storage` et `bootstrap/cache` inscriptibles par le runtime et le
  deploiement. Le seul lien Web pointe vers `storage/app/public` ; les parents
  autorisent uniquement la traversee et les documents prives restent inaccessibles
  a Apache. Aucun lien ni permission n'est ajoute au stockage historique Coffrac.
- Execute `package:discover`, puis `migrate --force` comme `cofrac2-app`, dans un
  environnement CLI nettoye pour que les variables du terminal ne remplacent pas
  le `.env`. Ni `migrate:fresh`, ni seed, ni scheduler, ni worker n'est lance.
- Verifie les migrations appliquees et l'absence d'utilisateurs, dossiers, lots,
  RDV et jobs. Les donnees de reference creees par les migrations (departements,
  expediteur mail sans identifiant SMTP) sont conservees. Aucun compte de demo
  ou admin artificiel n'est cree.

Le journal est prive dans `/var/lib/cofrac2-production/schema-initialization.log`.
`SCHEMA-READY.json` n'est publie qu'apres les controles reussis. En cas de migration
partielle, ne pas relancer automatiquement : la cible non vide sera refusee,
sans suppression ni remise a zero. Un script deja reussi est egalement refuse.
Le compte de maintenance MySQL n'est pas utilise pour ces migrations.

Verification en conteneurs jetables sur MySQL 8.0.46 avec les migrations reelles :
73 migrations, 46 tables, donnees metier vides, refus d'un `.env` modifie et d'une
base non vide. Le test verifie l'ecriture dans le stockage par le runtime,
la lecture seule du code et du `.env`, le refus d'acces au depot Git, aux secrets
de maintenance et aux fichiers prives par Apache, ainsi que la conservation des
fichiers/configurations et de la table Coffrac factices. Aucun port MySQL n'est
publie et aucun service du serveur reel n'est sollicite.

L'utilisateur confirme maintenant la fin de l'initialisation du schema sur le
serveur. L'etape Laravel a ete longue et silencieuse car sa sortie etait
redirigee dans le journal prive ; elle a termine sans import ni changement de
service. Ne pas relancer ce script initial sur la base maintenant structuree.

## Collecte actualisee de Coffrac

`tools/deploy/collect-production-coffrac.sh --export-current-coffrac` collecte
la base source `fiche_crm`, sans bootstrap de l'ancien Laravel :

- Utilise la connexion locale de maintenance existante et verifie les tables
  metier attendues. Refuse les tables de base non InnoDB, sauf la seule table
  `clients` en MyISAM apres acceptation explicite par l'option ci-dessus. Ne
  transforme aucun moteur et n'ajoute aucun verrou explicite.
- Produit un dump complet avec `--single-transaction --quick`, sans purge GTID,
  sans flush global et a priorite reduite. Les lignes InnoDB sont lues dans une
  transaction ; `clients` MyISAM reste une lecture en direct. Inclut donnees,
  structures, vues, triggers, routines et evenements dans la sauvegarde privee.
  Ne pas faire de modification de schema source pendant la collecte. Une lecture
  MyISAM peut retarder des ecritures pendant sa duree ; ne pas promettre une
  absence totale d'impact simplement parce que les verrous explicites sont exclus.
- Ecrit seulement dans un nouveau dossier root 0700 sous
  `/var/lib/cofrac2-production/coffrac-source-*`. Le dump, les erreurs et les
  inventaires restent en 0600 ; aucune information de dossier n'est affichee.
- Conserve un inventaire des tables et colonnes, sans valeurs de lignes ni
  valeurs par defaut. Les volumes et nombres de lignes d'`information_schema`
  sont des estimations, distinctes des comptages exacts du futur import.
- Controle gzip et SHA-256. Un export incomplet reste nomme `.partial`, sans
  manifeste de validation. Un verrou local refuse deux exports simultanes.
  Compare aussi la liste des tables/moteurs avant et apres collecte : un
  changement detecte invalide l'export, sans pretendre detecter tout DDL transitoire.
- Indique les horaires et la limite de coherence dans `collection.txt` :
  `collection_type=online_preparation_snapshot`,
  `data_consistency=mixed_engines_not_atomic`, `non_transactional_tables=clients`
  et `final_cutover_ready=no` pour le schema mixte actuel. Les checksums prouvent
  l'integrite des fichiers produits, pas la coherence globale de la source active.
- Ne restaure aucun SQL, ne modifie aucune ligne source ou V2, ne lance aucun
  service et ne copie aucun document.

Test reel en conteneurs jetables MySQL 8.0.46 : dumps InnoDB et mixte contenant
les objets attendus, integrite gzip/SHA-256, conservation des donnees source et
V2 ainsi que des fichiers/configurations factices. Le journal SQL verifie
l'absence d'ecritures, de `FLUSH` et de `LOCK TABLES` pendant les collectes.
Verification du refus des moteurs inattendus, de MyISAM sans option explicite,
d'un export concurrent, d'un echec force du client et d'un moteur change pendant
la collecte. Ces ALTER de test restent confines aux conteneurs jetables.

Commande de preparation pour le schema confirme :

```bash
sudo -H -u cofrac2-deploy \
    git -C /var/www/cofrac2 pull --ff-only origin v2 &&
sudo bash /var/www/cofrac2/tools/deploy/collect-production-coffrac.sh \
    --export-current-coffrac --allow-live-myisam-clients
```

Cette collecte est un cliche de preparation/recette. Les employes continuant a
travailler sur Coffrac, une nouvelle collecte coherente sera necessaire lors de
la bascule finale ; ce premier dump ne constitue pas une synchronisation live.
Le dump contient des donnees personnelles et des hashes d'authentification : ne
pas le publier ni le recopier dans le repertoire Web ou le home du runtime PHP.

Ne jamais restaurer ce dump brut dans `cofrac2production`, dont le schema est
natif TechCalendar. L'importeur `Simulation\SnapshotImporter` et les seeds de
demo ne conviennent pas a la production : ils remplacent les mots de passe,
ajoutent un administrateur de simulation et n'activent pas tous les comptes
historiques. La reprise de production doit reutiliser les projections metier
sans ces comportements, avec rapprochements et droits explicites, controles
de facturation et de visibilite, puis un rapport avant toute ouverture externe.

## Audit du dump actualise, 23 octobre 2026

Les etapes HTTP, certificat, HTTPS et timer dedie ont ete executees avec succes
sur le serveur. L'acces HTTPS a aussi ete controle depuis le poste local : HTTP
503 avec la page d'attente prevue. L'application V2 n'est pas encore ouverte.
Le certificat emis expire le 21 janvier 2027 ; son renouvellement dedie est actif.

La collecte source est conservee dans
`/var/lib/cofrac2-production/coffrac-source-20261023T175042Z-KiAdur69`.
Le dump compresse fait 36350412 octets, collecte terminee a 17:50:54 UTC.
Sa copie locale a ete verifiee par SHA-256 et gzip, puis restauree en MySQL
8.0.46 jetable, sans reseau, port publie ou fichier hote monte. Le planificateur
SQL, LOCAL INFILE et les exports de fichiers SQL sont desactives dans la copie.
Les estimations du fichier `tables.tsv` ne sont pas les comptages ci-dessous.

### Effectifs exacts constates

| Source | Lignes |
| --- | ---: |
| Utilisateurs | 288 |
| Dossiers | 17624 |
| Fiches de dossiers | 16986 |
| Types de prestations | 57 |
| RDV de dossiers | 16693 |
| RDV externes | 3755 |
| References documentaires de dossiers | 104932 |
| Chemins documentaires distincts, comparaison binaire | 104599 |
| Commentaires | 65739 |
| Historique | 130412 |
| Entreprises/installateurs | 1731 |
| Modeles email / SMS | 14 / 2 |
| Lots Coffrac | 1 |
| Ancienne table `clients` | 0 |

Les comptes clients sont dans `users`, pas dans la table vide `clients`.
Les types source sont : 34 super-admins, 2 referents techniques, 1 agence,
63 techniciens terrain, 177 entreprises/clients/installateurs/partenaires,
1 auditeur et 10 delegataires. Le type 6 est AUDITEURS : ne pas le projeter
comme organisation cliente. Le rapprochement historique V2 a ete corrige pour
ne creer des organisations externes qu'a partir des types 5 et 7.

Decision utilisateur confirmee : les 34 super-admins source deviennent des
gerants V2 (`role=0`, `admin=false`), avec requalification planning possible
ensuite. Seul le compte du seeder obtient l'administration globale. Deux de
ces 34 comptes sont inactifs/archives : ne pas les reactiver. Les quatre
profils atypiques referent/agence/auditeur sont importes desactives, avec leur
historique conserve, sur confirmation utilisateur du 23 octobre. Ne pas les
confondre avec les partenaires ni les activer avant requalification explicite.
Les champs d'autorisation source sont conserves dans le rapport de preparation.

Les 288 hashes sont reconnus comme bcrypt PHP valides en forme, couts 10 ou 12,
sans impression des hashes ni connaissance des mots de passe. Cela permet
d'envisager leur conservation ; cela ne constitue pas un test de connexion.
Il n'y a aucun doublon d'email apres normalisation lower/trim dans cet audit.
Les jetons et demandes de reset source restent exclusivement dans la sauvegarde.

### Ecarts a conserver et traiter explicitement

- 646 dossiers ne possedent aucune fiche source. Leur champ `status_id` de
  dossier vaut 1, ce qui ne suffit pas a inventer une prestation ou un etat de
  fiche. Les conserver sans fabriquer de donnees pour remplir l'interface.
- 1090 lignes de RDV ont une heure ou une affectation technique inexploitable :
  600 sans horaire complet, 499 sans technicien, avec neuf cas communs. Aucun
  lien dossier/fiche n'est orphelin ou incoherent. Trois derniers RDV encore
  rattaches a des etats attente/probleme sont incomplets, tous dans le passe
  au 23 octobre. Ne pas creer d'heure ou de technicien fictif pour les projeter.
- 16 affectations du champ client pointent vers un profil autre que le type 5 :
  15 internes et un delegataire. Garder les references dans le rapport de revue,
  sans creer automatiquement de comptes clients a partir de ces internes.
- 9 commentaires referencent un auteur absent. Conserver le commentaire et son
  identifiant auteur historique, sans generer un utilisateur de remplacement.
- 13096 dossiers possedent un client et un delegataire distincts : il faut
  maintenir les deux affectations et les trois audiences de contenu.
- 12978 dossiers sont actuellement marques factures via leurs fiches, mais
  14177 dossiers possedent une preuve historique de passage au statut 11.
  Les 1199 dossiers sortis de cet etat doivent conserver le blocage de double
  facturation. La table de facturation est vide : ne pas limiter la reprise
  comptable a cette table ni inventer des paiements ou numeros de facture.
- Les 104932 references ne prouvent pas la presence physique des fichiers :
  celle-ci reste a controler sur le serveur pendant la copie complete. Les
  doublons de chemins sont des references partagees, pas des documents a supprimer.

L'outil local `tools/fixtures/audit-production-coffrac.sh` reproduit la restauration
et les controles agreges dans un nouveau dossier prive. Il refuse le serveur
de production, supprime son conteneur et son volume a la fin et ne charge aucun
`.env` ou bootstrap Laravel. Le rapport scelle exige `native_import_ready=false`
et `final_cutover_ready=false` : un audit reussi n'est pas un import valide.
Il exclut les valeurs individuelles et refuse des champs supplementaires comme
les mots de passe. Les tests couvrent aussi l'integrite de la source, les audits
incomplets, les doublons de comptages, les liens symboliques et les ecrasements.

### Repetition de l'import natif, locale uniquement

Les nouveaux outils sont sous `tools/fixtures/production/`. Ils ne fournissent
volontairement aucune commande d'import sur `cofrac2production` : la couverture
metier et la copie documentaire doivent encore etre validees. Ne pas remplacer
leurs controles de cible ni utiliser un seeder de simulation pour contourner cela.

`prepare-dataset.sh` restaure la collecte verifiee dans MySQL 8.0.46 jetable,
sans reseau, port publie ou montage hote. Il extrait les 21 tables metier en
JSONL, controle les comptages exacts et empreintes, et conserve separement les
hashes bcrypt dans `credentials.jsonl`. Aucun `remember_token`, jeton de reset,
jeton API ou job historique n'est projete. Les textes UTF32 des modeles mail
ET SMS sont conserves octet pour octet avec leur charset, pas convertis avec perte.
Le repertoire produit est prive et contient des donnees personnelles : ne jamais
le joindre a un ticket, committer ou placer dans un repertoire public.

`rehearse.sh` utilise un deuxieme MySQL jetable, sans aucun port publie, et un
processus PHP dans le meme reseau ferme. Le code et le dataset sont montes en
lecture seule ; le `.env` local et les caches applicatifs ne sont pas charges.
Le compte SQL n'a de droits que sur `cofrac2_native_rehearsal`. Les migrations
natives sont appliquees et seul `AdminUserSeeder` est execute. Les deux conteneurs
et le volume SQL sont supprimes a la sortie ; le rapport reste dans un nouveau
repertoire prive. Il n'est pas possible de relancer cette commande sur une base
de production, meme avec l'option de repetition activee.

Exemple sur le Mac, avec deux destinations nouvelles a chaque essai :

```bash
bash tools/fixtures/production/prepare-dataset.sh \
    "$HOME/Downloads/coffrac-production-20261023" \
    "$HOME/Downloads/coffrac-production-20261023/dataset-native-NOUVEL-ESSAI"

bash tools/fixtures/production/rehearse.sh \
    "$HOME/Downloads/coffrac-production-20261023/dataset-native-NOUVEL-ESSAI" \
    "$HOME/Downloads/coffrac-production-20261023/repetition-native-NOUVEL-ESSAI"
```

Repetition reelle validee le 23 octobre sur le dump actualise :

| Projection | Effectif verifie |
| --- | ---: |
| Comptes Coffrac, hashes inchanges | 288 |
| Super-admin issu du seul seeder | 1 |
| Organisations clients / delegataires | 187 |
| Dossiers natifs | 17624 |
| Fiches natives | 16986 |
| RDV natifs exploitables, historiques et externes inclus | 19356 |
| Commentaires et historiques natifs | 196151 |
| Lignes metier conservees dans l'archive | 358257 |
| Preuves de facturation par fiche | 14181 |
| Dossiers distincts proteges par ces preuves | 14177 |
| Sessions, jetons ou envois reactives | 0 |

Les dossiers/fiches gardent leurs identifiants source. Les correspondances
des comptes sont dans `coffrac_user_links`, jamais deduites d'un nom ou d'un
email approximatif. Une collision entre l'email du seeder et un compte source
annule l'import, au lieu d'elever ce compte en super-admin. L'import est
transactionnel et un second passage du meme dataset ne cree aucun doublon.
Les projections communes avec la simulation sont extraites dans
`App\Services\Coffrac\CoffracProjector`, sans retirer les garde-fous de simulation.

Les 1090 RDV incomplets restent archives avec leurs identifiants de revue.
Deux autres RDV depassent la longueur native de l'adresse : meme traitement,
sans tronquer silencieusement ni inventer un horaire. Les 646 dossiers sans
fiche restent natifs, sans prestation fictive. Les coordonnees GPS absentes ne
sont pas fabriquees ; aucun geocodage externe n'est appele pendant la reprise.

Le rapport reste explicitement `native_import_ready=false` et
`final_cutover_ready=false`. Points encore bloquants : copie documentaire
complete et empreintes, validation des permissions et categories des portails
externes, qualification des techniciens/durees de prestations, mapping natif
des lots/satisfactions/modeles mail et SMS, et strategie de photographie finale.
Dans cette copie, aucun dossier n'a de `lot_id` et aucune fiche ne renseigne
`is_favorable` : ne pas inventer des rattachements ou une satisfaction a partir
des libelles libres. Les 8 valeurs `avis_satisfaction` de dossier et tous les
champs de rapport restent dans l'archive pour rapprochement explicite.

Les 187 comptes externes sont crees mais `portal_enabled=false`, permissions
et categories explicites vides : l'ancien profil delegataire n'autorise que la
categorie rapport, ce qui n'equivaut pas automatiquement aux nouvelles categories.
Leurs acces seront ouverts apres traduction et controle des droits, pas par
application des valeurs permissives par defaut de la V2.

## Nouveau vhost et certificat HTTPS independants

Le DNS controle depuis le poste de preparation le 23 octobre retourne
`46.105.110.76` pour `cofrac2.geniuscontrole.com`, sans AAAA ni CAA observes.
L'obtention effective du certificat reste a executer sur le serveur ; les tests
locaux n'utilisent ni le serveur reel ni l'autorite publique.

`configure-production-web.sh` ajoute le seul fichier
`/etc/apache2/sites-available/cofrac2.geniuscontrole.com.conf` et son lien actif.
Il exige `--reload-shared-apache`, refuse un domaine deja configure ailleurs,
verifie les modules deja charges sans en activer de nouveaux, puis effectue
`configtest` et un rechargement gracieux du service partage. Il compare les
autres configurations actives et le PID du processus maitre Apache. Sur echec
de validation, il restaure uniquement son propre vhost et son activation.

Les deux phases servent une page d'attente HTTP 503, sans aucun PHP ni acces
Laravel. L'absence de dossiers dans l'interface n'est pas un motif pour ouvrir
la base vide : importer, rapprocher et valider les donnees avant d'activer le
pool FPM V2, le worker, Reverb et le vhost applicatif. Le nouveau webroot ne
contient que les challenges ACME ; aucune piece metier n'y est copiee.

### 1. Challenge HTTP du nouveau domaine

```bash
sudo -H -u cofrac2-deploy \
    git -C /var/www/cofrac2 pull --ff-only origin v2 &&
sudo bash /var/www/cofrac2/tools/deploy/configure-production-web.sh \
    --enable-http-challenge --reload-shared-apache
```

Un HTTP 503 sur la racine est volontaire. Seuls les fichiers de challenge
`/.well-known/acme-challenge/<token>` sont servis en HTTP ; les alias globaux
comme phpMyAdmin ne doivent pas traverser cette fermeture.

### 2. Certificat dedie, sans installateur Apache

Certbot est deja present dans l'inventaire serveur. Utiliser `certonly --webroot`,
jamais `--apache` ni `--standalone` : aucun arret de serveur ni reecriture
automatique des anciens vhosts. Les repertoires, comptes ACME et renouvellements
V2 restent distincts de `/etc/letsencrypt` et des certificats existants.
`--config /dev/null` et `--no-directory-hooks` evitent la configuration et les
hooks globaux existants. Renseigner l'email et accepter les conditions dans le
terminal interactif, sans utiliser de faux email dans le depot.

```bash
sudo certbot certonly --webroot \
    --config /dev/null --no-directory-hooks \
    --config-dir /var/lib/cofrac2-letsencrypt \
    --work-dir /var/lib/cofrac2-certbot-work \
    --logs-dir /var/log/cofrac2-certbot \
    --webroot-path /var/lib/cofrac2-acme \
    --cert-name cofrac2.geniuscontrole.com \
    --domain cofrac2.geniuscontrole.com
```

En cas d'echec, conserver le vhost HTTP et lire le diagnostic : ne pas multiplier
les demandes de certificat ni desactiver un ancien site pour liberer le port 80.

### 3. HTTPS ferme pendant la reprise

```bash
sudo bash /var/www/cofrac2/tools/deploy/configure-production-web.sh \
    --enable-https-maintenance --reload-shared-apache
```

Le script controle nom, duree de validite et confiance TLS par une requete locale
avec SNI, sans `--insecure`. HTTP redirige temporairement vers HTTPS, sauf le
challenge de renouvellement. Le certificat et la cle restent uniquement dans
le stockage Certbot dedie ; aucune copie dans le depot ni le webroot.
Verifier ensuite `https://cofrac2.geniuscontrole.com` depuis un navigateur
exterieur : une page d'attente 503 est normale, pas un echec TLS.

### 4. Renouvellement automatique propre a la V2

```bash
sudo bash /var/www/cofrac2/tools/deploy/enable-production-certificate-renewal.sh \
    --enable-dedicated-renewal
```

Ce script installe uniquement `cofrac2-certificate-renewal.service/.timer` et
`/usr/local/sbin/cofrac2-renew-certificate`. Le timer controle le certificat
deux fois par jour avec delai aleatoire. Le hook recharge gracieusement Apache
uniquement apres renouvellement effectif, et seulement si `configtest` reussit.
Ne modifier aucun timer ni renouvellement Certbot global. Le rechargement du
gestionnaire systemd ne redemarre pas les services applicatifs existants.

Test reel HTTP/HTTPS sur Apache 2.4 jetable, sans reseau externe ni port publie :
challenge lisible, fermeture des routes/applications/alias globaux, validation
TLS avec autorite de test locale, vhost initial inchange, refus sans consentement
au reload et retour arriere sur configuration invalide ou echec du controle TLS.
L'emission et le renouvellement Let’s Encrypt reels restent des controles serveur.

## Correctifs de dependances avant la production

L'audit du verrouillage V2 initial revelait 27 alertes sur sept bibliotheques.
Une mise a jour ciblee compatible avec PHP 8.4.1 corrige ces alertes, sans
changement de version majeure : Guzzle 7.16.0, PSR-7 2.13.2, Laravel 13.38.0,
CommonMark 2.10.4, Flysystem 3.36.0, HTTP Foundation/Yaml 8.1.9 et deux
dependances necessaires (Promises 2.6.0, Prompts 0.3.26).
Le minimum PHP declare et la plateforme de resolution sont maintenant 8.4.1.
Ces modifications sont realisees localement, jamais par un `composer update`
sur le serveur. L'audit Composer ne signale plus de vulnerabilite connue,
et les 1 098 tests PHP passent (8 957 assertions).

## Stockage documentaire et suites

Le lecteur `CollectedDossierDocuments` pourra lire la copie privee V2 a partir
d'une racine et d'un manifeste complets regeneres pour cette copie. La solution
initiale de lecture des originaux en place est abandonnee selon le perimetre
confirme ci-dessus. Le stockage natif des nouveaux uploads reste prive et
distinct ; aucune archive historique ne doit etre exposee par `public/storage`
ni aucun `.htaccess` ou executable historique active dans la V2.

Apres les controles : preparer la cle de deploiement GitHub limitee au depot,
cloner la revision V2 publiee, installer le runtime dedie et preparer la reprise
sur une base distincte. Les scripts `*-demo` du CT 124 ne ciblent pas ce serveur
et ne doivent pas y etre executes. Configurer ensuite le nouveau vhost, DNS et
TLS pour l'URL temporaire ; conserver la bascule du domaine historique pour
la fin de recette et la derniere reprise coherente.

Au stade de cet inventaire, aucun acces GitHub, clone, installation de paquet,
migration, changement DNS ou modification de Coffrac n'a ete effectue par l'agent.

## Copie documentaire complete : preparation du stockage prive

`tools/deploy/prepare-production-documents.sh` est independant de Laravel et de
MySQL : il n'utilise aucun `.env`, aucun credential SQL et n'execute aucun code
Coffrac. Il ne recharge, demarre ni arrete aucun service. Il refuse un autre
hostname que `ns365601`, un appel non root, des chemins symboliques ou une
preparation V2 absente. Une seule operation documentaire peut tourner a la fois.

Les six racines inspectees sont `public/documents`, `public/pdf`, `public/media`,
`public/signature`, `public/lot_dossier`, `storage/app`, sous le seul projet
`/home/ubuntu/www/coffrac_preprod`. Aucun filtre sur les dossiers factures, aucun
besoin de changer les droits de l'ancienne application. Les fichiers non
references dans la base sont aussi copies : le rapprochement DB signalera les
orphelins au lieu de les supprimer.

### 1. Inventaire sans copie

```bash
sudo bash /var/www/cofrac2/tools/deploy/prepare-production-documents.sh \
    --inventory-all-documents
```

Conserver le chemin `INVENTORY=...` affiche. La sortie terminal ne contient que
les compteurs, pas les noms des pieces. Les details restent dans un nouveau
repertoire root `0700` sous `/var/lib/cofrac2-production/documents-inventory-*`.
L'inventaire produit un JSONL et deux listes NUL-delimitees avec leurs empreintes.
Ne jamais reutiliser le manifeste filtre de la demonstration du 14 octobre.

Les liens symboliques et fichiers speciaux ne sont pas suivis/copies. Le code,
`.htaccess`, `.user.ini`, `.env*` et les metadonnees connues sont signales mais
non reutilises. Les extensions ou noms non reconnus sont conserves en quarantaine
privee, sans acces applicatif. Les fichiers vides sont copies mais exclus de la
liste servable. Toute erreur de lecture annule le scellement de l'inventaire.

### 2. Copie physique reprenable, apres examen du volume

```bash
INVENTORY="/var/lib/cofrac2-production/documents-inventory-CHEMIN-RETOURNE"
sudo bash /var/www/cofrac2/tools/deploy/prepare-production-documents.sh \
    --copy-all-documents "$INVENTORY"
```

Destination fixe : `/srv/cofrac2-document-archive/files`, hors de tout webroot.
La hierarchie des six racines est conservee. Un marqueur d'identite prive interdit
l'adoption d'un repertoire tiers. Dossiers `0700`, fichiers `0600`, pas de bit
executable, symlink, hardlink, reprise d'owner/groupe/ACL/xattr de la source.
Une reference source partagee ou hardlinkee devient une copie physique distincte.
Le transfert ne comporte ni `--delete`, ni `--inplace`, ni `--copy-links`, ni
recursion sur un chemin transforme en repertoire pendant la preparation.
Les fichiers source et leurs permissions ne sont jamais modifies par le script.

Avant copie, les identites, tailles et horodatages source doivent encore
correspondre a l'inventaire. Sinon, generer un nouvel inventaire et reprendre
vers la meme archive, sans effacer les copies deja presentes. La place requise
comprend la croissance restante, un fichier temporaire de taille maximale et
une reserve de 50 Gio. La reserve est surveillee toutes les cinq secondes ; une
mesure impossible ou insuffisante interrompt le transfert. D'autres services
peuvent consommer du disque : ce controle ne remplace pas la supervision hote.

La copie est limitee a 32 Mio/s, en priorite CPU/I/O basse, avec fichiers partiels
reprenables et synchronisation disque des fichiers recus. Pour environ 217 Gio,
le debit plafond seul represente pres de deux heures ; les petits fichiers,
les acces disque et les controles s'ajoutent. Le terminal reste occupe et montre
la progression. Ne pas l'interpreter comme un blocage avant de lire le resultat.

### 3. Verification de contenu obligatoire

```bash
sudo bash /var/www/cofrac2/tools/deploy/prepare-production-documents.sh \
    --verify-all-documents "$INVENTORY"
```

Cette phase lit et calcule SHA-256 sur chaque original ET chaque copie. Elle
verifie aussi l'independance des fichiers, les permissions et une nouvelle passe
de metadonnees source apres les hashes. Les pieces absentes, remplacees, devenues
symboliques ou corrompues empechent `copy_verified=true`. Les extras destination
sont conserves et signales, jamais supprimes. Les resultats et hashes restent
prives dans un nouveau `/var/lib/cofrac2-production/documents-run-*`.

Un code retour rsync 0, une taille identique ou un timestamp identique ne
prouvent pas l'integrite du contenu. Si le controle signale une corruption de
copie alors que la source est toujours identique a l'inventaire, le mode explicite
suivant force une comparaison de contenus pour reparer la copie, puis il faut
reverifier. Il ajoute une lecture complete et ne doit pas masquer un original
qui a change : dans ce dernier cas, refaire d'abord un inventaire.

```bash
sudo bash /var/www/cofrac2/tools/deploy/prepare-production-documents.sh \
    --repair-copy-all-documents "$INVENTORY"
sudo bash /var/www/cofrac2/tools/deploy/prepare-production-documents.sh \
    --verify-all-documents "$INVENTORY"
```

### Limites et suite

Les employes continuent de travailler : l'ensemble n'est pas une photographie
atomique SQL/fichiers, et un nouveau document apres inventaire n'entre pas dans
cette selection. `final_cutover_ready` et `database_links_verified` restent
explicitement faux, meme apres hashes conformes. La copie initiale permet
seulement de preparer le volume ; une collecte finale, un inventaire et un passage
differentiel coherents restent necessaires avant bascule.

Aucun droit de traverse au runtime V2, aucun parametre `CRM_COLLECTED_DOCUMENTS_*`
et aucun acces HTTP ne sont actives par ces outils. La liste servable est relative
a `files/public/documents`, mais ne doit pas etre branchee avant rapprochement
avec les metadonnees et audiences de la base finale. Les autres racines restent
privees jusqu'a leur traitement explicite. Ne supprimer aucun original : recette,
controle d'independance et sauvegarde restaurable externe sont requis avant toute
approbation distincte de suppression.

Verification locale : tests unitaires de chemins, contenu, permissions et
inventaires alteres, plus `tools/deploy/tests/check-production-documents.sh`
dans un conteneur jetable sans reseau et sans montage des vrais repertoires.
La fixture teste interruption/reprise rsync, manque de place, checksum d'une
copie corrompue a taille/mtime inchanges, reparation explicite et absence de tout
appel SQL ou service. Elle compare aussi les contenus, identites et metadonnees
originaux avant/apres ; aucune commande de fixture ne doit tourner sur l'hote.

### Copie initiale validee sur le serveur

Sortie communiquee par l'operateur le 24 octobre 2026 :

- Inventaire : `/var/lib/cofrac2-production/documents-inventory-20261023T190847Z-qDf2CmAZ`.
- Verification : `/var/lib/cofrac2-production/documents-run-20261023T225113Z-oFnSjkVt`.
- 126447 fichiers et 232349323775 octets verifies, zero anomalie et zero extra.
- 53 fichiers vides et 63 en quarantaine conserves, sans exposition.
- `copy_verified=true`, `JOB_EXIT_CODE=0`, verification terminee le
  `2026-10-24T01:29:47+00:00` (03:29:47 heure de Paris).
- `database_links_verified=false`, `final_cutover_ready=false` : ce resultat
  ne prouve ni le rapprochement SQL, ni une photographie finale atomique.

### 4. Rapprochement de preparation avec les references SQL

Le nouveau controle ne charge ni Laravel, ni le `.env`, ni MySQL sur le serveur.
Le Mac extrait uniquement les identifiants, chemins, audiences et empreintes
des lignes depuis le dataset de production scelle. Le paquet de references ne
contient aucun hash de mot de passe, jeton, texte de commentaire ou questionnaire.
Les noms de fichiers peuvent contenir des donnees personnelles : paquet prive,
jamais dans Git, un webroot, un ticket ou une piece jointe publique.

```bash
php -d memory_limit=512M tools/fixtures/production/export-document-references.php \
    "$HOME/Downloads/coffrac-production-20261023/dataset-native-20261023" \
    "$HOME/Downloads/coffrac-production-20261023/references-NOUVELLE-SORTIE"
```

Sur le dump de production du 23 octobre, l'extraction donne 104932 documents de
dossiers, 2 `fiche_document` et 2 `media`. Les 4365 champs `dossier_fiches.rapport`
renseignes sont des questionnaires JSON, pas des fichiers : ils restent dans
l'archive metier. Les champs explicitement fichiers `rapport_pdf` et `signature`
sont egalement exportes s'ils sont renseignes (zero dans cette photographie).

Le paquet autonome distribue a l'operateur comprend les scripts
`stage-document-reconciliation.sh`, `reconcile-production-documents.sh`,
`reconcile-documents.php`, les deux bibliotheques dans `lib/`, `README.txt` et
les trois fichiers scelles dans `references/`. `SHA256SUMS` couvre chaque fichier.
L'entree `stage-document-reconciliation.sh INVENTORY VERIFICATION` controle le
paquet, en cree une copie root privee dans un nouveau repertoire sous
`/var/lib/cofrac2-production`, puis appelle le rapprochement. Aucun checkout
applicatif ou secret de production n'est remplace.

Si les references ont deja ete installees en root prive :

```bash
sudo bash tools/deploy/reconcile-production-documents.sh \
    /var/lib/cofrac2-production/document-references-PREPARATION/references \
    /var/lib/cofrac2-production/documents-inventory-20261023T190847Z-qDf2CmAZ \
    /var/lib/cofrac2-production/documents-run-20261023T225113Z-oFnSjkVt
```

Le controle valide le scellement et la couverture de l'inventaire, du rapport
SHA-256 reussi et de chaque reference. Les copies doivent toujours etre privees,
regulieres, independantes et de la taille attendue. Leurs contenus ne sont pas
rehashes : `copy_content_rehashed=false`, la preuve est celle de la verification
initiale. Une modification de contenu de meme taille n'est donc pas redetectee
ici ; le passage differentiel final avec verification complete reste obligatoire.

La correspondance automatique autorisee est exacte : `dossier_document.path`
vers `files/public/documents/PATH`, sans recherche approximative, basename de
secours, URL distante, normalisation de casse ou decodage d'URL. Les references
catalogue/media/rapport/signature attendent un traitement de portee explicite ;
leur presence dans une autre racine ne prouve pas une autorisation de dossier.

Le rapport et les listes `links.jsonl`, `issues.jsonl`, `unreferenced.jsonl` restent
root prives. Les documents absents, vides, en quarantaine, sans proprietaire ou
aux chemins dangereux sont signales sans suppression. Une meme copie peut
correspondre a plusieurs lignes : chaque dossier, identifiant source et audience
reste distinct. Le prive l'emporte toujours sur le drapeau delegataire. L'empreinte
de la ligne source permet un futur controle du contenu metier contre l'import.

`complete=true` signifie que toutes les references ont ete traitees, pas que
toutes ont ete retrouvees. `all_references_resolved` rend cette distinction
explicite. `database_links_verified=false`, `serving_enabled=false` et
`final_cutover_ready=false` restent obligatoires : pas de DB V2 consultee,
pas de droit runtime ouvert et pas d'import natif effectue par ce controle.

### Resultat du rapprochement initial et revue des exceptions

Rapport fourni par l'operateur le 24 octobre :
`/var/lib/cofrac2-production/documents-reconciliation-20261024T092837Z-76MciFyx/report.json`.
Le paquet de references root prive est dans
`/var/lib/cofrac2-production/document-references-20261024T092833Z-c8NRTzpI`.

- 104936 references traitees, dont 104785 retrouvees et 104455 fichiers lies
  distincts ; 330 references supplementaires partagent un fichier.
- 32745 documents internes, 70893 client uniquement, 1294 client et delegataire.
- 59 references en quarantaine, 72 absentes de l'inventaire verifie, 16 fichiers
  vides, 4 pieces de portee a verifier : 151 references restent indisponibles.
- 21918 fichiers conserves sans rattachement de dossier prouve. Ne pas les
  assimiler a des dechets : autres racines, fichiers generes, pieces annexes ou
  decalage entre dates SQL et inventaire sont notamment possibles.

`review-production-document-issues.sh INVENTORY VERIFICATION RECONCILIATION`
est un paquet autonome, independant du checkout. Il comprend le script, le CLI
`review-document-issues.php`, `lib/CoffracDocumentArchive.php`,
`lib/CoffracDocumentIssueReview.php`, `README.txt`, et leurs empreintes dans
`SHA256SUMS`. Il valide les preuves existantes, installe ses seuls outils en root
prive et conserve le meme verrou documentaire que copie et rapprochement.

Le diagnostic recoupe les exceptions de l'inventaire, observe par `lstat` les
chemins source exacts et signale les candidats exacts dans les six racines
deja verifiees. Il ne lit pas le contenu des originaux, n'execute aucun fichier,
ne consulte aucune DB, ne suit pas les symlinks observes et n'appelle aucun
service. Les classes suivantes sont distinguees : fichier exclu comme code ou
configuration, absence persistante, source presente mais hors inventaire,
quarantaine, fichier vide ou repare depuis la copie, portee/proprietaire non
prouves. Aucun candidat n'est rattache automatiquement : un nom identique
dans une autre racine n'est pas une preuve d'appartenance ou d'audience.

Le terminal ne montre que des compteurs et des noms de classes connus. Les
identifiants, chemins encodes et candidats restent dans `issues-review.jsonl`
root prive, accompagne du rapport et de leurs empreintes. La repartition des
fichiers sans reference est affichee uniquement par racine, jamais par nom.
Un fichier present actuellement n'est pas considere copie ou valide : un
nouvel inventaire et un passage differentiel avec hashes restent requis.

Les 151 references en revue ne seront ni supprimees, ni remplacees par des
pieces fictives, ni ouvertes aux portails pour contourner les controles.
La politique de traitement des exceptions et les derniers rapprochements
restent a approuver avant d'activer une reprise de production.

Diagnostic fourni ensuite par l'operateur :
`/var/lib/cofrac2-production/documents-issue-review-20261024T094528Z-Og3i6UdK/report.json`.
Les 72 references absentes de l'inventaire se repartissent en 67 chemins encore
absents de Coffrac et 5 fichiers exclus comme code/configuration. Les 59 references
en quarantaine, 16 fichiers vides et 4 pieces de portee non prouvee restent a
examiner. Quatre references ont au moins un candidat exact dans une autre racine,
ce qui ne constitue pas un rattachement valide. Les fichiers sans reference
prouvee se repartissent en 20578 sous `public/documents`, 324 sous `public/pdf`
et 1016 sous `public/media` ; ils sont tous conserves.

### 5. Verification des liens dans la repetition native, sans deuxieme gestion documentaire

`App\Services\Coffrac\DocumentReconciliationPlan` lit la copie portable du
rapprochement : `links.jsonl`, `issues.jsonl`, `unreferenced.jsonl`, `report.json`
et leur `SHA256SUMS`. Il ne demande ni les documents, ni la source Coffrac. Les
fichiers doivent rester prives, non symboliques et de meme proprietaire que leur
repertoire. Le montage de conteneur est en lecture seule ; le lecteur accepte
le proprietaire hote au lieu de lui imposer l'UID root du conteneur.

Le scellement, la couverture, les identifiants et chaque audience sont controles
contre `DocumentReferenceExport` et le dataset metier exact. Le SHA-256 du dump
ET le fingerprint du dataset doivent correspondre : un plan du 23 octobre ne
peut pas valider une collecte finale plus recente. Les liens dangereux ou de
portee non prouvee ne peuvent pas etre declares disponibles. Le lecteur
controle toutes les lignes avant de rendre le plan utilisable, et reverifie les
empreintes apres lecture pour refuser une modification intermediaire.

`NativeDocumentAudit` est volontairement limite a la cible de repetition isolee,
avec le meme garde-fou avant connexion SQL que l'importeur natif. Il exige la
reprise du meme dataset et verifie les `legacy_records` existants par lots de
400 : dossier parent natif, ligne metier complete, audiences selon le service
existant `ClientAccess`, absence de masquage et d'override. Aucun doublon n'est
cree dans `dossier_documents`, aucun droit n'est corrige automatiquement et aucun
fichier n'est ouvert. Les annexes sont comptees separement, sans parent invente.

Apres rapatriement prive des seuls rapports du serveur, exemple sur le Mac avec
une nouvelle destination de repetition :

```bash
bash tools/fixtures/production/rehearse.sh \
    "$HOME/Downloads/coffrac-production-20261023/dataset-native-20261023" \
    "$HOME/Downloads/coffrac-production-20261023/repetition-documents-NOUVELLE-SORTIE" \
    "$HOME/Downloads/coffrac-production-20261023/document-links-20261024"
```

Le plan, le code et le dataset sont montes en lecture seule. MySQL est jetable,
sans port publie et sans acces reseau externe ; le `.env` local n'est pas charge.
Le rapport ajoute `document_verification`. Son drapeau
`native_database_links_match_plan=true` prouve le rapprochement des metadonnees
dans cette repetition uniquement : il ne vaut ni verification physique locale,
ni ouverture des fichiers en production, ni validation finale de bascule.

Les 147 lignes de documents de dossier non liees ne doivent pas etre supprimees
de l'archive native et les 4 annexes restent a traiter a part. La reprise finale
et l'interface conserveront l'indisponibilite des fichiers absents/vides et le
blocage des pieces en quarantaine ou exclues. Ne pas presenter ces absences
preexistantes comme des fichiers perdus pendant la copie. La recherche dans une
sauvegarde externe et tout traitement de quarantaine restent des operations
distinctes a valider, sans suppression des preuves.

Repetition documentaire reelle validee le 24 octobre, apres rapatriement du
plan serveur. Rapport local prive :
`~/Downloads/coffrac-production-20261023/native-documents-rehearsal-20261024-vktT65AQ/report.json`.
Empreinte du plan portable :
`08b5d770a972b75a4e83ba2ad4720c96b1daeeba93aee97468755023cb4f4646`.
Le dataset reste celui du dump de production du 23 octobre, pas la demonstration.

- 104932 lignes documentaires rapprochees avec l'archive native : dossier parent,
  metadonnees et droits identiques. `native_database_links_match_plan=true`.
- 104785 references liees dans cette photographie, 147 documents indisponibles
  conserves et 4 annexes hors dossier laissees en revue.
- Audiences identiques : 32745 internes, 70893 client uniquement, 1294 partagees.
- Zero document natif duplique, zero ligne native modifiee par le controle,
  aucun original lu et aucun acces de fichier ouvert.
- Effectifs natifs inchanges : 289 comptes dont le seul super-admin seed,
  17624 dossiers, 16986 fiches, 19356 RDV exploitables, 187 organisations externes,
  196151 activites, 358257 lignes source archivees et 14181 preuves de facturation
  couvrant 14177 dossiers distincts.
- Hashes de comptes source identiques : 288/288. Quatre profils atypiques
  desactives, portails externes encore fermes, zero session/jeton/job reactive.
- Le second passage du meme import ne cree aucun doublon. Les deux conteneurs
  et leur volume SQL jetables ont ete supprimes a la sortie.

Ce succes clot la verification des liens documentaires pour cette collecte
uniquement. `native_import_ready=false` et `final_cutover_ready=false` restent
justifies par les correspondances metier restantes (droits externes,
qualifications/durees, lots/satisfactions, modeles mail/SMS), les donnees incompletes
et la collecte finale coherente avant bascule. Les 1092 RDV non projetables restent
dans l'archive source ; ne pas les compter comme des rendez-vous natifs exploitables.

### 6. Modeles de communication et limites des donnees techniciens

`LegacyTemplateText` lit les champs UTF-8 des anciens snapshots ou les couples
`*_base64` / `*_charset=utf32` du nouvel export. Le charset MySQL est decode en
UTF-32 big-endian, avec verification de validite et de retour aux octets source.
Les coupures de ligne de `TO_BASE64()` MySQL sont acceptees ; les autres blancs
ou variantes non canoniques sont refuses. Ce cas est teste avec des lignes de
76 caracteres, comme dans le dataset reel.
Les representations concurrentes, encodages inconnus, controles, octets invalides
ou tailles excessives sont refuses, sans remplacement silencieux. L'archive
`legacy_records` conserve les octets encodes originaux et leur empreinte.

`NativeCommunicationTemplates` s'execute uniquement dans la repetition isolee,
avec le garde-fou de l'importeur avant connexion SQL. Il refuse un catalogue mail
natif non vide. Les modeles acceptes reutilisent `mail_templates`, avec un slug
stable `coffrac-mail-ID` et la correspondance d'identifiants dans le rapport prive.
Ils sont tous desactives, sans expediteur ni auteur technique invente. Aucune
configuration SMTP n'est deduite de l'ancien texte ou importee depuis le `.env`
Coffrac. Choisir un expediteur et valider chaque modele avant activation.

Les variables Coffrac connues sont traduites vers celles du rendu natif :
beneficiaire, adresse, technicien, societe et date/plage complete du rendez-vous.
Le texte litteral est protege contre une reinterpretation Markdown et les retours
a la ligne sont conserves. Aucun Blade/PHP n'est evalue. Une variable inconnue,
du HTML ou autre balisage a revoir, un sujet multiligne ou une limite native
depassee laisse le modele uniquement dans l'archive, avec une raison explicite.
Le sujet et le corps sont reverifies apres expansion des variables de modele.

Les SMS n'ont pas de deuxieme catalogue : `DossierSmsService::templates()` lit
les deux formats de texte dans les memes `legacy_records` existants. Les lignes
endommagees, ambigues ou vides apres nettoyage sont ignorees et signalees dans
le rapport de reprise. L'apercu/confirmation explicite et le controle des variables
manquantes restent ceux du service SMS existant. Lire ou reprendre un modele
n'active pas SMSFactor, ne cree pas de SMS/job et ne contacte aucun fournisseur.
La repetition garde les SMS desactives et le mail sur le transport local log/array.

Le champ utilisateur Coffrac `dpt` ne prouve pas un secteur d'intervention : pour
les 63 techniciens du dump, 23 valeurs sont absentes, 21 ressemblent a un code
departement, 18 a un code postal et une est libre. Ni cette valeur, ni l'adresse,
ni les anciens RDV ne doivent generer automatiquement de `department_user`.
Les 63 comptes sont donc signales pour rapprochement de leurs secteurs,
y compris ceux deja inactifs ; leurs donnees source restent archivees. Les durees
de prestation restent egalement a valider : ne pas presenter la valeur native
par defaut comme une duree issue de Coffrac.

Decision explicite de l'operateur le 24 octobre : chaque technicien importe
recoit par defaut toutes les prestations natives Coffrac. La reprise cree les
`service_user` correspondants, jamais pour les autres profils ou le super-admin
seed et jamais pour la prestation technique d'occupation creee pour les RDV
externes. Un compte inactif reste inactif ; ces habilitations ne le reactivent pas.
Le rapport distingue cette politique explicite de toute deduction historique.

Les secteurs seront repris depuis une nouvelle collecte de l'ancien
TechCalendar, par correspondance exacte d'e-mail normalise, pas depuis le vieux
snapshot de demonstration. `prepare-techcalendar-export.php CHEMIN --planning-only`
genere un collecteur autonome en lecture seule : departements, techniciens,
affectations de departements et durees des prestations. Pas de mots de passe,
configurations SMTP/API, dossiers, documents ou comptes externes. Il refuse une
configuration demo/test/simulation et ne demarre pas les providers Laravel.
Le snapshot transactionnel est termine par rollback ; seuls l'export compresse
et son checksum sont ecrits dans un nouveau repertoire prive hors du projet.

Repetition reelle validee ensuite dans MySQL 8.0.46 jetable, avec les secteurs
encore en attente :
`~/Downloads/coffrac-production-20261023/native-business-rehearsal-20261024-Be62ve4B/report.json`.
Les 14 mails sont repris, tous desactives et sans expediteur ; les deux SMS
sont lisibles dans le catalogue existant. Les 63 techniciens ont chacun les
57 prestations Coffrac, soit 3591 affectations natives. Aucun departement n'a
ete deduit du champ `dpt` et aucun compte source n'a ete reactive.

Les autres effectifs restent identiques a la repetition documentaire precedente,
avec 288 hashes de comptes preserves, le seul super-admin seed, zero portail
externe active, zero session/jeton/job importe et zero message sortant. Le second
passage ne cree aucun doublon. Le rapprochement des 104932 documents de dossier
contre le meme plan portable reste valide, sans modification ni ouverture de
fichier. Les deux conteneurs et leur volume sont supprimes en sortie.

La suite complete comprend alors 1211 tests et 9408 assertions, tous passes.
Ce resultat ne constitue toujours pas une importation ou une ouverture de la
production ; les droits externes, les secteurs et durees a rapprocher, les lots
et les dernieres donnees coherentes avant bascule restent a traiter.

### 7. Reprise des secteurs depuis la collecte TechCalendar actualisee

L'export de la V1 conservee dans `/var/www/TechCalendar` sur le CT TechCalendar
a ete collecte le 24 octobre a 20 h 27 (Europe/Paris) puis rapatrie sur le Mac.
Il ne provient ni de la demo V2, ni du snapshot du 14 octobre. Empreinte gzip :
`433509bf54661728440fe8b8cb46e327e6484d4c6b12e2014740311bbd870a9e`.
Repertoire prive local :
`~/Downloads/coffrac-production-20261023/techcalendar-planning-20261024`.
Il contient 101 departements, 13 techniciens, 86 affectations et 20 prestations.

`TechCalendarPlanningSnapshot` exige cette empreinte fournie independamment du
fichier. Il controle permissions privees, absence de symlinks, proprietaire,
limites de taille gzip/JSON, origine V1 production, schemas exacts, comptages,
identites, profils techniciens et integrite des affectations. Aucune colonne
supplementaire ou credential ne peut etre acceptee dans les lignes.

`CoffracNativeImporter::rehearse()` peut recevoir cette seconde source en option.
`NativeTechCalendarPlanning` utilise uniquement une egalite d'e-mail normalise
(trim et casse), jamais un nom ou un identifiant suppose commun aux deux bases.
Les e-mails absents/ambigus, les comptes TechCalendar inactifs et les departements
inconnus restent en revue. Aucun compte n'est cree, reactive ou change de profil.
Le departement principal et les affectations d'intervention restent distincts ;
les horaires sont copies uniquement s'ils sont coherents. Une duree moyenne
n'est reprise que pour un libelle Coffrac identique et non ambigu, sans modifier
la duree historique des RDV. Les habilitations aux 57 prestations ne changent pas.

Le rapport conserve l'empreinte des deux sources. Un second passage avec une
collecte planning differente (ou retiree) est refuse avant tout changement. Les
lectures/projections restent limitees a la repetition isolee, sous la transaction
native globale ; il n'existe toujours pas de commande d'import de production.

Exemple local, avec une nouvelle sortie et une collecte documentaire deja verifiee :

```bash
bash tools/fixtures/production/rehearse.sh \
    "$HOME/Downloads/coffrac-production-20261023/dataset-native-20261023" \
    "$HOME/Downloads/coffrac-production-20261023/repetition-planning-NOUVELLE-SORTIE" \
    "$HOME/Downloads/coffrac-production-20261023/document-links-20261024" \
    "$HOME/Downloads/coffrac-production-20261023/techcalendar-planning-20261024" \
    433509bf54661728440fe8b8cb46e327e6484d4c6b12e2014740311bbd870a9e
```

Repetition reelle validee dans MySQL 8.0.46 jetable :
`~/Downloads/coffrac-production-20261023/native-planning-rehearsal-20261024-PNWU5UPF/report.json`.
Les 10 comptes rapproches recuperent 68 affectations de departements et leurs
horaires. Les 53 autres comptes Coffrac, dont 41 actifs, n'ont pas de correspondance
exploitable dans cette collecte. Trois techniciens TechCalendar ne sont pas
crees dans Coffrac par la reprise. Seule la duree de `AGRI TH 117` est reprise
par libelle identique (90 minutes) ; les 56 autres restent a rapprocher/valider.
Les 3591 habilitations, 288 hashes source, effectifs metier et liens documentaires
sont preserves ; aucun job, message, portail ou acces fichier n'est active.

Limite constatee : les dix comptes rapproches n'ont aucun GPS de depart dans
la source Coffrac et la collecte planning ciblee n'exportait pas ceux de
TechCalendar. Il reste donc necessaire de lire les reglages complets, incluant
points de depart, absences et anciennes correspondances de prestations. La
commande generee avec `--production-settings` reutilise le collecteur de
references complet deja existant, sous le meme garde-fou d'origine production.
Elle n'exporte aucun mot de passe, token, session, parametre SMTP/API ou contenu
de fichier et ne change aucun service. Les anciennes correspondances serviront
uniquement de preuve pour la migration, sans recreer une gestion de connecteur.

Paquet autonome de complement prepare sur le Mac :
`~/Downloads/cofrac2-techcalendar-settings-20261024-OZUHdGoW`.
`fetch-techcalendar-settings.sh` verifie son paquet local, transmet le collecteur
en entree standard SSH au seul CT `root@10.100.0.124` et recupere les deux fichiers
prives automatiquement. Il n'installe rien dans le projet distant. Les chemins
temporaires et le SHA de retour sont strictement valides avant transfert ; le
manifeste ne peut designer que `references.json.gz`. La verification finale
recoupe le manifeste ET l'empreinte recue par SSH, puis teste gzip. Les executions
factices couvrent succes, chemin distant inattendu et fichier corrompu, sans
appel reseau reel. La suite applicative comprend 1245 tests / 9513 assertions,
tous passes. Ce complement n'a pas encore ete execute par l'agent sur le CT.

### 8. Reglages complets recuperes et rapproches

L'operateur a execute le complement le 24 octobre a 20 h 48 (Europe/Paris).
Le fichier est conserve sur le Mac dans
`~/Downloads/coffrac-production-20261023/techcalendar-settings-20261024-l3PDvJ42`.
Empreinte gzip attendue et verifiee independamment :
`91a9bb5e7c8412c7531d99d3e1d818226f61f68f01fa966a7a07c0989ea242fb`.
Il contient les dix tables du contrat de references : 14 comptes (13 techniciens
et un gerant/admin), 101 departements, 20 prestations, 29 anciens aliases,
209 habilitations, 86 affectations, 10 organisations, un expediteur, zero modele
mail et zero absence. Aucun secret ou contenu de document n'est collecte.

`TechCalendarPlanningSnapshot` accepte maintenant ce contrat complet OU le
precedent contrat planning cible, sans assouplir le controle de leur schema.
Les colonnes et relations supplementaires sont validees, notamment les cibles
de prestations des aliases et les habilitations. Seuls les profils techniciens
participent aux rapprochements. Le compte admin TechCalendar ne peut pas devenir
un second super-admin ni modifier les privileges d'un compte Coffrac.

La projection verifie egalement l'e-mail et le role du compte natif cible avant
de le modifier. Un point de depart n'est copie qu'avec une adresse exploitable et
une paire GPS numerique finie dans les bornes geographiques ; le couple 0/0 est
refuse comme valeur de remplissage. Aucune geolocalisation n'est inventee et
aucun service de geocodage n'est appele. Une source invalide ne vide pas un point
de depart existant. Les dossiers et leurs adresses ne sont pas modifies.

Les aliases historiques constituent uniquement une preuve de correspondance
pour les durees moyennes. Source `coffrac`, type `COFFRAC`, libelle externe exact
et cible Coffrac existante sont requis. Un rapprochement direct et un alias
vers la meme cible sont dedupliques ; des cibles contradictoires restent en
revue. Le rapport garde l'identifiant du service TechCalendar et des aliases
utilises. Aucun alias n'est insere dans la V2, aucun catalogue natif n'est fusionne
ou renomme et aucune duree de rendez-vous historique n'est changee. Les anciennes
209 habilitations ne remplacent pas la politique explicite des 3591 habilitations.

La source actuelle n'a aucune absence, ce qui est verifie dans le rapport. Un
futur export non vide ne doit pas etre accepte silencieusement : ce contrat ne
porte pas le fuseau source, donc ces lignes restent en revue temporelle/identitaire
et ajoutent un verrou de reprise explicite, sans insertion ou auteur invente.

Repetition complete reelle validee dans MySQL 8.0.46 jetable :
`~/Downloads/coffrac-production-20261023/native-settings-rehearsal-20261024-tXLGiaMi/report.json`.
Elle reutilise le meme dump Coffrac de production du 23 octobre et le meme plan
documentaire scelle, avec cette collecte TechCalendar plus recente :

- 10 techniciens rapproches, 68 affectations, horaires et 10 points de depart GPS.
- 29 durees natives reprises avec preuve ; 28 prestations encore sans duree
  rapprochee, gardant la valeur native de 60 minutes a faire valider.
- 53 techniciens Coffrac sans correspondance, dont 41 actifs, sans secteur deduit.
  Trois techniciens TechCalendar ne sont pas ajoutes ou transformes en comptes Coffrac.
- 289 comptes natifs dont un seul super-admin seed ; 288 hashes source inchanges,
  3591 habilitations, 17624 dossiers, 16986 fiches et 19356 RDV exploitables.
- 104932 documents de dossier rapproches, audiences inchangees, aucun document
  natif duplique, aucune ouverture de fichiers ni lecture des originaux.
- Aucun portail, job, jeton ou message reactive. Reprise identique sans doublon.

Les conteneurs et le volume SQL ont ete retires en sortie. La suite complete
passe : 1266 tests, 9583 assertions. Cette etape ne modifie pas le serveur et ne
constitue toujours pas une ouverture ou une importation de production. Les
parametres manquants, droits externes, lots et collecte finale coherente restent
distincts ; le rapport conserve `native_import_ready=false` et
`final_cutover_ready=false`.

### 9. Droits externes et preuves de lots/satisfaction

`NativeExternalAccessReview` et `NativeLotSatisfactionReview` completent maintenant
la repetition native. Ils ne changent aucune donnee ni autorisation : leurs
resultats sont des propositions et des listes de revue dans le rapport prive,
pas un script d'ouverture des portails. Le garde-fou de repetition est verifie
avant toute connexion SQL. Aucun nouveau controle d'acces parallele n'est cree :
les categories utilisees sont celles de `PortalCategoryAccess` et les comptes
restent soumis a `ClientAccess` et aux policies natives.

Le controle des 187 comptes externes verifie l'identifiant source, le type
client/delegataire, l'organisation native, le role sans administration, le
portail desactive, toutes les permissions explicitement fausses et les categories
vides. L'ordre des cles JSON ne change pas cette verification (MySQL peut le
normaliser). Les autorisations source mal formees, absentes ou faisant reference
a un statut inconnu ne produisent aucune categorie candidate. Les identifiants
de modules et les champs de portee par compte sont conserves comme preuves,
sans leur attribuer une signification ou des droits non verifies.

Ecart important : la source autorise le statut 4 `RAPPORT GENIUS` aux profils
client (5) et delegataire (7), mais pas le statut 11 `DOSSIERS FACTURER`. La V2
reunit ces deux valeurs historiques dans la categorie `report`. Copier seulement
l'autorisation du statut 4 elargirait donc les dossiers visibles. Initialement,
le rapport ne proposait une categorie que si tous ses statuts source etaient
autorises. L'exception explicite pour `report` a depuis ete validee par
l'operateur (voir section 10) ; la categorie non classee reste en revue. Ce controle ne masque
pas automatiquement tous les dossiers portant un ancien marquage de facturation :
le probleme concerne les valeurs d'etat fusionnees, pas la preuve comptable
independante qui doit rester permanente. Les nouvelles possibilites de correction
et de demande de recontrole restent regies par le workflow V2 ; leur ouverture
effective doit etre validee dans le plan d'acces, pas deduite des anciens modules.

Sur le dump du 23 octobre : huit categories clients sont candidates sans
elargissement (prise RDV, probleme, attente visite, visite, RT, non-conformite,
recontrole, annule). Le delegataire n'avait pas d'equivalence exacte applicable
avant la decision sur `report`, maintenant documentee ci-dessous. Aucun de ces
resultats n'est applique aux droits effectifs pendant la repetition.
Seize affectations de dossier dans le champ client pointent vers un profil source
non client et sont identifiees pour revue ; elles ne sont ni converties en comptes
externes ni rattachees arbitrairement. Les affectations delegataires ont toutes
un type compatible.

Pour les lots, un libelle `numero_lot` n'est pas une cle relationnelle. Le controle
recense les liens explicites, les references orphelines, les lots sans dossiers,
les avis au niveau dossier et ceux au niveau prestation, sans calculer de taux
ou inventer une validation de controle. Une correspondance de libelle ne suffit
jamais a creer une ligne de lot. Les avis dossier ne sont pas recopies vers toutes
les prestations d'un dossier, meme lorsqu'ils sont reconnus comme satisfaisant ou
non satisfaisant. Les contenus de questionnaire ne sont pas interpretes comme un
avis global, un fichier ou un chemin executable.

La collecte reelle contient un enregistrement `lot_dossier`, zero `dossiers.lot_id`
renseigne, 5545 libelles libres et huit avis dossier (sept satisfaisants, un non
satisfaisant). Aucune fiche n'a de `is_favorable` renseigne ; les 4365 contenus de
`dossier_fiches.rapport` restent dans l'archive. Le rapport identifie les dossiers,
leurs prestations et les empreintes des valeurs d'avis, sans publier les libelles
de lots ni les contenus de questionnaire. Aucun lot, rattachement, resultat de
controle ou taux natif n'est cree a partir de ces indications insuffisantes.

Repetition complete validee, avec les memes sources Coffrac, TechCalendar et
documentaires que precedemment :
`~/Downloads/coffrac-production-20261023/native-access-lots-rehearsal-20261024-2v7Lt2T0/report.json`.
Elle conserve les 289 comptes, 288 hashes identiques, 17624 dossiers, 16986 fiches,
19356 RDV, 14177 dossiers historiquement factures et 104932 documents rapproches.
Le second passage est identique et sans doublon ; aucun portail, token, job,
message ou acces fichier n'est active. Les conteneurs et le volume SQL jetables
sont retires en sortie. Suite complete : 1284 tests / 9655 assertions passes.
La validation des droits, les preuves de rattachement et la collecte finale
restent des verrous explicites ; aucun import de production n'est encore expose.

### 10. Acces aux rapports conserve apres facturation

Decision operateur du 24 octobre 2026 : un client ou delegataire affecte conserve
l'acces a `Rapport Genius` apres le marquage de facturation. Le statut metier et
la preuve comptable sont independants ; aucun filtre general sur les facturations
ne doit retirer cet acces. Cette decision ne donne pas acces aux dossiers non
affectes, aux categories desactivees pour le compte, ni aux documents/commentaires
internes ou non partages avec le type de destinataire concerne.

`NativeExternalAccessReview::POLICY_VERSION` identifie la regle
`2026-10-24-report-access-after-billing`. Le rapport conserve les autorisations
Coffrac originales et trace separement l'extension approuvee de la categorie
`report`. Elle n'est candidate que si la source autorise deja `RAPPORT GENIUS`,
et si les seuls statuts supplementaires sont `DOSSIERS FACTURER`. Un profil
autorise seulement aux factures, des droits absents/mal formes ou des references
de statut inconnues ne gagnent pas l'acces au rapport par cette exception.

Les clients ont donc neuf categories candidates (les huit precedentes plus
`report`), les delegataires une (`report`). Les modules, droits d'action et autres
restrictions par compte restent a rapprocher ; `rights_approval_required` reste
vrai pour le plan d'acces global. Les comptes importes restent desactives au
portail, avec permissions fausses et categories vides, tant que l'ouverture n'est
pas explicitement preparee. Un rapport d'import avec une ancienne version de la
politique ne peut pas etre reutilise silencieusement : une nouvelle base de
repetition jetable est requise, sans modifier l'ancienne.

Les tests HTTP couvrent clients et delegataires, avec `RAPPORT GENIUS` et l'ancien
`DOSSIERS FACTURER`, avant/apres insertion d'une preuve comptable. Listes filtrees,
fiches et telechargements restent accessibles selon leurs droits ; contenus
internes, documents client seuls pour un delegataire, dossiers etrangers et
categories revoquees restent bloques. La suite complete passe : 1294 tests /
9745 assertions. Les preuves anti-double-facturation ne sont ni retirees ni
assouplies par cette regle d'acces.

Repetition complete avec la regle confirmee validee dans MySQL 8.0.46 jetable :
`~/Downloads/coffrac-production-20261023/native-approved-report-rehearsal-20261024-fdojmnyp/report.json`.
Les neuf categories candidates clients et la categorie rapport delegataire sont
confirmees, avec les deux extensions approuvees tracees. Les 187 comptes externes
restent verrouilles, les 288 hashes et tous les effectifs metier sont preserves,
ainsi que le rapprochement des 104932 documents et leurs audiences. La reprise
identique est sans doublon, aucun envoi n'est produit et les conteneurs/volume
SQL sont retires. Coffrac original et la base cible de production restent intacts.

### 11. Import serveur de preparation, distinct de la bascule finale

`stage-production-import.sh` et son point d'entree PHP ajoutent uniquement une
preparation dans `cofrac2production`. Le mode repetition reste interdit sur le
serveur : ne pas mettre `APP_ENV=testing` ni activer `COFFRAC_OFFLINE_REHEARSAL`.
Le coeur de projection est reutilise, mais le point d'entree production exige
un contexte CLI strict, le runtime `cofrac2-app`, le projet `/var/www/cofrac2`,
l'URL temporaire, le fuseau Europe/Paris et le compte SQL local limite a la V2.
Les gardes des sous-services n'acceptent cette cible que pendant ce contexte,
referme dans un `finally`. Le mode HTTP et un appel direct au moteur d'import
ne peuvent pas l'activer.

Le paquet contient le dataset metier et ses hashes de comptes, les reglages
TechCalendar, le plan documentaire et la preuve de repetition complete. Il ne
contient ni SQL executable, ni `.env`, ni secret API/SMTP/SMS, ni copie des 232 Go
de documents. Son manifeste a une empreinte attendue transmise separement et
lie les trois sources, leur preuve de repetition, la politique de droits validee
et la revision Git du code. Une entree supplementaire, symbolique, publique ou
modifiee est refusee. Ce paquet contient des donnees personnelles sensibles :
repertoires 0700 et fichiers 0600 pour le transfert, jamais de lien public.

Generation locale, apres commit du code et verification des sources :

```bash
php tools/fixtures/production/prepare-stage-package.php \
    /CHEMIN/DATASET /CHEMIN/PLANNING /CHEMIN/PLAN-DOCUMENTAIRE \
    /CHEMIN/RAPPORT-REPETITION.json /NOUVEAU/PAQUET \
    EMPREINTE_GZIP_PLANNING \
    /var/lib/cofrac2-production/documents-inventory-INVENTAIRE \
    /var/lib/cofrac2-production/documents-run-VERIFICATION
```

Le controle serveur n'initialise pas une nouvelle base et n'en reinitialise
aucune. Il exige les marqueurs de preparation, le checkout propre a la revision
du paquet, le vhost `cofrac2` encore en maintenance HTTPS statique, une
configuration privee non cachee, des moteurs InnoDB et l'absence de triggers ou
events dans la cible. Toutes les tables doivent etre vides, sauf les migrations,
les referentiels techniques de depart et les caches. Il refuse donc aussi un
second import, sans `DELETE`, `TRUNCATE`, `migrate:fresh` ou suppression de base.
La seule migration supplementaire acceptee est celle du suivi d'import Coffrac.

Les empreintes des preuves physiques serveur sont recoupees avec le plan
documentaire et les tailles des copies sont controlees. Ce controle relit les
manifestes mais pas le contenu des 232 Go ; il ne remplace pas le differentiel
et les controles finaux avant bascule. Les fichiers vides, absents ou en
quarantaine restent dans les listes de revue, sans etre exposes ou executes.

Sur le serveur, apres transfert prive du paquet et mise a jour du seul checkout V2 :

```bash
sudo -H -u cofrac2-deploy git -C /var/www/cofrac2 pull --ff-only origin v2

# Controle seul : pas d'import, de migration ni de service.
sudo bash /var/www/cofrac2/tools/deploy/stage-production-import.sh \
    --check /CHEMIN/PAQUET EMPREINTE_MANIFESTE

# Import explicite de preparation, toujours site ferme.
sudo bash /var/www/cofrac2/tools/deploy/stage-production-import.sh \
    --import-staging /CHEMIN/PAQUET EMPREINTE_MANIFESTE
```

L'import prend un verrou propre a la preparation V2 et recopie les entrees dans
un dossier root non modifiable par le runtime. Il demande dans le terminal
l'adresse et le mot de passe du seul super-admin (minimum huit caracteres,
confirmation masquee). Une collision avec un compte Coffrac annule l'import,
sauf si le manifeste scelle approuve explicitement la reprise de ce compte
interne actif via `seeded_admin.source_user_id` et la politique
`reuse_active_internal_account_reset_password`. Le generateur accepte cet ID
comme dernier argument optionnel. Le compte selectionne est alors reutilise
sans doublon : identite, correspondance Coffrac et liens metier conserves ;
seuls ses privileges globaux et son mot de passe sont definis par le seeder.
Un client, delegataire, technicien, profil en revue ou compte inactif est refuse.
Les autres comptes gardent leur hash exact et aucun autre super-admin n'est cree.
Le comptage de reference est diminue d'une seule ligne utilisateur puisque le
super-admin ne constitue plus un compte supplementaire. Le rapport distingue
les hashes sources conserves et l'unique mot de passe reinitialise par le seeder.
Le mot de passe ne figure ni dans les arguments, ni dans
le `.env`, ni dans les logs ; son fichier temporaire root est accessible au seul
runtime le temps necessaire puis supprime en sortie, y compris en cas d'erreur.
Les entrees protegees cessent aussi d'etre accessibles au runtime en sortie.

Une sauvegarde de la cible V2 encore vide est faite avec le seul compte SQL
applicatif (pas avec `debian.cnf`). Les fichiers de code V2 nouvellement recus
obtiennent les memes droits de lecture du runtime que lors de l'initialisation.
Le code reste au deployeur, le runtime ne peut modifier ni le code ni le `.env`.
La migration de suivi est appliquee si elle manque. Cette operation DDL n'est pas
transactionnelle : en cas d'echec ulterieur, ses tables peuvent rester presentes,
mais aucune donnee metier n'est effacee automatiquement.

La transaction d'import englobe la projection, le rapprochement documentaire
natif, les effectifs compares a la repetition, les hashes des mots de passe,
l'absence d'envois/sessions/jetons et une nouvelle verification du paquet. Toute
erreur de ces controles annule les donnees importees. Les audits, modeles mail
inactifs et listes de revue sont conserves ; aucun droit externe n'est ouvert
par le fait d'importer les comptes. Le `.env` est controle inchange en sortie.

Les rapports et la sauvegarde restent sous
`/var/lib/cofrac2-production/native-import-...` en prive. Le marqueur
`STAGING-IMPORTED.json` est ecrit uniquement apres succes ; un incident survenant
apres la validation SQL ne provoque jamais de restauration/destruction aveugle.
Examiner le rapport et la base avant toute reprise manuelle. L'import ne demarre
ni PHP-FPM, ni worker, ni Reverb, et ne recharge pas Apache. Le site reste 503,
les SMS restent desactives/en simulation et les mails non livres (`log`).

Verification automatisee : 1313 tests / 9774 assertions, plus une fixture Docker
MySQL 8.0.46 avec les gardes production actives, une base Coffrac temoin et un
utilisateur PHP sans shell. Elle couvre la cible incorrecte, le mauvais SHA,
une base non vide, l'annulation complete apres une erreur injectee en fin
d'import, le succes sous le compte SQL limite et le refus de doublon. Base,
documents, `.env` et vhost temoins restent inchanges ; aucun service n'est appele.
Le test local avec un paquet reel verifie en plus les volumes et cas de donnees
reels, sans pretendre reproduire la preuve physique distante des 232 Go.

Ces controles n'autorisent toujours pas une bascule finale : les droits externes
restants, parametrages manquants et donnees en revue doivent etre traites pendant
la recette. La photographie SQL coherente et le differentiel documentaire seront
refaits au moment convenu, sans autorisation implicite d'arreter Coffrac original.

### 12. Ouverture de recette interne sur cofrac2 seulement

L'import serveur termine ne demarre aucun service. Le runtime suivant est
prepare exclusivement dans `/var/www/cofrac2`, avec la base `cofrac2production`
et les copies documentaires sous `/srv/cofrac2-document-archive`.
Il ne remplace pas l'ancien Coffrac et ne constitue pas une bascule finale.

Le generateur local construit les assets depuis un `git archive` propre et un
`npm ci --ignore-scripts` neuf, sans `.env` dans le build ni variables VITE
locales. Le paquet prive comprend les assets scelles et seulement les reglages
Mapbox, OpenAI et Global+ TEST autorises. Aucun secret ne doit apparaitre dans
les assets, dans Git ou dans la sortie du terminal. Le manifeste rattache ces
assets a la revision du code, au paquet effectivement importe et aux preuves
documentaires. Transporter ce paquet en 0700/0600, jamais via un lien public.

```bash
bash tools/deploy/prepare-production-runtime-bundle.sh /CHEMIN/PAQUET-IMPORT-APPROUVE
```

Sur le serveur, apres mise a jour du checkout V2 et transfert prive :

```bash
sudo bash /var/www/cofrac2/tools/deploy/configure-production-runtime.sh \
    --prepare /CHEMIN/PAQUET-RUNTIME EMPREINTE_MANIFESTE
```

Cette preparation ne recharge pas Apache et ne demarre aucun service. Elle :

- recoupe le rapport d'import et les preuves de copie, sans rehasher 232 Go ;
- autorise uniquement le runtime a lire les fichiers servables de la copie ;
- conserve les fichiers absents, vides ou en quarantaine hors de la selection ;
- installe un manifeste documentaire non modifiable par PHP, hors du webroot ;
- installe les assets et met a jour uniquement le `.env` V2 ;
- configure les API approuvees sans effectuer de requete reseau externe ;
- conserve SMS desactives/simulation, CAPCEE desactive, mails en log ;
- met `MAIL_DELIVERY_ENABLED=false`, qui bloque aussi les expediteurs SMTP
  dynamiques via l'evenement Laravel `MessageSending` ;
- installe trois unites dediees et inactives : `cofrac2-php-fpm`, `cofrac2-queue`
  et `cofrac2-reverb`, puis verifie leurs configurations ;
- compile les caches Laravel et controle la lecture de chaque document de la
  selection avec l'utilisateur `cofrac2-app` ;
- verifie que les configurations et processus partages sont restes identiques.

PHP utilise son propre maitre FPM et `/etc/cofrac2/php-fpm.conf`, sans modifier
les pools existants ni demarrer/recharger `php8.4-fpm.service`. Le socket dedie
est accessible a Apache ; les workers PHP tournent sous `cofrac2-app`. Les
services ne peuvent ecrire que dans les repertoires runtime de la V2, pas dans
son code ou ses secrets. Le worker utilise un timeout inferieur au retry-after.
Les erreurs FPM et PHP utilisent syslog : `/proc/self/fd/2` ne peut pas etre
rouvert lorsque systemd fournit un socket de journalisation comme stderr.

Si la preparation initiale a reussi avec l'ancien modele de journalisation,
mais que l'ouverture a echoue sur `failed to open error_log (/proc/self/fd/2)`,
ne relancer ni l'import ni `--prepare`. Apres recuperation du correctif Git :

```bash
sudo bash /var/www/cofrac2/tools/deploy/configure-production-runtime.sh \
    --repair-fpm-logging
```

Cette reparation exige le vhost encore statique et les trois services V2
arretes. Elle verifie le `.env` inchange, l'ancien modele PHP exact et que
seuls la journalisation du deploiement, ses tests et sa documentation ont
change depuis la revision preparee. Toute autre modification applicative est
refusee. Le seul fichier `/etc/cofrac2/php-fpm.conf` est remplace par sa version
syslog testee ; les assets, l'import, les droits documentaires et les secrets
restent en place. La nouvelle revision compatible est tracee dans le marqueur
prive, avec sauvegarde de son etat precedent. Aucun service n'est demarre ni
recharge par la reparation. L'ouverture explicite ci-dessous reste obligatoire.
En cas d'echec du demarrage, les etats et journaux des seuls services V2 sont
conserves dans le diagnostic prive avant leur arret de securite.

L'ouverture est une action separee et exige le consentement explicite au
rechargement gracieux du service Apache partage :

```bash
sudo bash /var/www/cofrac2/tools/deploy/configure-production-runtime.sh \
    --open --reload-shared-apache
```

Si `headers` ou `proxy_http` ne sont pas deja charges, l'ouverture ajoute leurs
seules directives `LoadModule` dans le fichier dedie
`/etc/apache2/conf-enabled/cofrac2-runtime-modules.conf`. Elle ne lance pas
`a2enmod`, ne modifie aucun module/site existant et ne redemarre pas Apache.
Ce chargement est global a Apache ; examiner les eventuels blocs conditionnels
des autres vhosts si une politique locale particuliere l'exige.

Le seul vhost `cofrac2` est remplace par son modele applicatif. Seul
`/index.php` est execute par FPM ; PHP ailleurs, dotfiles et alias administratifs
herites sont refuses. Les documents historiques restent hors du webroot et
passent par les autorisations Laravel. Les controles verifient les pages login
et sante, un asset compile, l'authentification obligatoire, le refus des chemins
sensibles, le handshake WebSocket via HTTPS et un vrai aller-retour du worker
sans appel externe. Une erreur remet automatiquement le seul vhost V2 en
maintenance et arrete ses services ; aucune base n'est effacee.

Apres reussite, les trois unites V2 sont activees au demarrage. Aucun cron,
import periodique Coffrac ou envoi reel n'est active. Les comptes externes
restent bloques jusqu'a la validation et l'application de leurs droits.
Verifier aussi dans le navigateur une connexion super-admin et l'ouverture
d'un vrai document. Les appels Mapbox/OpenAI/Global+ sont configures, mais leur
validation reseau n'est pas remplacee par ce controle local.

Retour explicite a la maintenance V2, en conservant ses donnees :

```bash
sudo bash /var/www/cofrac2/tools/deploy/configure-production-runtime.sh \
    --maintenance --reload-shared-apache
```

La collecte SQL coherente actualisee, le differentiel documentaire final,
les droits externes et l'activation des envois restent des etapes distinctes
avant de remplacer le domaine de l'ancien Coffrac. Ne jamais relancer l'import
initial sur la base peuplee et ne pas utiliser les scripts demo du CT 124 ici.

### 13. Correctif de recherche des comptes et adresses deja utilisees

Le correctif conserve l'unicite globale des emails, y compris pour les comptes
internes desactives et les comptes externes importes. L'erreur precise le type
de compte et propose son ecran de gestion autorise. Une recherche sans resultat
peut aussi proposer ce lien ; le statut « Tous » inclut les internes desactives.
Aucune reactivation ni conversion de compte n'est automatique.

Pour cette mise a jour PHP/Blade uniquement, utiliser le script de maintenance
AVANT le pull : il faut arreter les seuls services V2 pendant le changement de
code et conserver la revision preparee pour les controles de compatibilite.

```bash
sudo bash /var/www/cofrac2/tools/deploy/configure-production-runtime.sh \
    --maintenance --reload-shared-apache &&
sudo -H -u cofrac2-deploy git -C /var/www/cofrac2 pull --ff-only origin v2 &&
sudo bash /var/www/cofrac2/tools/deploy/configure-production-runtime.sh \
    --update-user-management &&
sudo bash /var/www/cofrac2/tools/deploy/configure-production-runtime.sh \
    --open --reload-shared-apache
```

Le mode cible verifie le `.env` inchange, les services et pools dedies, et une
liste exacte des fichiers PHP/Blade/tests du correctif. Il refuse notamment
tout changement de dependance, schema, route, assets ou configuration API.
Les permissions de lecture du runtime et les vues compilees sont actualisees,
puis le controle applicatif et documentaire est rejoue. La nouvelle revision
est tracee dans le marqueur prive avec son etat precedent. Le site reste
statique jusqu'au succes de `--open`. Aucun seeder, migration, import SQL,
renvoi de mail/SMS, modification de compte ou nouvelle copie documentaire.
En cas d'echec, conserver le diagnostic et ne pas reinitialiser la base.

### 14. Maintenance de l'ancien domaine, autorisation du 25 octobre

L'utilisateur demande explicitement l'activation immediate, meme si les portails
externes et l'envoi reel des emails V2 sont encore fermes. La page indique les
identifiants conserves, propose « Mot de passe oublie » et precise qu'une
activation par l'equipe peut etre necessaire. Ne pas promettre qu'un email sera
livre tant que l'envoi reel n'a pas ete valide et active separement.

Le paquet autonome contient uniquement :

- `configure-legacy-domain-maintenance.sh` ;
- `legacy-domain-maintenance.php` ;
- `legacy-domain-maintenance.html` ;
- leur manifeste `SHA256SUMS`.

Il peut etre envoye et execute depuis `/home/ubuntu` sans faire de pull dans la
V2 active. Aucun changement de son marqueur de revision n'est necessaire et le
mode `--update-user-management` n'est pas a rejouer pour cette operation.

```bash
cd /home/ubuntu/PAQUET_MAINTENANCE
sha256sum -c SHA256SUMS &&
sudo bash ./configure-legacy-domain-maintenance.sh \
    --activate --reload-shared-apache
```

Le script exige le serveur `ns365601`, les deux vhosts connus actives par leurs
liens habituels, les modules deja charges et une page de connexion HTTPS V2 en
HTTP 200. Il refuse les alias ou includes TLS non reconnus. Il conserve les
directives du certificat/TLS existant sans lancer Certbot, puis remplace seulement :

- `/etc/apache2/sites-available/cofrac.geniuscontrole.com.conf` ;
- `/etc/apache2/sites-available/cofrac.geniuscontrole.com-le-ssl.conf`.

Les originaux sont sauvegardes sous le repertoire root prive
`/var/lib/cofrac2-production/legacy-domain-maintenance/run-*`. La page statique
est installee dans `/var/www/cofrac-legacy-maintenance`, en lecture seule. Les
URL ordinaires de l'ancien domaine et de son alias `www`, y compris les liens
profonds, PHP, documents et alias administratifs, retournent HTTP 503 avec cette
page. Aucun ancien `.htaccess` n'est execute. Seuls les jetons ACME au nom strict
sont encore servis en texte depuis le webroot de renouvellement original ; le
script ne cree ni ne modifie aucun fichier dans ce webroot.

Apres configtest, seul un rechargement **gracieux** d'Apache partage est effectue.
Les controles comparent les autres configurations actives et les etats/PID des
services, verifient le meme PID Apache, les reponses 503 sans cache et le login
HTTPS V2 toujours en 200. Une erreur restaure les deux configurations precedentes
si elles n'ont pas subi de modification concurrente. Le script de retour arriere
refuse egalement d'ecraser des vhosts modifies apres l'activation.

```bash
sudo bash /var/lib/cofrac2-production/legacy-domain-maintenance/rollback.sh
```

Cette maintenance HTTP **n'est pas un gel transactionnel de la source** : les
workers, cron et autres domaines historiques, notamment `preprod`, ne sont pas
modifies. La collecte finale coherente et le differentiel documentaire restent
necessaires ; aucun import existant ne doit etre relance sur la V2 peuplee.

Validation : tests unitaires du rendu et fixture jetable avec vrais Apache/TLS,
refus sans consentement, echec de config/rechargement, chemins profonds, ACME,
certificats conserves, V2 intacte, retour arriere et modification concurrente.
