# Signalements techniciens

Le signalement sur place conserve la photo, son auteur, le motif et l'heure dans une file interne durable. Chaque envoi mobile est identifie par un UUID unique : une relance du meme envoi ne cree ni seconde preuve ni seconde alerte. Un nouveau passage peut produire un autre signalement, sans ecraser les precedents.

Un nouveau signalement conserve le statut metier et le creneau natif, y compris un rappel deja arme. Photo, commentaire client, historique du passage, alerte planning et suivi du deplacement sont conserves. Le technicien ne decide plus du statut destination : la decision administrative est explicite depuis la file des signalements.

Le technicien affecte peut egalement completer un probleme deja declare par le planning, avant tout signalement terrain pour ce passage : les anciennes notes et la declaration restent tracees, sans changement de statut. Le controle de concurrence compare les donnees du creneau, de l'affectation et du rattachement ; une simple note, un affinage GPS ou un marquage en probleme ne perime pas le formulaire. Un autre RDV actif sur la fiche impose le mode historique pour ne pas deplanifier la nouvelle visite.

## Decision administrative Cofrac et MAR

Les nouveaux signalements MAR ne creent aucune commande source. La preuve locale et l'alerte planning sont enregistrees apres verification GET du RDV affecte. Une passerelle actions desactivee ne bloque plus ce depot. Aucun statut, creneau ou document MYMAR n'est modifie par le technicien.

Les administrateurs et gerants (admin explicite ou role 0) disposent d'une decision de statut dans chaque signalement. Planning non-admin conserve le suivi traite/non traite, mais pas cette nouvelle action. La lecture du statut courant est chargee seulement a l'ouverture du formulaire. Contexte chiffre lie au compte/signalement et valable 15 minutes, statut choisi explicitement, note obligatoire, controles de version et audit prive. Le suivi comptable reste independant.

Pour Cofrac, la decision concerne uniquement la prestation liee. Le retour en prise de RDV, probleme ou annulation libere le creneau seulement s'il correspond encore au passage signale, jamais une visite ulterieure. Les preuves historiques ne modifient pas le nouveau RDV.

Pour MAR, la commande additive `review_status` est reservee a une decision admin persistee. Elle accepte uniquement les etats apres travaux audites, encore actifs dans MYMAR, et un dossier affecte au perimetre pilote. La revision source est controlee dans la transaction. Le retour en prise de RDV (14) ou l'annulation (21) archive les creneaux courants sans effacer leurs dates/auteurs ; les autres decisions conservent les visites. Le statut 16 peut seulement etre conserve sur une visite courante deja active : il ne permet pas de ressusciter un ancien creneau en contournant le placement. L'historique MYMAR garde le compte Cofrac et la cle de demande. Aucun document, montant ou affectation n'est modifie.

Les anciennes commandes `on_site_problem` et `on_site_rebook` non transmises ne sont plus envoyees automatiquement. Les recus historiques restent interpretes selon leur intention d'origine ; une demande deja envoyee mais incertaine est rapprochee par GET seulement. Les decisions admin ont le meme garde-fou : aucune seconde ecriture apres timeout, reprise explicite de la meme demande, suivi local finalise seulement apres confirmation. Une note de suivi modifiee pendant l'attente n'est pas ecrasee.

Le deploiement applicatif doit etre suivi de l'actualisation des passerelles privees **lecteur et actions** avec `tools/deploy/prepare-mymar-reader-code-update.php` (sans argument, puis avec `--actions`). Le controle operateur exige la revision applicative deja installee. Une verification GET authentifiee `/v1/capabilities` exige `incident_status_review` sur les deux passerelles avant une decision administrative MAR. Les preuves restent deposables pendant cette mise a jour. Aucun nouveau droit SQL, schema ou statut MYMAR ; aucun ancien dossier n'est modifie en masse.

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
