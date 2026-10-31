MYMAR - preparation du traitement des demandes depuis Cofrac
==========================================================

Commande Mac : bash ./fetch-workflow.sh

Cette collecte examine les methodes d'ajout/modification de RDV, de changement
de statut, les modeles et hooks associes. Les chaines du code sont masquees
sauf une liste de noms de champs et formats techniques. Les nombres des
extraits sont masques : seuls les business_facts font foi pour les constantes.
Les extraits ne sont jamais du code executable ni un contrat valide a deployer.

Une session SQL READ ONLY bornee lit UNIQUEMENT les metadonnees des six tables
MYMAR utiles : colonnes, contraintes et presence de triggers (pas leur corps).
Aucune ligne de dossier, de compte, de commentaire ou de document n'est lue.
Aucun mot de passe, .env ou cache de configuration n'est lu par cette collecte.

Les fichiers MYMAR ne sont jamais inclus/evalues. Aucun bootstrap Laravel,
appel API, ecriture SQL, permission, paquet, service ou maintenance applicative.
Le code de la passerelle de lecture et ses privileges restent inchanges.
Le rapport verifie que les sources n'ont pas change pendant la collecte.

Ce paquet N'ACTIVE PAS les nouveaux placements. Il sert a verifier les regles
initial/apres travaux, l'archivage des anciens RDV, l'historique obligatoire,
les notifications et les changements concurrents avant une interface d'ecriture
privee, authentifiee et idempotente. Aucun appel aux anciens mutateurs API.

Rapport technique prive telecharge dans Downloads ; transmettre LOCAL_DIR,
pas le contenu des fichiers ni les identifiants. Aucun pull/deploiement Cofrac.
