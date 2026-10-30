MYMAR - verification a blanc des chevauchements
============================================

Ce paquet autonome NE DEPLOIE PAS de nouvelle version applicative.
Il ne fait ni git pull, migration, import, maintenance, test SMTP ou appel MYMAR.
Il ne change aucune activation. Le pilote reste en observation, sans blocage.

Lecture du cache SQL LOCAL et des rendez-vous Cofrac actifs des seuls techniciens
du pilote, dans une meme transaction MySQL explicitement READ ONLY. Le cache est
lu directement, sans Cache::get qui pourrait supprimer les entrees expirees.
Le jeton API n'est jamais charge par le script de lecture. Pas de lecture SQL
MYMAR ni utilisation du compte de maintenance. Fichiers/config/services compares.

Le rapport distingue les chevauchements reels des resultats inconnus : cache
absent, perime, anomalie, derniere synchro echouee, technicien desactive ou periode
hors couverture. Les rendez-vous deja existants ne sont ni supprimes ni deplaces.
Les bornes sont semi-ouvertes : finir a 10h quand un autre commence a 10h n'est
pas un chevauchement. Les comparaisons se font en instants absolus, pas en heures
du navigateur. Les trajets et la co-localisation MYMAR ne sont PAS verifies.

Maximum 5000 rendez-vous Cofrac et 2000 occupations en cache ; requetes SQL
limitees a 1,5 seconde, processus limite a 30 secondes. Rapport prive limite a
30 paires avec identifiants techniques et horaires seulement, sans noms,
beneficiaires, adresses, emails, commentaires, documents ou credentials.

Laravel et ses providers ne sont PAS amorces : seuls l'autoloader, la configuration
compilee et les composants SQL/Eloquent sont charges en CLI par cofrac2-app.
Cela evite aussi les ecritures du cache de parametres au bootstrap. Les nouvelles
classes de calcul sont chargees depuis un repertoire dedie root:cofrac2-app,
jamais ecrites dans /var/www/cofrac2. Les lectures SQL du diagnostic sont bornees
et READ ONLY ; le cache n'est ni modifie ni purge. Aucun droit ni mot de passe
n'est modifie et aucun service n'est demarre, arrete ou recharge.

Commande Mac : bash ./fetch-conflicts.sh
Transmettre uniquement LOCAL_DIR. Les horaires compares dans le navigateur sont
des preuves d'affichage pour le pilote, pas une validation globale des durees,
des types de visite ou des disponibilites de tous les techniciens MYMAR.
