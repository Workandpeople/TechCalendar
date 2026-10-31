MYMAR - lecteur prive des demandes de rendez-vous
================================================

Commande Mac : bash ./fetch-reader.sh

Cette etape installe deux NOUVEAUX services dedies :
cofrac-mymar-booking-fpm et cofrac-mymar-booking-http. Leur unique ecoute est
127.0.0.1:19847. Aucun DNS, certificat, port public, paquet systeme ou .env.
Apache public, Cofrac, MYMAR, l'API d'occupations 19846 et son timer ne sont
pas recharges/redemarres. Aucun deploiement ni maintenance du site.

Un NOUVEAU compte SQL local cofrac_mymar_br_XXXXXXXX est cree avec des droits
SELECT par colonnes uniquement. Il est deverrouille pour ce lecteur, jamais
le compte d'ecriture cofrac_mymar_rw deja prepare, qui doit rester VERROUILLE.
L'ancien compte SQL d'occupations n'est pas touche. Aucun ancien compte MYMAR
ou Cofrac, mot de passe, role, dossier, document, RDV ou historique modifie.

GET /v1/pending-bookings : demandes MYMAR en prise de RDV initiale/apres travaux,
pagination par ID (maximum 100). GET /v1/booking-commands/UUID : recu de commande.
POST reste refuse AVANT connexion SQL, meme pour une commande valide.
Les droits SELECT seuls interdisent aussi les ecritures au niveau MySQL.
Le lecteur refuse une configuration dont writes_enabled n'est pas false.

Authentification Bearer dediee, controle loopback/Host, refus des en-tetes de
proxy, limites de taille/temps/memoire et compte Linux sans shell. Sources
MYMAR, release ouverte, auteur technique desactive et liens reverifies avant
installation. Secrets root:compte-dedie 0640 dans un repertoire 0750 readonly.
Ils ne sont pas fournis au runtime Cofrac et ne sortent pas du serveur.

Les controles HTTP lisent AU PLUS une demande pour valider le contrat et
verifient les refus, dont un POST synthetique avec porte d'ecriture fermee.
Aucun beneficiaire/adresse/email n'est publie ou telecharge : seul summary.json
avec indicateurs techniques et compteurs est recupere. Aucun mail/SMS envoye.

En cas d'echec : seuls les nouveaux services sont arretes, le NOUVEAU compte
lecteur est verrouille et sa configuration desactivee. Pas de DROP automatique.
Une installation partielle ou deja presente est refusee ; ne pas relancer
aveuglement. L'arret manuel affiche a la fin fonctionne independamment des
anciens fichiers d'approbation/configuration et ne coupe pas l'API 19846.

Cela ne branche PAS encore les 3 onglets ou l'outbox Cofrac. Aucun appel
MYMAR n'est ajoute au rendu des pages, aucun blocage des disponibilites ni
placement n'est active. La couverture de techniciens validee reste le pilote.
Prochain lot : cache/liste des demandes, controles et reservation native,
puis branchement explicite du parcours avant toute activation d'ecriture.
