# Collecte des references TechCalendar pour le seed V2

## Perimetre

Collecte des utilisateurs (y compris supprimes logiquement), prestations, alias,
departements, affectations des techniciens, absences, delegataires, templates
de mails et identites des expediteurs. Les identifiants source sont conserves
pour reconstruire les relations explicitement.

Exclusions : mots de passe, tokens, sessions, configuration SMTP, parametres
applicatifs, payloads API bruts et contenu des fichiers. Les chemins des logos
ne sont que des references ; les images devront etre collectees separement.
Les champs libres des templates restent du contenu metier : ne pas y stocker
de secrets. L'archive contient des donnees personnelles, ce n'est pas une
anonymisation. Base64 est un encodage, pas un chiffrement.

Ce collecteur ne peuple aucune base. Le seed reel et la conversion Coffrac
doivent etre prepares et verifies avec les exports avant tout reset local.
Les expediteurs devront avoir une configuration locale sans livraison reelle ;
les comptes devront recevoir des mots de passe locaux. Ne pas injecter ces
donnees dans un environnement capable d'envoyer des mails, notifications ou
requêtes CRM en production.

## Sans SSH ni deploiement V2

Sur le Mac, depuis le depot :

```bash
php tools/fixtures/prepare-techcalendar-export.php /var/www/TechCalendar | pbcopy
```

Coller le contenu du presse-papiers dans la console du CT TechCalendar puis
l'executer. Adapter le chemin si le projet est installe ailleurs. Le PHP du CT
doit etre celui qui execute habituellement l'application.

La commande est autonome, elle ne necessite pas le nouveau collecteur sur le
serveur. Elle lit la configuration en cache ou dans le projet sans demarrer les
providers applicatifs. Les seules ecritures sont une archive et son checksum
dans un nouveau repertoire prive du dossier temporaire, hors du site Web.
Le repertoire a les droits 0700 et les fichiers 0600.

Le collecteur exige des tables InnoDB, ouvre une transaction READ ONLY avec un
snapshot coherent, et utilise des SELECT pages par cle primaire. Il ne pose
aucun verrou explicite sur les tables et ne modifie aucun enregistrement. Une
table absente, un schema incompatible ou un volume inattendu fait echouer la
collecte ; aucun export partiel n'est annonce comme reussi. Comme toute lecture
SQL, la collecte consomme un peu de CPU/I/O et peut brievement attendre un DDL.

La sortie donne le repertoire, la taille, le SHA-256 et les effectifs. La ligne
RECUPERATION indique la commande `base64` pour afficher l'archive en texte.
Ne copier que le texte encode, pas l'invite du shell. Pour une grosse sortie,
proceder par morceaux plutot que risquer de tronquer le presse-papiers.

Sur le Mac, apres avoir copie le texte encode :

```bash
(
    set -eu
    set -o pipefail
    umask 077
    DEST=$(mktemp -d "$HOME/Downloads/techcalendar-references-XXXXXXXX")
    pbpaste | tr -d '[:space:]' | base64 --decode > "$DEST/references.json.gz"
    gzip -t "$DEST/references.json.gz"
    shasum -a 256 "$DEST/references.json.gz"
    printf 'Dossier local : %s\n' "$DEST"
)
```

Comparer l'empreinte avec le SHA256 affiche dans le CT : elles doivent etre
identiques. Ne pas utiliser un fichier tronque ou dont l'empreinte differe.
Ne rien placer dans `public/`, ne pas ouvrir de port HTTP pour telecharger
l'archive et ne pas l'ajouter a Git.

## Coffrac

Un dump complet du 14 octobre 2026 est deja disponible localement dans
`~/Downloads/coffrac-migration-20261014/coffrac.sql.gz`. Ce dump contient des
donnees et des secrets de production : le conserver hors du depot et hors du
web. Il ne faut pas transferer ses dizaines de Mo par presse-papiers.

Il doit etre analyse dans une base isolee avant conversion, jamais importe
directement dans TechCalendar. Les fichiers applicatifs recuperes ne doivent
pas etre executes. Les 214 Go de `public/documents` n'ont pas ete copies ; la
presence de lignes documentaires en base ne signifie pas que leurs fichiers
sont disponibles en local.
