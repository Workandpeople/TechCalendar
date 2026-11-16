# Signalements techniciens

Le signalement sur place conserve la photo, son auteur, le motif et l'heure dans une file interne durable. Chaque envoi mobile est identifie par un UUID unique : une relance du meme envoi ne cree ni seconde preuve ni seconde alerte. Un nouveau passage peut produire un autre signalement, sans ecraser les precedents.

Un nouveau signalement sur le creneau courant remet desormais la fiche native en **Prise de RDV**, pour la retrouver directement dans la file a planifier. Photo, commentaire client, historique du passage, alerte planning et suivi du deplacement sont conserves. L'ancien rendez-vous reste techniquement marque comme passage echoue (`problem`) pour ne plus occuper le planning ; ce n'est pas l'etat metier de la fiche, qui devient `PRISE DE RDV`. Un rappel encore arme est desarme : le retour en prise de RDV est immediat.

Le technicien affecte peut egalement completer un probleme deja declare par le planning, avant tout signalement terrain pour ce RDV : les anciennes notes et la declaration restent tracees, puis la fiche revient en prise de RDV. Le controle de concurrence compare les donnees du creneau, de l'affectation et du rattachement ; une simple note, un affinage GPS ou un marquage en probleme ne perime pas le formulaire. Un autre RDV actif sur la fiche impose le mode historique pour ne pas deplanifier la nouvelle visite.

## Retour en prise de RDV MAR

Les nouveaux signalements MAR utilisent la commande additive `on_site_rebook` : seul un RDV apres travaux verifie, courant et affecte au technicien peut faire passer MYMAR de 16 a **14 (prise de RDV apres travaux)**. Le creneau d'origine est archive (`is_old=1`) sans supprimer ni modifier ses dates, sa duree ou son auteur. Le motif court et l'historique MYMAR sont conserves ; photo et commentaire complet restent dans Cofrac, avec alerte planning et suivi du deplacement.

La commande historique `on_site_problem` reste supportee avec sa transition 16 vers 15. Les anciennes commandes en attente et les anciens recus ne sont jamais reinterpretes comme un retour en prise de RDV. Chaque retour de MYMAR doit confirmer l'action et l'etat 14 avant d'annoncer la reouverture ; la revision est controlee dans la transaction source. Une replanification concurrente, une autre affectation ou une confirmation incertaine ne declenche jamais de seconde ecriture automatique.

Le deploiement applicatif doit etre suivi de l'actualisation des passerelles privees **lecteur et actions** avec `tools/deploy/prepare-mymar-reader-code-update.php` (sans argument, puis avec `--actions`). Le controle operateur exige la revision applicative deja installee. Pendant cette transition, une verification GET authentifiee `/v1/capabilities` refuse tout nouvel envoi MAR si l'une des deux passerelles est encore ancienne : aucun POST source ni photo/incident local ne sont crees, et le brouillon du navigateur reste disponible. Les anciennes demandes deja enregistrees gardent leur reprise habituelle.

Cela ne demande aucun nouveau droit SQL, schema ou statut MYMAR ; aucun ancien dossier n'est repris en masse. Un simple deploiement applicatif ne suffit pas pour activer cette nouvelle commande.

## Preuve d'un ancien passage natif

