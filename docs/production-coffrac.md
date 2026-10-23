# Preparation de la production V2 sur le serveur Coffrac

## Cible

- Serveur : `ubuntu@46.105.110.76`, hostname `ns365601`.
- URL temporaire confirmee : `https://cofrac2.geniuscontrole.com`.
- URL finale : `https://cofrac.geniuscontrole.com`.
- Coffrac actuel : `/home/ubuntu/www/coffrac_preprod`, toujours en service.
- Consigne confirmee : ne modifier ni les fichiers, ni la base, ni les vhosts,
  ni les regles d'acces de Coffrac original. Aucun confinement de l'ancien site.
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
