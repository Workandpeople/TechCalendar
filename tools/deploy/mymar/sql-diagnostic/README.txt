MYMAR : RELECTURE D'UN AUDIT SQL INCOMPLET

Ce paquet ne relance PAS l'audit SQL et n'est pas un deploiement.
Il ne lit aucun fichier applicatif, .env, cache Laravel ou document.
Il retrouve le diagnostic termine correspondant exactement a l'empreinte
TARGET_SOURCE_SHA256, sous /var/lib/cofrac2-production/mymar-readonly-*.
Une cible absente ou ambigue interdit la lecture.

Seuls les fichiers de diagnostic deja produits par le precedent collecteur
sont lus. Le rapport exporte les fichiers presents et leurs tailles, les codes
numeriques MySQL/SQLSTATE et lignes SQL, et les ecarts aux huit tables/colonnes
attendues. Aucun message SQL brut, extrait de requete, compte utilisateur,
nom de personne, email, mot de passe ou contenu de configuration n'est exporte.
Les codes de sortie n'etaient pas traces par le premier collecteur : un code
d'erreur absent ne prouve pas la reussite d'une etape.

Aucun SQL, bootstrap Laravel, API, artisan, mail/SMS, modification applicative,
configuration ou service. Seuls un paquet root prive et des fichiers de
diagnostic prives sont crees hors applications. Limites de lecture et timeout
20 secondes. Rapports 0700/0600 avec SHA-256.

Depuis le Mac : bash fetch-audit.sh
Serveur fixe : ubuntu@46.105.110.76 / ns365601.
Transmettre uniquement LOCAL_DIR apres execution.