- L'option explicite **Preuve d'un ancien passage** ajoute seulement la photo, le commentaire visible au client et un signalement interne a traiter. Ni le RDV actuel (horaire, affectation, statut, consignes, metadonnees) ni sa prestation (etat, rappel, facturation) ne sont modifies. Le compteur dossier est incremente pour signaler le nouvel ajout.
- Le formulaire conserve un contexte de visite signe HMAC, lie a l'ancien technicien, au RDV, a la prestation et au dossier, valable 24 h. Il autorise uniquement ce depot historique, meme apres reattribution : il ne donne jamais acces a la nouvelle fiche, a ses documents ou aux donnees du nouveau technicien. Comptes inactifs, changement de role, cloture, annulation, rattachement modifie et signatures expirees sont refuses avant et apres preparation de la photo.
- La date du creneau d'origine et la date de reception sont distinguees dans le commentaire, la photo et le journal. Un passage futur est refuse. L'UUID reste idempotent ; un second envoi avec un nouvel UUID pour le meme technicien/RDV/passage est bloque.
- Les brouillons natifs locaux sont accessibles depuis **Mon planning**, tries par cle du compte, avec lecture bornee a 60 cles / 30 entrees. Photo et commentaire restent sur l'appareil ; aucune reprise ni transmission automatique. Une reprise depuis le planning ou apres changement de creneau demande confirmation du mode historique.
- Un brouillon anterieur a ce correctif ne contient pas de contexte signe. Si le technicien reste affecte, il retrouve sa photo/commentaire puis confirme manuellement la date et l'heure du passage avec un contexte courant autorise. Si le RDV a deja ete reattribue, cet ancien brouillon ne permet pas d'accorder un acces retrospectif : contacter le planning. La photo n'est pas effacee automatiquement avant l'expiration locale de 24 h.
- Ce mode historique concerne uniquement les RDV natifs Cofrac. Les commandes MAR gardent leurs controles et leur workflow existants ; les champs historiques natifs sont explicitement interdits sur le formulaire MAR.

## Acces et notifications

- Rubrique Planning > Signalements techniciens : super-admins, gerants et planning internes actifs, hors changement de mot de passe obligatoire.
- Le compteur de l'en-tete et la banniere de nouvel incident sont actualises par un GET toutes les 45 secondes sur une page visible. Pas de rechargement du tableau, pas de polling dans les espaces clients/techniciens, pause en arriere-plan et temporisation progressive en cas d'echec.
- La file est commune a l'equipe. L'alerte reste dans le compteur jusqu'au traitement, independamment de la lecture par un collegue. Aucun email interne supplementaire n'est envoye.
- La photo et le commentaire terrain sont toujours visibles au client et a l'equipe interne, jamais aux delegataires. Aucun choix de visibilite a effectuer par le technicien. La file, ses notes et ses audits de traitement sont strictement internes.

## Deux suivis independants

Le planning renseigne une note et passe le signalement de **A traiter** a **Traite** (reouverture possible). Cela ne modifie pas l'etat du dossier ni son rendez-vous.

Les comptes habilites a la comptabilite disposent de **Deplacement a verifier**, **A facturer**, **Facture** et **Non facturable**. Une note/reference accompagne chaque decision. Ce suivi est distinct du marquage de facturation du dossier : aucun paiement ou facture n'est cree automatiquement, aucune seconde facturation de dossier n'est debloquee.

Les modifications sont verrouillees et controlees par version. Une page obsolete doit etre rechargee ; elle n'ecrase pas la decision d'un collegue. Un audit prive conserve les valeurs precedentes et nouvelles, l'auteur et la date.

## Deploiement et reprise

La migration ciblee `2026_11_09_200000_create_technician_incidents_table` est ajoutee aux gardes-fous du deploiement. Les index couvrent les compteurs planning/comptabilite et la recherche par dossier. La file est paginee par 20, avec chargement groupe des relations.

`php artisan tech:backfill-incidents` effectue un diagnostic sans ecriture. L'option `--apply` reprend les derniers signalements historiques encore conserves dans le JSON des rendez-vous, uniquement si dossier, auteur, prestation et photo concordent. La reprise est idempotente, ne recree pas de commentaire et ne change aucun rendez-vous, droit ou statut ; aucun message n'est envoye. Le deploiement l'execute apres la migration. Les donnees incoherentes sont comptees comme ignorees dans le diagnostic prive. Les signalements ecrases avant cette evolution ne peuvent pas etre reconstruits automatiquement.

En cas de rendez-vous deja replace avant la reprise, son ancienne date n'est pas devinee : seule l'heure attestee du signalement est reprise. Aucune modification de MYMAR ni lecture de ses documents n'est necessaire pour cette reprise historique native.
