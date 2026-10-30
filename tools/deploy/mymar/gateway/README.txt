PASSERELLE MYMAR PRIVEE - PREPARATION LOCALE, NON DEPLOYEE

Ce repertoire ne contient aucun installateur, compte, cle, mot de passe,
vhost, service, migration, ordonnanceur ou activation des disponibilites.
Ne pas lancer le serveur de developpement PHP en production.

Entree autonome : serve.php. Aucun autoload/bootstrap de MYMAR ou de Cofrac.
Prerequis runtime : PHP >=8.4.1, extensions pdo_mysql, json et posix.
GET /v1/occupations seulement, depuis loopback, avec Authorization: Bearer.
Les en-tetes de proxy sont refuses. La future ecoute HTTP devra etre liee
exclusivement a 127.0.0.1, sur un port dedie, jamais a 0.0.0.0.

Parametres obligatoires, sans doublons : window_start, window_end (RFC3339
avec fuseau), technician_ids (IDs numeriques uniques separes par des virgules).
Pas de cle en URL, filtre libre, donnees de beneficiaire ou documents.
31 jours et 200 techniciens maximum, statuts prepares 3 et 16 seulement.
Reponse 503 complete=false si lecture impossible ou plus de 2000 lignes.
Les valeurs invalides de la source restent des anomalies dans l'observation.
complete=true atteste la couverture SQL, pas la qualite ni l'activation.

Configuration JSON LITTERALE privee hors des racines applicatives : fichier
0600, dossier parent 0700, chemin reel sans symlink. Emplacement par defaut
/etc/cofrac-mymar-gateway/config.json, surcharge processus MYMAR_GATEWAY_CONFIG.
Champs stricts : enabled (false initialement), token (32 octets aleatoires
encodes en 64 caracteres hexadecimaux), source_timezone (Europe/Paris),
duration_unit (hours), database_socket (/var/run/mysqld/mysqld.sock),
database_user (cofrac_mymar_ro), database_password (secret dedie >=24 caracteres).
Les secrets devront etre generes sur le serveur et ne jamais passer dans Git,
les commandes, les rapports, les URL ou les conversations.

Le runtime refuse les privileges globaux, les roles SQL, les droits d'ecriture,
GRANT OPTION et meme SELECT sur des tables entieres. Deux privileges autorises :
- SELECT (id,status_id,technicien_id) sur MYMAR.dossiers
- SELECT (id,dossier_id,date_rdv,duree,is_old) sur MYMAR.dossier_rdv
Pas de compte de maintenance, de lecture de users, de la base Cofrac ou de .env.
Tables InnoDB exigees ; transaction READ ONLY, requete parametree, budget
MySQL 1500ms, attente de verrou 2s. Aucun ajout d'index automatique.

Client Cofrac : App\Services\Mymar\MymarGatewayClient, desactive par defaut.
Destination imposee 127.0.0.1, pas de proxy, redirect, retry ou cookie,
reponse bornee a 2 Mio et lectures bornees dans le temps. Il refuse les
reponses perimees, partielles ou dont fenetre, techniciens, fuseau ou unite
different de la demande. Aucun echec ne devient un planning vide reussi.
Il ne sauvegarde rien et ne participe a aucune decision de placement.

VALIDATION LOCALE EXPLICITE (Docker requis) :
MYMAR_GATEWAY_TEST=1 bash tools/deploy/tests/check-mymar-gateway.sh

Ce test cree puis detruit deux conteneurs et un volume de socket dedies,
sans reseau externe ni port publie. Le depot est monte en lecture seule.
Les droits SQL et les donnees crees sont exclusivement des fixtures jetables.
L'API autonome et le vrai client Laravel communiquent sur leur loopback isole.
Ce script ne doit jamais etre utilise pour creer un compte en production.
Validation locale : 134 tests unitaires MYMAR (451 assertions), chaine HTTP
et MySQL jetable validee, suite globale 2656 tests reussis (20279 assertions).

Avant toute installation : validation du perimetre de statuts, rapprochement
explicite des techniciens, validation de la requete sur le serveur reel et
preparation d'un service isole. Les hypotheses heures/Europe-Paris restent
provisoires. Projection, synchro et controle des disponibilites non branches.
