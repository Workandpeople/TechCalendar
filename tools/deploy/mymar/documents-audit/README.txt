MYMAR - audit facture/devis, VT2 et passage en Depot

But : preparer la consultation des pieces par le technicien affecte, puis le
depot VT2 par l'equipe dans Cofrac avec transmission et changement d'etat MYMAR.
Cette collecte n'active aucune de ces fonctionnalites.

Lecture statique de neuf fichiers PHP precis (sans include/eval ni bootstrap
de MYMAR), avec masquage des chaines hors liste blanche et des nombres hors
faits metier connus. Aucun .env, cache de configuration, secret ou fichier
client lu. Aucun dossier de documents parcouru. Les hashes sont recontroles
en fin de collecte.

SQL local via compte de maintenance deja existant, transactions READ ONLY,
delai maximal 1,5 seconde par requete et timeout de processus. Metadonnees de
quatre tables uniquement, puis id/libelle/suppression du catalogue des statuts.
Aucune ligne de dossier, document, utilisateur ou beneficiaire selectionnee.
Pas de trigger SQL exporte, pas de valeur de connexion, pas de corps d'erreur.

Ne modifie ni code, configuration, compte, droits SQL, schema, etat MYMAR,
planning Cofrac ou service. N'appelle aucune route, n'envoie aucun message.
Seuls les scripts et rapports prives de collecte sont ecrits sur le serveur.
Un schema inattendu, un changement de source ou une troncature bloque la collecte.
Les indicateurs JSON MySQL true/false et 0/1 sont normalises explicitement.
En cas d'arret, l'etape et l'emplacement du controle sont affiches, sans message
brut d'exception ni valeur de configuration.

Sur le Mac : bash ./fetch-audit.sh
Saisir le mot de passe SSH uniquement dans le terminal. Le script copie ce
paquet, lance sa verification root, puis recupere quatre rapports JSON/code
masque et leurs empreintes sous Downloads (repertoire 0700, fichiers 0600).
Transmettre seulement LOCAL_DIR ; ne pas publier les rapports.
