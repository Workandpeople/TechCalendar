# Signalements techniciens

Le signalement sur place conserve la photo, son auteur, le motif et l'heure dans une file interne durable. Chaque envoi mobile est identifie par un UUID unique : une relance du meme envoi ne cree ni seconde preuve ni seconde alerte. Un nouveau passage peut produire un autre signalement, sans ecraser les precedents.

## Acces et notifications

- Rubrique Planning > Signalements techniciens : super-admins, gerants et planning internes actifs, hors changement de mot de passe obligatoire.
- Le compteur de l'en-tete et la banniere de nouvel incident sont actualises par un GET toutes les 45 secondes sur une page visible. Pas de rechargement du tableau, pas de polling dans les espaces clients/techniciens, pause en arriere-plan et temporisation progressive en cas d'echec.
- La file est commune a l'equipe. L'alerte reste dans le compteur jusqu'au traitement, independamment de la lecture par un collegue. Aucun email interne supplementaire n'est envoye.
- La portee choisie sur le terrain pour la photo/commentaire reste appliquee. La file, ses notes et ses audits de traitement sont strictement internes ; ils ne changent pas cette portee.

## Deux suivis independants

Le planning renseigne une note et passe le signalement de **A traiter** a **Traite** (reouverture possible). Cela ne modifie pas l'etat du dossier ni son rendez-vous.

Les comptes habilites a la comptabilite disposent de **Deplacement a verifier**, **A facturer**, **Facture** et **Non facturable**. Une note/reference accompagne chaque decision. Ce suivi est distinct du marquage de facturation du dossier : aucun paiement ou facture n'est cree automatiquement, aucune seconde facturation de dossier n'est debloquee.

Les modifications sont verrouillees et controlees par version. Une page obsolete doit etre rechargee ; elle n'ecrase pas la decision d'un collegue. Un audit prive conserve les valeurs precedentes et nouvelles, l'auteur et la date.

## Deploiement et reprise

La migration ciblee `2026_11_09_200000_create_technician_incidents_table` est ajoutee aux gardes-fous du deploiement. Les index couvrent les compteurs planning/comptabilite et la recherche par dossier. La file est paginee par 20, avec chargement groupe des relations.

`php artisan tech:backfill-incidents` effectue un diagnostic sans ecriture. L'option `--apply` reprend les derniers signalements historiques encore conserves dans le JSON des rendez-vous, uniquement si dossier, auteur, prestation et photo concordent. La reprise est idempotente, ne recree pas de commentaire et ne change aucun rendez-vous, droit ou statut ; aucun message n'est envoye. Le deploiement l'execute apres la migration. Les donnees incoherentes sont comptees comme ignorees dans le diagnostic prive. Les signalements ecrases avant cette evolution ne peuvent pas etre reconstruits automatiquement.

En cas de rendez-vous deja replace avant la reprise, son ancienne date n'est pas devinee : seule l'heure attestee du signalement est reprise. Aucune modification de MYMAR ni lecture de ses documents n'est necessaire pour ce lot.
