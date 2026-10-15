TECHCALENDAR V2 - PREPARATION DE LA DEMO SUR LE CT 124

URL conservee : https://techcalendar.lucas-dinnichert.fr
Cible unique : root@10.100.0.124
Aucune action sur Coffrac. Aucun fichier de ce dossier ne bascule le serveur automatiquement.

ARCHITECTURE DE RETOUR ARRIERE
- V1 conservee : /var/www/TechCalendar, sa base et son PHP 8.3 restent intacts.
- V2 : /var/www/TechCalendar-v2, nouvelle base techcalendar_v2_demo_simulation.
- Documents prives deja en transfert : /srv/techcalendar-v2-demo/documents.
- Reverb V2 : 127.0.0.1:8081, derriere le meme domaine HTTPS.
- PHP >= 8.4.1 obligatoire d'apres composer.lock, en parallele de PHP 8.3.
- APP_ENV=demo, SIMULATION_MODE=true : emails, push et CAPCEE desactives.
  Mapbox/OpenAI autorises ; Global+ uniquement sur cee-api.test.globalplus.fr.
- Donnees reelles : aucun partage public des archives, du .env ou des exports SQL.

1. PREPARATION SUR LE MAC (ne touche pas au CT)

bash tools/deploy/prepare-demo.sh

Le script exige la branche v2 et une simulation Docker sans job en cours.
Il exporte la base en transaction, la cle de chiffrement actuelle, les secrets
necessaires et les fichiers applicatifs utiles. Les modifications locales V2
non commitees sont incluses, ainsi que les assets compiles.
Il n'inclut pas vendor, node_modules, .env, caches, logs, snapshots historiques,
ni les 26 Go deja en transfert. Ne pas utiliser un paquet interrompu ou .part.

Validation locale reproductible dans un MySQL 8.0.46 jetable sans acces reseau :
  docker pull mysql:8.0.46
  bash tools/deploy/verify-demo-locally.sh /chemin/du/paquet
Ce controle utilise le runtime PHP 8.5 local ; PHP 8.4 doit encore etre verifie
sur le CT. Il ne touche pas aux bases locales existantes ni aux serveurs.

Le paquet genere n'est pas un clone Git. Ne pas effectuer de git checkout main
dans la V1 pour installer la V2. La revision et l'etat du worktree sont fournis
pour la tracabilite ; aucun commit ni push n'est realise automatiquement.

2. CONTROLES CT EN LECTURE SEULE, MEME PENDANT LE TRANSFERT

hostname
df -h / /srv
apt-cache policy php8.4-cli php8.4-fpm php8.4-mysql
command -v composer
ls -l /etc/nginx/sites-enabled
systemctl list-units --all --type=service 'laravel*' '*reverb*'
crontab -l
crontab -u www-data -l

Ne pas afficher le .env, nginx -T ou les secrets dans un chat public.
Si PHP 8.4 n'est pas disponible dans les depots actifs, valider sa source avant
installation. Ne pas changer la version PHP par defaut ni retirer PHP 8.3.
Extensions requises : mysql, mbstring, xml, curl, zip, intl, bcmath, gd, opcache.
Les workers necessitent egalement pcntl. Composer check-platform-reqs est obligatoire.

3. RECEPTION ET CONTROLE (APRES LA FIN DU TRANSFERT DES DOCUMENTS)

Transferer le paquet prive dans /srv/techcalendar-v2-demo/exports/, pas dans public.
Verifier SHA256SUMS sur le CT puis gzip -t database-v2.sql.gz.
Verifier les checksums du manifeste dans /srv/techcalendar-v2-demo/selection.
Verifier l'inventaire des documents avec verify-documents.php :

php8.4 tools/deploy/verify-documents.php \
  /srv/techcalendar-v2-demo/documents \
  /srv/techcalendar-v2-demo/selection/documents.list0 \
  /srv/techcalendar-v2-demo/selection/summary.json

Ce controle lit seulement les metadonnees ; il ne garantit pas l'identite des
octets de chaque fichier. Un rsync a blanc avec --checksum --from0 --files-from
depuis le Mac permet un controle de contenu, mais relit 26 Go des deux cotes.
Trois fichiers source vides sont connus : ils ne sont pas affichables dans l'app.
Ne pas fabriquer d'archive supplementaire des 26 Go sur le CT de 50 Go.

4. INSTALLATION ISOLEE, PAS ENCORE DE BASCULE

