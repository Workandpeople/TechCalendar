MYMAR : OBSERVATION HORS LIGNE

Ce dossier ne contient pas de connecteur reseau actif ni de deploiement.
Le script relit une calibration locale deja collectee et verifiee.
Aucun SSH, SQL, appel HTTP, bootstrap Laravel, envoi ou changement applicatif.

Depuis la racine du projet, avec une destination privee deja creee :

  php -n tools/deploy/mymar/observation/observe-calibration.php \
    /chemin/prive/vers/calibration \
    /chemin/prive/hors/application/nouvelle-observation.json

La destination doit etre nouvelle, dans un repertoire reel en 0700, hors
du projet, de /var/www et de /home/ubuntu/www. Le fichier sera cree en 0600.
Les fichiers et empreintes du rapport d'entree ne sont jamais reecrits.

Hypotheses explicites d'observation : durees en heures et heures locales
Europe/Paris. Elles restent provisoires dans les rapports. Aucune valeur
absente ou invalide n'est remplacee par une duree par defaut.

La calibration porte sur un seul dossier. Elle est donc toujours marquee
partielle, jamais comme une preuve de disponibilites exhaustives. Les liens
techniciens ne sont pas inventes. Aucun compte n'est cree ou modifie.

Meme en l'absence d'anomalies structurelles, aucun resultat ne peut affecter
le planning : connector_enabled, planning_ready, can_affect_planning sont false.

Contrat, limites et travail restant : docs/integration-mymar.md.
Test synthetique : bash tools/deploy/tests/check-mymar-observation.sh.
