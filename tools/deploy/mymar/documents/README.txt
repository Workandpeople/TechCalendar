MYMAR - socle documentaire isole, NON ACTIVE

Etat : adaptateur et tests locaux uniquement. Aucun listener, aucune route web,
aucun fournisseur Laravel et aucune migration active ne referencent ce dossier.
Ne pas executer journal.sql ou les GRANT manuellement sur la production.
L'installation, les controles de preparation privilegies, le transport prive,
l'outbox Cofrac et les ecrans restent a raccorder avant toute activation.

Faits verifies le 5 novembre 2026 dans l'interface, sans ouvrir les fichiers :
- Documents libres : nom texte + suffixe facultatif VT/Audit. Pas de type fiable
  facture/devis/VT2. Les liens historiques utilisent /storage/uploads/<fichier>.
- Le champ dossiers.facture correspond aux marquages de facturation, pas aux
  documents. Ne pas l'utiliser pour retrouver les factures de travaux.
- La cible du depot est le statut 19 DEPOT (solde), pas Depot fini.

Perimetre confirme par l'utilisateur : factures et devis DE TRAVAUX uniquement.
Les factures AMO, devis MAR, impots, pieces d'identite, contrats et attestations
ne sont pas publies. Le classificateur accepte un marqueur facture/devis et
seulement le nom du beneficiaire ou des qualificatifs explicites connus autour.
Les libelles ambigus restent fermes, sans reecriture des documents sources.
Ce classement du libelle ne constitue pas une analyse du contenu du PDF.

Lecture : rendez-vous APT courant unique, dossier en 16 ou 19, technicien
actuellement affecte, actif et de role Technicien Terrain, dans la liaison
approuvee. Autorisation recontrolee pour CHAQUE ouverture. Aucun cache de liens,
chemin source ou URL publique dans le catalogue. PDF uniquement, taille bornee,
pas de symlink, hardlink persistant, chemin absolu, URL ou traversee de dossier.
Le futur controleur Cofrac devra identifier le technicien depuis la session et
la liaison privee, JAMAIS depuis un identifiant transmis librement par le client.
Les clients/delegataires n'ont aucun acces a cette passerelle.

VT2 : action future reservee a l'equipe interne habilitee, pas aux techniciens
ni aux comptes externes. Le rdv doit avoir commence, rester en statut 16, etre
unique et porter la meme revision/affectation. Le PDF est prepare en flux dans
un dossier prive 0700, avec SHA-256, taille exacte et limite de 1 Gio. Reserve
de 50 Gio surveillee, sans copie de l'archive MYMAR. Aucun gros hash sous verrou SQL.

Publication exclusive d'un nouveau fichier aleatoire : le nom est choisi par
la passerelle, pas par le navigateur. Le staging est sur le meme filesystem.
Les liens physiques transitoires servent uniquement a publier sans ecrasement ;
il ne reste qu'un lien et un fichier non inscriptible (0440) apres publication.
Le compte de service devra etre distinct de MYMAR, seul proprietaire du staging
et des nouveaux fichiers ; le groupe de lecture doit permettre a MYMAR de lire.
Les fichiers MYMAR existants ne sont jamais renommes, remplaces ou supprimes.

Attention : le disque historique MYMAR est servi publiquement. is_public=0
limite la visibilite dans son interface, pas necessairement l'acces HTTP brut.
Le futur proxy Cofrac ne doit jamais exposer ce lien, et la protection du stockage
doit etre verifiee avant l'activation documentaire. Le socle ne pretend pas
corriger ce fonctionnement historique et ne modifie pas sa configuration.

Transaction InnoDB : journal integration separe + document + statut 19 +
historique + accuse. Auteur technique existant, toujours desactive ; compte
Cofrac acteur trace. Pas de bootstrap MYMAR, controleur historique, seeder,
email, SMS, agenda ou hook applicatif. Aucune modification du schema MYMAR.
Une erreur annule toutes les lignes. Un fichier publie avant un rollback reste
conserve pour permettre une reprise ; jamais de suppression apres COMMIT incertain.
Il n'est reference par MYMAR qu'apres confirmation de la transaction.
Un UUID rejoue avec le meme contenu renvoie le recu sans seconde insertion.
Un autre contenu/acteur avec le meme UUID est refuse. Un recu absent signifie
inconnu, jamais "echec certain". Aucun POST ne doit etre rejoue automatiquement.

SQL : deux nouveaux comptes dedies lecteur/ecrivain, crees VERROUILLES lors de
la future preparation. Droits par colonnes seulement, controles exacts a chaque
connexion, aucun role actif ni privilege global. Aucun mot de passe source,
DELETE, UPDATE de document, modification de compte, de RDV, ni GRANT OPTION.
Le journal vit dans cofrac_mymar_integration, pas dans MYMAR.
Moteur transactionnel et longueurs controles au runtime. La preparation root
devra confirmer l'absence de triggers et de nouveaux champs obligatoires ;
un compte limite ne peut pas prouver seul l'absence de triggers.

Tests (local uniquement, donnees synthetiques) :
php -d memory_limit=512M vendor/bin/pest tests/Unit/MymarDocumentWorkflowTest.php
MYMAR_DOCUMENTS_TEST=1 bash tools/deploy/tests/check-mymar-documents.sh
Le test MySQL utilise des conteneurs jetables SANS RESEAU, monte le depot en
lecture seule et n'a aucun acces aux applications ou bases de production.