Creer le repertoire V2 uniquement s'il n'existe pas. Extraire code-v2.tar.gz
dans /var/www/TechCalendar-v2 et runtime-files.tar.gz dans son storage/app.
Creer storage/logs, storage/framework/{cache,views,sessions}, bootstrap/cache.
Combiner demo.env.example puis demo-secrets.env dans .env prive (0640 root:www-data).
NE PAS lancer key:generate : la cle existante dechiffre les reglages en base.
NE PAS reutiliser le .env V1 ni les fichiers de cache de configuration locaux.

Creer une base VIDE nommee techcalendar_v2_demo_simulation et un utilisateur
techcalendar_v2_demo limite a CETTE base, avec le DB_PASSWORD du fichier prive.
Refuser l'import si la base existe deja ou n'est pas vide. Ne pas donner au
compte V2 de droits sur la base V1. Importer le dump dans cette nouvelle base.
La compatibilite du dump MySQL 8.4 vers le serveur MySQL 8.0 doit etre verifiee.

Pour le paquet release-v2-20261015 et les chemins ci-dessus, le script dedie
restore-demo-database.php automatise cette etape. Le transferer separement
si le paquet a ete genere avant son ajout, puis executer en root sur le CT :
  php8.4 /chemin/prive/restore-demo-database.php --create-new-demo-database /chemin/socket/mysql
Il verifie les empreintes, la configuration, l'absence de la base ET du compte,
importe avec un compte limite a la V2, compare les comptages puis purge les
etats temporaires ci-dessous. Il ne lance pas Laravel. En cas d'echec partiel,
il conserve la copie pour diagnostic et refuse une nouvelle execution dessus.
Ne pas supprimer une base ni changer les chemins pour contourner ce refus.

Uniquement sur cette copie restauree, avant tout worker, purger les etats
temporaires : jobs, failed_jobs, job_batches, sessions, cache, cache_locks,
password_reset_tokens, mobile_access_tokens, mobile_push_tokens, remember_token
des utilisateurs. Conserver les tables metier. Aucun nettoyage de la source.

Installer les dependances verrouillees sous PHP 8.4 : composer install --no-dev
--prefer-dist --optimize-autoloader, puis composer check-platform-reqs --no-dev.
Executer Composer explicitement avec php8.4, pas via le PHP 8.3 par defaut.
Ne pas lancer composer update, migrate:fresh, db:seed, simulation:reset ou les
tests sur la base de demo. Le dump contient deja le schema V2.

Donner www-data les droits d'ecriture sur storage et bootstrap/cache seulement.
Pour les documents collectes : droits de traversee sur /srv/techcalendar-v2-demo,
lecture/traversee sur documents et selection, pas d'acces public ni symlink vers
public/storage. Conserver exports en 0700 root ; ne pas changer ses permissions.
Creer public/storage uniquement vers storage/app/public pour les logos.

Installer le pool PHP dedie d'apres php-fpm-demo.conf.example sous PHP 8.4.
Verifier php-fpm8.4 -t avant rechargement. Ne pas modifier le pool PHP 8.3 V1.
Executer sous www-data, depuis le repertoire V2 :
  php8.4 artisan demo:check
  php8.4 artisan schedule:list
Le controle doit etre OK et aucun traitement automatique metier ne doit etre
programme en demo. demo:check ne teste pas encore les sockets ou l'acces Internet.
Installer les deux services V2 fournis, mais ne pas les lancer avant ces controles.
Pas de cron V2 pour cette demonstration.

5. BASCULE DU MEME DOMAINE, UNIQUEMENT APRES ACCORD SUR UNE COURTE COUPURE

Faire une sauvegarde recente CT/V1 (base, code local modifie, .env, vhost et
services) sur le stockage de sauvegarde, pas une deuxieme copie des 26 Go.
Identifier et suspendre uniquement le scheduler et le worker V1 TechCalendar.
Mettre la V1 en maintenance et prendre la derniere sauvegarde coherente.
La base de demonstration est un snapshot : elle ne fusionne pas les nouvelles
ecritures V1. Prevenir les utilisateurs : ne pas l'utiliser pour du travail reel.

