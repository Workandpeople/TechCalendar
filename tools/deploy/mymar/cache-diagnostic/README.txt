MYMAR : DIAGNOSTIC STATIQUE DU FORMAT DU CACHE

Ce paquet n'est pas un correctif applicatif ni un deploiement.
Il explique pourquoi le parseur litteral du deuxieme audit a refuse
bootstrap/cache/config.php. Il ne leve aucun garde-fou et ne lance aucun SQL.

Depuis le Mac : bash fetch-audit.sh
Serveur fixe : ubuntu@46.105.110.76 / ns365601.

Seul fichier applicatif lu :
/home/ubuntu/www/MYMAR/bootstrap/cache/config.php
Il est lu comme du texte, jamais inclus ou execute. Limite : 2 Mio,
128 Mio de memoire, execution totale limitee a 20 secondes.

Le rapport contient la taille du cache, son accessibilite dans les limites,
le resultat du parseur, et, en cas de refus, la ligne et les TYPES de tokens
PHP concernes. Aucune valeur, aucun identifiant PHP, extrait de code,
mot de passe, cle, token, empreinte de configuration ou donnee metier
n'est exporte. L'absence de changement entre lectures est controlee.

Aucun .env lu, SQL, bootstrap Laravel, artisan, appel HTTP, mail ou SMS.
Aucune modification des applications, configurations, bases ou services.
Seuls des fichiers de diagnostic prives hors applications sont crees.
Rapports 0700/0600, copie root privee du collecteur avec sommes verifiees.

Le parseur reste strict : une expression inconnue reste refusee.
Fournir uniquement LOCAL_DIR apres execution. Aucun secret a copier.
