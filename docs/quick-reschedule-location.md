# Replacement rapide et localisation

Le replacement rapide conserve la recherche d'une position verifiee pour une nouvelle visite (adresse, puis commune unique en repli). Les coordonnees historiques importees ne sont pas reutilisees aveuglement et aucun point fictif n'est genere.

Si aucune position exploitable n'est trouvee, le premier envoi ne cree ni ne modifie le RDV. Il renvoie une contrainte `location_unverified` dans la confirmation habituelle de placement force. Le planning doit verifier lui-meme l'adresse et la faisabilite du deplacement, puis cocher la confirmation. Le second envoi exige `force_placement=true` et `force_confirmed=true` ; toutes les versions et contraintes sont reverifiees.

Le RDV confirme conserve l'adresse textuelle et des coordonnees nulles, avec `external_payload.location_precision=unverified`. L'auteur, l'heure et toutes les contraintes confirmees sont traces dans l'activite interne `appointment_forced`. Le message "position et trajet a verifier manuellement" reste visible apres validation et dans l'espace du technicien. Aucun geocodage du dossier ni point GPS n'est fabrique pour masquer le probleme.

Absences, chevauchements, habilitations et absence de GPS sont presentes ensemble avant confirmation. Une position approximative ou non verifiee ne prouve pas que deux visites se deroulent au meme endroit. Les reservations MYMAR incertaines ou non verifiables restent impossibles a forcer. Une adresse vide, une version perimee ou un acteur non habilite restent bloquants.

Un ancien RDV protege par une visite, un controle ou un lot traite n'est pas modifie : une nouvelle reservation est creee suivant le workflow existant. Rapports, pieces, resultats du controle et facturation restent conserves. Aucun nouveau cron, migration ou droit d'acces n'est necessaire pour ce correctif.
