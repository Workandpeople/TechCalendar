Diagnostic explicite de l'echec du controle applicatif MAR.

La revision applicative doit correspondre a SOURCE_REVISION. Aucun pull, deploiement,
migration, rechargement de service, changement de compte ou activation d'ecriture.

Le script controle le schema V2, les techniciens actifs, les fichiers/jetons
accessibles au runtime, le lecteur de demandes, sa projection en cache et chaque
controle de disponibilite du pilote. Si ce dernier echoue, une lecture GET bornee
de l'API d'occupation distingue HTTP, format, portee et anomalies de donnees.
Aucun POST MYMAR, rendez-vous, email ou SMS de test.

Le bootstrap Laravel et la projection peuvent toucher les caches techniques.
Aucune donnee metier n'est creee, modifiee ou supprimee.

Le rapport contient des booleens, compteurs, classes d'exception, codes d'anomalie
et au maximum 20 references techniques de dossiers/RDV par technicien. Aucun
beneficiaire, adresse, email, jeton, mot de passe, corps HTTP ou erreur brute.
Les sorties brutes restent privees root sur le serveur.

Sur le Mac : bash ./fetch-diagnostic.sh
Transmettre le LOCAL_DIR final pour poursuivre. Ne pas relancer le paquet
d'activation en echec et ne pas restaurer la base.
