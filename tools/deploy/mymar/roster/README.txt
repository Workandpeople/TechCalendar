RAPPROCHEMENT GLOBAL DES TECHNICIENS COFRAC / MYMAR

Le collecteur lit les comptes actuels des deux bases de production, dans une
transaction READ ONLY, sans demarrer les applications ni appeler leurs API.
Il ne reutilise pas les anciens dumps. Les requetes sont bornees a 1500 ms,
la collecte a 45 secondes et les comptes a 1000 par application.

Seuls les techniciens terrain actifs de MYMAR et les techniciens internes
actifs de Cofrac peuvent etre proposes pour le raccordement. Ni les clients,
delegataires, administrateurs ou gerants, ni les comptes desactives ne sont
transformes en techniciens. Aucun compte n'est cree, fusionne ou reactive.

Le plan conserve les correspondances existantes dont les profils sont actifs,
inclut les identites confirmees par l'operateur et propose les noms complets
uniques ou les emails uniques coherents avec le nom complet. L'ordre nom/prenom,
la casse, les accents et les departements entre parentheses sont normalises.
Un email partage, une contradiction, un nom incomplet ou un homonyme sans email
distinct verifie reste en revue : aucune recherche approximative ni premier
resultat choisi. Une correspondance existante n'est pas remplacee silencieusement.

Les emails bruts et mots de passe ne sont pas exportes. Les emails sont compares
via une empreinte salee pour cette seule collecte, non presente dans le rapport.
Les habilitations MAR, secteurs et presence de GPS Cofrac sont controles, pas
ajoutes automatiquement. Raccorder une identite ne lui attribue pas de droits.
Les noms et IDs professionnels du rapport sont prives : ne pas publier le JSON.

Lancer fetch-roster.sh depuis le paquet prive prepare dans Downloads.
Le fichier confirmed-identities.json est prive et n'est jamais inclus au depot.
Renvoyer uniquement LOCAL_DIR. Le plan et les identites proposees sont aussi
conserves root-prives sur le serveur pour preparer ensuite une application
groupee, apres verification des cas ambigus et des passerelles existantes.

CE PAQUET NE CHANGE PAS LES CORRESPONDANCES ACTIVES.
Aucun code, schema, configuration, compte ou ligne MYMAR n'est modifie.
Aucune maintenance, activation de passerelle, nouvelle permission SQL,
modification de planning, sauvegarde volumineuse ou notification.
Seuls de petits fichiers de diagnostic et les verrous techniques sont crees.
Au moins 16 Mio libres sont requis pour ces fichiers. Les applications restent ouvertes.

Validation locale :
php vendor/bin/pest tests/Unit/MymarTechnicianRosterTest.php tests/Unit/MymarApprovedTechnicianMapTest.php
MYMAR_ROSTER_TEST=1 bash tools/deploy/tests/check-mymar-technician-roster.sh
La repetition utilise MySQL 8.0.46 et un compte SELECT sur des fixtures, puis
un collecteur Linux isole, sans reseau externe. Les empreintes des comptes,
applications et configurations sont comparees avant/apres. Un resultat SQL
incomplet ne produit pas de rapport de succes. Aucun appel applicatif autorise.
