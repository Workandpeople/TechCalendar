MYMAR - CORRESPONDANCES EXPLICITES, PREPARATION INACTIVE

Ce paquet prepare uniquement les identifiants confirmes par l'operateur.
Les personnes et IDs reels sont dans approved-identities.json, prive, jamais
dans le depot. Aucun rapprochement par recherche approximative ou email.
Une source vers plusieurs cibles et plusieurs sources vers une cible sont
refusees. Aucun compte n'est fusionne, cree, reactive ou modifie.

Lancer fetch-mapping.sh depuis le Mac. Aucun git pull, deploiement,
maintenance, ordonnanceur, vhost ou redemarrage n'est necessaire.
La release V2 attendue doit deja etre ouverte et sa preparation MYMAR rester
enabled=false/configured=false. Aucune configuration applicative n'est ecrite.

Le script lit statiquement les declarations/cache SQL des deux applications,
sans les executer. Puis il effectue une seule collecte SQL ponctuelle avec
le compte de maintenance existant : SELECT uniquement, transactions READ ONLY,
budget de 1500 ms par requete, attente de verrou 2s, processus limite a 20s.
Ce compte de maintenance ne sera jamais utilise par le connecteur recurrent.

La collecte verifie MySQL local 8.0/3306, les tables InnoDB et les colonnes,
puis seulement les comptes par IDs approuves. Sources et cibles sont lues
dans la MEME transaction : source active avec role Technicien Terrain,
cible Cofrac active interne avec role technicien, identites correspondant
a celles approuvees. Les departements entre parentheses peuvent changer.
Les noms ne sont pas rapproches approximativement et les IDs restent fixes.
Aucun mot de passe, email, beneficiaire, adresse ou document n'est selectionne.

Si un controle echoue, aucun approved-map.json ni rapport de succes n'est
cree pour ce run. Les erreurs SQL restent root-privees ; seuls des codes
diagnostiques fixes sont affiches. Les applications restent ouvertes.

En cas de succes, approved-map.json est cree en 0600 dans le nouveau dossier
root 0700 /var/lib/cofrac2-production/mymar-mapping-XXXXXXXX. Il contient les
IDs, empreintes d'identite, date de validation, revision et empreinte de
l'approbation. Aucun lien ni colonne de compte n'est ecrit dans les bases.
Il ne devient pas une configuration active ni un pointeur vers un planning.
requires_runtime_revalidation=true : son controle d'activite est ponctuel,
et devra etre refait avant utilisation par la future integration.
connector_enabled et can_affect_planning restent false.

Seuls mapping-summary.json et son SHA256SUMS sont copies vers un repertoire
ubuntu 0700 et recuperes sur le Mac. Le chemin et l'empreinte du fichier
root-prive y figurent pour la prochaine installation. Transmettre LOCAL_DIR
ou le resume, pas les fichiers SQL prives. Les autres propositions d'identites
non confirmees ne sont pas promues en correspondances approuvees.

Validation locale :
php vendor/bin/pest tests/Unit/MymarApprovedTechnicianMapTest.php
MYMAR_MAPPING_TEST=1 bash tools/deploy/tests/check-mymar-approved-mapping.sh

Le second test utilise MySQL 8.0.46 et un compte SELECT sur des fixtures,
compare les checksums et repete la preparation complete en conteneurs sans
reseau externe. Aucun compte, application ou service de production contacte.
