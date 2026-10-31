MYMAR - backend de prise de rendez-vous : preparation DESACTIVEE
==============================================================

Commande Mac : bash ./fetch-backend.sh

Ce paquet ne deploie aucune application et ne lance aucun service. Il cree :
- un NOUVEAU compte applicatif MYMAR "Cofrac planning", DESACTIVE, sans role,
  avec un mot de passe aleatoire inconnu et une adresse reservee .invalid ;
- un NOUVEAU schema cofrac_mymar_integration pour le journal d'idempotence ;
- un NOUVEAU compte SQL local cofrac_mymar_rw_XXXXXXXX, privileges par colonnes,
  ferme ACCOUNT LOCK. Aucun changement du compte SQL de lecture existant.

Le compte technique a ete explicitement approuve. Il servira uniquement
d'auteur dans l'historique ; l'acteur Cofrac reel sera trace separement.
Aucun ancien compte, mot de passe, dossier, RDV, historique ou document n'est
modifie. Aucun mail/SMS, seeder, import ou ancien endpoint MYMAR n'est appele.

Les liens du pilote et les sources MYMAR auditees sont reverifies AVANT les
creations. Une preparation existante, des triggers inconnus, une derive
d'identite ou un changement de code/configuration provoquent un STOP.
Le compte de maintenance sert uniquement a ces ressources nouvelles et aux
verifications. Le test de lecture utilise le nouveau compte restreint.

Il est deverrouille seulement pour ouvrir UNE connexion PDO, puis immediatement
reverrouille avant le test READ ONLY d'une page d'au plus un dossier. Aucune
donnee personnelle de cette page n'est publiee ou telechargee. Un test d'action
avec ecriture DESACTIVEE doit etre refuse avant SQL. Le journal doit rester vide.
Un garde-fou independant reverrouille le nouveau compte meme si le processus
de preparation echoue ou depasse son delai. Aucun DROP automatique.

Secrets root-prives 0600 sous /var/lib/cofrac2-production (repertoires 0700).
La configuration persiste read_enabled=false ET writes_enabled=false. Aucun
port n'est ouvert ; les onglets Cofrac ne sont pas branches sur ce backend.
Le site et le pilote d'occupation actuel restent inchanges.

Le moteur est teste en conteneurs jetables MySQL 8.0.46 : transitions 1->3 et
14->16 pour placement, 1->2 et 14->15 pour probleme, heures Europe/Paris sans
decalage implicite, archivage, historique, revision optimiste et idempotence.
Une requete incertaine se retrouve par son UUID : jamais recreer un UUID pour
"retenter" un resultat inconnu. Une action source reussie n'est confirmee
qu'apres COMMIT. Aucun succes fictif sur une simple mise en file.

Limites AVANT activation reelle : brancher la file de reservations Cofrac,
les controles locaux de disponibilite/absences/trajets, les 3 onglets et leur
autorisation planning/gerant, puis revalider les statuts/fuseaux/durees et
tester le parcours complet. Les verrous du pont ne remplacent pas les
controles des autres ecrans MYMAR. L'absence de triggers est verifiee par la
preparation privilegiee : le compte runtime n'a volontairement pas TRIGGER.

En cas de STOP : transmettre le chemin du diagnostic prive, jamais le contenu
de config.private.json. Ne pas relancer aveuglement : les nouvelles ressources
restent fermees et peuvent necessiter une reprise controlee.
