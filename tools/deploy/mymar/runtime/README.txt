Passerelle MYMAR HTTP interne isolee - pilote sans branchement CRM
================================================================

Cette etape INSTALLE et DEMARRE deux nouveaux services systemd :
cofrac-mymar-fpm.service et cofrac-mymar-http.service. Elle cree un utilisateur
Linux sans shell cofrac-mymar et des repertoires exclusivement dedies.
L'Apache public, ses vhosts et tous les services applicatifs existants ne sont
ni modifies ni recharges/redemarres. Le seul daemon-reload systemd sert a
faire connaitre les deux nouvelles unites, sans relancer les autres services.

L'ecoute HTTP est exclusivement 127.0.0.1:19846. Ni DNS ni certificat ni port
public ni pare-feu a ouvrir. Deux workers PHP maximum, quatre threads HTTP,
limites de memoire/temps et compte SQL SELECT par colonnes deja prepare.
Les applications Laravel ne sont jamais executees par cette passerelle.
Il n'y a pas de serveur de developpement PHP en production.

IMPORTANT : contrairement a la preparation precedente, le compte SQL dedie
est DEVERROUILLE pour que la passerelle puisse lire MYMAR. Ses privileges ne
changent pas. Aucun nouveau compte SQL ni droit d'ecriture n'est ajoute.
Les deux services sont actives au demarrage seulement apres les controles.
Cela n'active PAS le connecteur dans Cofrac : aucun appel par les pages,
worker/scheduler, changement de disponibilites, suggestion, import ou envoi.

Prerequis verifies avant installation : ns365601, PHP>=8.4.1 avec PDO MySQL,
cURL et POSIX, MySQL 8.0 local, Apache/FPM deja installes, port libre,
absence des nouveaux services/comptes/repertoires et release Cofrac ouverte
attendue. Le script n'installe aucun paquet et ne touche pas aux .env.
Il refuse une installation preexistante au lieu de la remplacer.

Les quatre profils sources Terrain et les quatre cibles Cofrac approuves
sont reverifies dans une transaction READ ONLY. Le code scelle, la configuration
SQL et le fichier de correspondances doivent conserver leurs empreintes.
Un changement de compte, role, suppression ou identite bloque l'installation.
Les requetes HTTP sont elles aussi limitees aux IDs explicitement approuves.

Commande sur le Mac : bash ./fetch-private-http.sh
Commande serveur : sudo bash ./install.sh --install-private-http-and-observe
Les credentials ne sont ni fournis dans la ligne de commande ni exportes.
Ils sont copies uniquement cote serveur dans un dossier 0700 et fichier 0600
lisibles par le seul utilisateur dedie. Le code est root en lecture seule ;
les unites systemd rendent la configuration en lecture seule pour le runtime.
Le compte Cofrac ne recoit pas le mot de passe SQL MYMAR.

Verification avant publication : vrai GET HTTP authentifie, mauvais jeton,
POST, chemin inconnu, mauvais Host, en-tete de proxy et technicien non approuve.
Le resultat doit etre complet, frais et correspondre a la fenetre demandee.
Les empreintes des fichiers et l'etat des services existants sont compares.
Seuls summary.json / observation.json et leurs empreintes sont recuperes,
sans token, mot de passe, adresse ou nom de beneficiaire. Les diagnostics
plus detailles restent root-prives sous /var/lib/cofrac2-production.

En cas d'echec apres validation, le script arrete les seuls nouveaux services
et reverrouille le seul compte SQL dedie. Aucun DROP ni nettoyage automatique
des fichiers ou comptes n'est fait ; une installation partielle reste a revoir.
L'arret manuel apres succes figure dans summary.json / a la fin de la commande :
sudo bash /var/lib/cofrac2-production/mymar-http-runtime-XXXXXXXX/bundle/stop.sh
Il desactive les deux services au demarrage, verrouille SQL et desactive la
configuration privee. Il fonctionne meme si l'ancien fichier d'approbation a
change. Ne pas relancer l'installateur pour reouvrir apres un arret volontaire.

Les hypotheses de duree, fuseau et statuts restent signalees. La couverture
globale des techniciens n'est pas revendiquee. Prochain lot : projection/cache
Cofrac et visualisation pilote, puis activation explicite des disponibilites.
Pas de requete MYMAR directement dans le chargement des pages du planning.

Tests Docker locaux seulement, sans acces au serveur ni reseau externe :
MYMAR_PRIVATE_HTTP_TEST=1 bash tools/deploy/tests/check-mymar-private-http.sh
MYMAR_PRIVATE_HTTP_TEST=1 MYMAR_PRIVATE_HTTP_FAILSTART=1 bash tools/deploy/tests/check-mymar-private-http.sh
Images prealables : mysql:8.0.46, cofrac2-runtime-check:local.
Apache, FPM, SQL et HTTP sont reels. Le gestionnaire des PIDs est une fixture
du cycle systemd ; les unites sont validees par systemd-analyze verify, leurs
protections seront aussi controlees lors du demarrage systemd reel sur l'hote.
