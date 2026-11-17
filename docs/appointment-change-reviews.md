# RDV modifies et planning sans GPS

## Report manuel Global+

Planning > RDV modifies est une file commune aux administrateurs, gerants et planning internes actifs. Elle ne constitue pas une synchronisation Global+ : aucun nouvel email, SMS ou appel API n'est declenche.

Seules les nouvelles modifications de date, duree ou technicien d'un RDV Cofrac deja rattache a une fiche sont suivies. La creation initiale, les notes, les affinages GPS, les observations MAR et les imports sans auteur interne ne creent pas de demande. Les anciens changements ne sont pas repris en masse.

L'observateur `updating` inscrit la version et les instantanes avant/apres dans le meme UPDATE SQL que le RDV. Pas de journal asynchrone susceptible de perdre la modification apres sauvegarde. Plusieurs changements en attente conservent le premier avant et le dernier apres, sans multiplier les lignes. Les nouvelles colonnes sont masquees dans la serialisation du modele et ne sont pas assignables depuis les formulaires.

Le report exige une confirmation explicite et la version courante, sous verrou du RDV. Une nouvelle modification invalide une ancienne confirmation ; une validation identique est idempotente. La validation seule ne change pas `updated_at` du RDV, pour ne pas perimer les formulaires de planning. Son auteur, son heure et les instantanes sont conserves dans un audit prive du dossier.

La pastille apparait dans le dossier, la navigation et l'en-tete. Le compteur et une banniere sont actualises via le GET interne de suivi existant toutes les 45 secondes, uniquement sur une page visible, sans second polling. Les changements successifs d'une ligne deja en attente sont aussi annonces. La liste affichee n'est jamais remplacee automatiquement : il faut ouvrir ou actualiser la file avant de valider.

La migration `2026_11_17_230000_add_global_plus_change_review_to_appointments` est autorisee dans les precontroles et l'application ciblee du deploiement. Elle ajoute trois colonnes et un index ; aucune integration privee MAR ni activation de service ne change.

## Planning complet sans GPS

En V2 Cofrac, une adresse non localisee n'interrompt plus l'analyse. Les techniciens et leurs disponibilites restent consultables et recherchables, meme sans position domicile. Distances et durees restent nulles, aucun point 0/0 ni trajet fictif n'est calcule, et aucune proposition automatique n'est generee. La carte ne place pas de marqueur factice. Les controles MAR restent inchanges.

Le placement manuel exige une adresse et la confirmation explicite d'un placement force, trace dans le dossier avec la precision `unverified`. Les autres conflits sont controles et les confirmations MYMAR incertaines restent non contournables.

Le remplacement rapide dispose du selecteur recherchable existant, avec nom, prenom, telephone et departements en texte uniquement. Aucun nouveau composant de selection ou droit d'acces n'est ajoute.
