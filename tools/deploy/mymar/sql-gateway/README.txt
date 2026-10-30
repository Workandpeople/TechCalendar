Preparation SQL MYMAR dediee et test ponctuel authentifie
======================================================

Ce paquet CREE un nouveau compte SQL local au nom aleatoire reserve
cofrac_mymar_ro_XXXXXXXX. Il ne modifie aucun compte applicatif existant.
Il accorde uniquement SELECT sur trois colonnes de MYMAR.dossiers et cinq
colonnes de MYMAR.dossier_rdv. Aucune lecture des mots de passe, documents,
emails, beneficiaires ou adresses n'est autorisee par ce nouveau compte.

Le compte est cree ACCOUNT LOCK. Le script le deverrouille uniquement pour
ouvrir UNE connexion PDO, puis le reverrouille avant la lecture des RDV.
MySQL conserve cette session deja ouverte ; les nouvelles connexions sont
refusees. La session est fermee apres le test. Un controle independant du
verrouillage s'execute aussi a la sortie du script, en cas d'erreur ou delai
depasse. Aucun DROP USER automatique n'est utilise.

Prerequis : serveur ns365601, PHP >=8.4.1 avec PDO MySQL, MySQL 8.0 local,
compte de maintenance existant dans /etc/mysql/debian.cnf, release Cofrac
deja ouverte, huit fichiers MYMAR prepares/scelles, correspondances humaines
approuvees root-privees et empreintes exactes dans REQUEST.json.

Lancer depuis le Mac : bash ./fetch-sql-gateway.sh
Execution serveur explicite :
sudo bash ./collect.sh --prepare-restricted-sql-and-probe

Avant CREATE, les profils source Terrain actifs et les cibles Cofrac internes
techniciens actives sont relus dans une transaction READ ONLY. Les identites
et les liens doivent toujours correspondre au fichier approuve. Les lectures
metier sont bornees, transactionnelles et limitees a 1500 ms par requete.
Le compte de maintenance ne lit pas les occupations pendant le test : elles
sont lues par le nouveau compte SELECT seul, apres controle de ses grants.

Le test exerce EN PROCESSUS le gestionnaire de passerelle : mauvais jeton
refuse sans lecture, bon jeton lu une fois, occupations normalisees pour les
seuls techniciens explicitement approuves sur une fenetre de sept jours.
Ce n'est PAS un test HTTP en production. Aucun port, serveur HTTP, service,
pool PHP, tache recurrente ou worker n'est lance/configure/recharge.
Les applications Laravel ne sont jamais executees. Aucun git pull, migration,
import, seeder, envoi de message, changement de droit metier ou suggestion.

Les seuls changements SQL sont CREATE USER, GRANT SELECT par colonnes et
ACCOUNT LOCK/UNLOCK pour CE nouveau compte. Les tables metier ne sont pas
modifiees. La configuration generee conserve enabled=false, reste root-privee
en 0600 sous /var/lib/cofrac2-production (dossiers 0700), et n'est chargee
par aucun service. Mot de passe et jeton ne quittent pas le serveur.

Les rapports summary.json / observation.json et leurs SHA256 sont les seuls
fichiers recuperes par l'operateur. Ils indiquent notamment :
sql_account_locked=true, authenticated_handler_tested=true,
production_http_tested=false, connector_enabled=false,
can_affect_planning=false. Les hypotheses de fuseau/durees/statuts restent
signalees et la couverture de tous les techniciens n'est pas revendiquee.

Une seconde execution pour la meme empreinte de correspondances est refusee,
sans nouveau compte. Une tentative echouee est conservee pour revue privee,
avec le nouveau compte verrouille ; ne pas effacer son marqueur pour relancer
aveuglement. Les erreurs SQL brutes restent root-privees dans le diagnostic.

Suite encore necessaire : hebergement HTTP interne isole et authentifie,
validation du transport reel, couverture des autres techniciens, decisions
sur les durees/fuseau/statuts, puis projection CRM et activation progressive.
Cette preparation n'a aucun effet sur les disponibilites en production.

Tests locaux uniquement (images Docker deja disponibles) :
vendor/bin/pest tests/Unit/MymarSqlGatewayPreparationTest.php
MYMAR_SQL_GATEWAY_TEST=1 bash tools/deploy/tests/check-mymar-sql-gateway.sh
Le test integral utilise mysql:8.0.46 et cofrac2-runtime-check:local
(PHP >=8.4.1, PDO MySQL et client mysql), sans reseau ni port expose.
