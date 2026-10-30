MYMAR / COFRAC : DEUXIEME AUDIT EN LECTURE SEULE
Ce paquet n'est PAS un deploiement et n'active aucune liaison.

Depuis le Mac : bash fetch-audit.sh
Serveur exclusivement ns365601 / ubuntu@46.105.110.76.
Applications lues : /home/ubuntu/www/MYMAR et /var/www/cofrac2.

GARDE-FOUS
- Aucun artisan, bootstrap Laravel, composer/npm, import ou seeder.
- Aucun appel HTTP, meme vers les anciennes routes API MYMAR.
- Aucun INSERT/UPDATE/DELETE/DDL, utilisateur SQL cree ou droit modifie.
- Aucun service demarre, arrete ou recharge ; aucune configuration modifiee.
- Seuls des fichiers prives de collecte sont crees hors des applications.
- Code copie dans un paquet root prive et sommes verifiees avant execution.

LECTURE STATIQUE
Les appels de filtre et de date pertinents sont extraits du code, avec leurs
petites valeurs numeriques et formats non secrets. Le code n'est jamais execute.
Les caches PHP ne sont jamais inclus ou executes. Les objets exportes sous
la forme exacte Classe::__set_state(tableau litteral) peuvent etre ignores
comme marqueurs opaques, sans autoload ni appel de methode. Leur etat est
immediatement jete. Toute autre expression reste refusee. Le bloc database
et le fuseau app.timezone doivent rester entierement litteraux ; aucun
marqueur opaque n'y est tolere. Le rapport compte les objets ignores ailleurs.
La configuration en memoire sert a verifier le schema local et le fuseau ;
aucune valeur de mot de passe, cle ou token n'est exportee.
Sans cache, l'identite est etiquetee comme declaration .env seulement,
pas comme preuve de configuration runtime. Une contradiction interdit le SQL.
Meme avec cache compatible, la configuration runtime des processus n'est
pas pretendue verifiee : seule sa declaration statique est comparee.
L'analyse Apache est partielle et statique : pas une validation du confinement.

SQL SI LES IDENTITES ET LE SCHEMA LE PERMETTENT
Socket MySQL local exclusivement ; compte de maintenance existant, sans
modification de privileges. Transactions READ ONLY, isolation REPEATABLE READ,
limite de 1500 ms par SELECT et 20 s par session, attente de verrou 2 s.
Les reglages sont propres a la session du collecteur, pas aux applications.
Les tables utiles doivent etre des tables InnoDB, avec schema et volumes bornes.

Le rapport contient uniquement :
- References statuts/roles et liens de roles techniques.
- Compteurs groupes de RDV : statut, mar_type, is_old, durees manquantes/min/max.
- IDs/noms des techniciens/auditeurs candidats et comptes V2 correspondants,
  avec booleens de concordance d'email, statut du compte et alertes de doublons.
- Heures/fuseaux declares et plan EXPLAIN simple (pas EXPLAIN ANALYZE).
- Etapes SQL, codes de sortie et codes MySQL/SQLSTATE/ligne, jamais l'erreur brute.

Le rapprochement techniciens utilise des ensembles calcules une seule fois
(IDs candidats, doublons historiques, cles email). Pas de recherche IN/UNION
dependante ni COUNT correle par utilisateur. Aucun index ni schema modifie.
La limite de 1500 ms par SELECT est conservee, sans nouvelle tentative automatique.

Aucun RDV individuel, client/beneficiaire, adresse, telephone, document,
commentaire, mot de passe ou email brut n'est exporte. Les emails sont compares
dans SQL uniquement ; les noms contenant un email sont masques avant publication.
Pas de lien cree automatiquement, meme en cas de correspondance unique.

Une limite, un timeout, un schema inattendu ou un fichier source modifie
produit un diagnostic incomplet : ne jamais l'utiliser pour activer la liaison.
Les erreurs/requetes SQL et empreintes de configuration restent root-privees
sur le serveur. Les rapports telecharges restent prives (0700/0600).
Fournir seulement le chemin LOCAL_DIR pour l'analyse suivante.
