MYMAR - cache local et affichage pilote en observation
====================================================

Commande Mac : bash ./fetch-pilot.sh
Le paquet met d'abord a jour le code Cofrac avec les controles habituels,
sauvegarde V2, courte maintenance et reouverture HTTPS des DEUX domaines.
Les controles de release ont lieu AVANT la maintenance. Aucun npm, Composer,
seeder ou import en production. Les modes mail/SMS/CAPCEE sont conserves.

Il active ensuite uniquement le cache et l'affichage MYMAR pour les liens
explicitement approuves. Aucun appel HTTP/SQL MYMAR depuis les pages.
Aucun rendez-vous Cofrac, compte, droit, mot de passe, document ou donnees
MYMAR n'est cree, modifie ou supprime. Les sources/cibles sont reverifiees
en lecture seule au moment de l'installation. Pas de modification MYMAR.

Une commande Laravel dediee lit l'API loopback existante toutes les minutes,
grace a un nouveau service/timer systemd limite. Le scheduler global n'est
PAS lance. Le CRM recoit uniquement le jeton API, jamais le mot de passe SQL
MYMAR. Configuration root:cofrac2-app, repertoire 0750, fichiers 0640.

Cache atomique borne : 7 jours passes et 21 jours a venir, 2000 occupations
maximum. Dernier cache valide conserve 24 h en cas d'echec ; avertissement
au-dela de cinq minutes et indication de couverture partielle. Les beneficiaires,
adresses, documents et trajets MYMAR ne sont pas importes.

Les cartes MAR sont distinctes, en lecture seule, dans les deux calendriers.
IMPORTANT : ce pilote NE BLOQUE PAS les disponibilites ou suggestions !
Le fuseau Europe/Paris, les durees en heures et les statuts 3/16 restent des
hypotheses visibles, a confirmer avant toute activation du blocage.

Une premiere lecture valide est obligatoire AVANT l'affichage et le timer.
En cas d'echec du pilote, ses flags sont desactives ; le site et l'API privee
ne sont pas arretes. Ne pas relancer aveuglement apres un STOP : transmettre
le diagnostic. Une installation existante n'est jamais remplacee.

Controle serveur, sans appel API ni envoi :
sudo -u cofrac2-app env -i PATH=/usr/sbin:/usr/bin:/sbin:/bin HOME=/var/lib/cofrac2-app php8.4 /var/www/cofrac2/artisan mymar:observation-status

Le rapport summary.json fournit le chemin root-prive et la commande stop-pilot.sh.
Cet arret masque les cartes et stoppe le nouveau timer uniquement. L'API privee,
les RDV et le site restent intacts ; aucun retour arriere SQL automatique.