Adapter le vhost EXISTANT selon nginx-demo.fragment.example, sans changer URL,
certificat, redirection HTTPS ou proxy TLS existant. Sauvegarder le vhost avant.
Nginx -t obligatoire avant reload. Demarrer les services V2 queue et Reverb.
Verifier connexion, dossiers, documents, planning, import de lot et WebSocket.
Tout essai Global+ cree de vraies donnees dans SON environnement TEST uniquement.
Se connecter avec le compte de simulation connu ; ne pas conserver un mot de
passe partage/faible pour une demonstration accessible depuis Internet.
Le responsable a choisi de conserver uniquement la connexion Laravel, sans
authentification HTTP supplementaire ni restriction IP/VPN. Remplacer le mot de
passe de simulation partage avant ouverture et conserver les documents prives.

Pour le CT et le vhost precis controles dans cette procedure, transferer
activate-demo.sh et demo-cutover.php dans le repertoire prive de release, puis :
  bash /srv/techcalendar-v2-demo/exports/release-v2-20261015/activate-demo.sh --switch-v2
Le script exige un terminal interactif pour definir le mot de passe admin V2.
Il teste HTTP et WebSocket sur un vhost temporaire lie a 127.0.0.1:18081,
conserve les en-tetes du vhost existant, suspend les services/cron V1 et realise
un dump transactionnel V1 prive avant de basculer. Il ne touche pas a Coffrac.
Les services V2 sont actives au demarrage et les services V1 desactives.
Une erreur apres preparation du retour arriere declenche celui-ci automatiquement.
Si rollback-v1 existe deja, ne pas le supprimer pour contourner le controle :
inspecter le diagnostic et l'etat courant avant une nouvelle tentative.
Verifier ensuite la connexion HTTPS, les documents et le WS dans le navigateur :
les controles locaux ne valident pas le proxy TLS en amont ni tous les parcours.
Le script de retour est /srv/techcalendar-v2-demo/rollback-v1/rollback.sh.
Il reactive la V1 sans restaurer de dump ni ecraser la base V1.

6. RETOUR ARRIERE

Arreter seulement les deux services V2. Restaurer le vhost sauvegarde vers V1
et son socket PHP 8.3, tester Nginx puis reload. Lever la maintenance V1 et
restaurer son worker/scheduler. La base V1 et le code V1 sont restes intacts.
Les essais saisis en V2 ne sont pas reportes en V1. Conserver temporairement les
exports prives pour controle, puis definir leur suppression apres validation.
Revenir a l'authentification SSH par cle (prohibit-password) et tester une
seconde connexion avant de fermer la session root actuelle.

7. MISE A JOUR DU CODE APRES OUVERTURE DE LA DEMO

La V2 installee par archive n'a pas de depot Git. Ne pas faire git pull dans
/var/www/TechCalendar : il s'agit de la V1 conservee pour le retour arriere.
Le paquet de mise a jour ne contient ni base, ni .env, ni documents.

Sur le Mac, apres commit et push de v2 et avec un worktree propre :
  bash tools/deploy/prepare-code-update.sh
Transferer le dossier PAQUET_CODE indique vers un nouveau repertoire prive
/srv/techcalendar-v2-demo/exports/update-<revision> sur le CT 124.

Sur le CT, en root, dans ce repertoire :
  sha256sum -c SHA256SUMS
  bash update-demo.sh --update-v2

Le script controle la cible et la disponibilite des services, prepare Composer
avec PHP 8.4 avant maintenance, puis sauvegarde le code, le .env et la base V2
sous /srv/techcalendar-v2-demo/updates/. Il applique les migrations uniquement,
reconstruit les caches et relance le worker, Reverb et PHP-FPM V2. Il conserve
les sessions, mots de passe, documents et reglages. Aucune commande sur Coffrac,
aucune modification de la V1 et aucun import du snapshot local.

Le rapprochement des clients historiques est affiche en simulation seulement.
Apres verification du bilan, appliquer si souhaite depuis le repertoire V2 :
  runuser -u www-data -- php8.4 artisan clients:reconcile-legacy --apply
Cette commande ne cree/active aucun compte de connexion externe.

En cas d'erreur apres mise en maintenance, le site reste ferme et les services
V2 sont arretes pour eviter des ecritures sur un schema partiellement migre.
Conserver le diagnostic ; ne pas relancer ni restaurer automatiquement le dump.
demo:check est un controle AVANT premiere ouverture et exige des sessions/jobs
vides : il ne doit pas servir de critere pour cette mise a jour en service.
Controler ensuite la connexion HTTPS, un dossier, un document et les menus.
