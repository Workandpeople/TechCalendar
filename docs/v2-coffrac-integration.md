# Fusion de Coffrac dans TechCalendar V2

## Documents collectés, 15 octobre 2026

Les fichiers de la sélection hors dossiers entièrement facturés sont raccordés
au registre existant en lecture seule. Les métadonnées du snapshot déterminent
le dossier propriétaire ; aucun fichier ni dossier n'est dupliqué en base.
Accès privé depuis les dossiers, la prise de RDV et le planning, sans appel Coffrac.
La configuration locale, les limites et les effectifs sont documentés dans
`v2-simulation.md`, section « Documents collectes sans seconde copie ».

Recette : 540 tests PHP / 4 074 assertions, dont 18 tests du raccordement, et
téléchargement navigateur local vérifié par SHA256 identique au fichier source.
Les 34 fichiers sans référence dans le dump et les 3 fichiers vides ne sont pas
présentés comme utilisables. Aucun changement sur la production.

## Après visite natif, 15 octobre 2026

Premier parcours opérationnel, sans appel à Coffrac et sans nouvelle copie de
dossier, rendez-vous, document ou calcul de statistiques :

- Confirmation explicite d'un RDV terminé et non retiré : **RDV visité**.
- Saisie de la date réelle, de la conclusion et des observations : **Contrôle RT**.
- Le gérant/admin peut demander une correction : **Non-conformités**. La nouvelle
  conclusion est ensuite soumise à nouveau au RT. Les fiches historiquement en
  recontrôle peuvent aussi être soumises, sans présumer qu'une nouvelle visite a eu lieu.
- Validation réservée gérant/admin, avec un **PDF existant du dossier** disponible
  en stockage privé : **Rapport Genius**. Cette action ne génère ni PDF, ni signature,
  ni facture, et n'envoie rien à Global+ ou CAPCEE.
- La conclusion validée alimente `physical_satisfaction` des lignes de lot liées
  à la prestation. Les contacts restent indépendants ; les lignes exclues restent
  exclues du calcul. Les widgets et le statut du lot réutilisent
  `LotAutoCompletionCalculator` et `LotStatusService`, sans nouvelle formule.
- `satisfactory` / `unsatisfactory` / `unverifiable` donnent respectivement
  `true` / `false` / `null`. Le dernier cas ne devient jamais un résultat défavorable.
- Le retrait motivé d'une validation retire son résultat des statistiques jusqu'à
  revalidation. Ancien résultat, date, validateur et référence du PDF sont conservés
  dans l'historique. Un lot archivé bloque le traitement. Un envoi CAPCEE existant
  bloque le retrait local isolé, car la correction distante exige sa propre version.
- Verrouillage transactionnel, version optimiste du dossier, contrôle du périmètre
  prestation/document et protection contre reset, déplacement ou déliaison d'une
  visite déjà engagée. Un formulaire rejoué ne produit pas un second traitement.
- Accès depuis **Après la visite** dans le dossier, depuis le modal du planning
  et depuis le détail physique d'une ligne de lot. L'historique est aussi visible
  dans les commentaires existants.

Les contrôles historiques ne sont pas automatiquement déclarés validés : leurs
valeurs source restent archivées. Pour reprendre un contrôle RT sans conclusion
native, demander une correction motivée puis le soumettre avec sa conclusion.
La planification d'une nouvelle visite de recontrôle, les litiges, les questionnaires
terrain, la génération/signature du rapport et la facturation restent à intégrer.
Le rôle RT utilise actuellement les droits gérant/admin ; les techniciens n'ont
pas reçu un accès global aux documents internes.

Migration additive : `2026_10_15_090000_add_native_post_visit_workflow.php`.
Appliquée uniquement sur `techcalendar_v2_simulation` en local ; aucune migration
ni modification sur la production Coffrac. Vérification navigateur en lecture
sur cette copie locale et tests automatisés sans appels HTTP ni emails.

Recette : 522 tests PHP / 3 965 assertions, 92 tests JavaScript, build Vite réussi.
Le parcours complet a aussi été vérifié sur MySQL local : visite, contrôle RT,
validation du rapport, satisfaction et passage du lot à facturer. Les données
temporaires de cette recette ont été annulées transactionnellement et son PDF retiré.
Les paragraphes d'audit ci-dessous conservent l'état constaté le 14 octobre.

## Audit de préparation à la démo, 14 octobre 2026

**La reprise fonctionnelle de Coffrac n'est pas complète.** Les paragraphes
historiques ci-dessous décrivent des incréments successifs, pas tous l'état actuel.
Le dump et les référentiels alimentent désormais une base de simulation isolée.
Les catégories, documents et événements archivés ne constituent pas à eux seuls
un moteur de contrôle, de rapport ou de facturation opérationnel.

### Parcours vérifiés et corrections

- Dossier en prise de RDV : action directe **Planifier le RDV**, réutilisant
  l'analyse technicien, les trajets et les propositions existantes.
- Placement physique d'une ligne de lot : création native du dossier et de sa
  prestation, liaison au RDV et état **RDV ATTENTE VISITE**, dans une transaction.
  Aucun appel à Coffrac. Identités bénéficiaire/site/installateur séparées, nom
  du lot et référence interne conservés. Le délégataire est rapproché uniquement
  si son nom stocké dans le lot correspond exactement à un référentiel unique.
- RDV manuel : création d'un dossier natif ; replacement : réutilisation du
  dossier et de la prestation, sans deuxième RDV. Un RDV Coffrac historique sans
  liaison native est refusé à la replanification, plutôt que recopié silencieusement.
- Contrôles serveur : source de placement unique, dossier/lot encore disponible,
  contrôle concurrent du créneau du technicien et refus d'écraser un état avancé
  de contrôle ou de facturation. Les lots archivés et lignes exclues/non placées
  ne peuvent pas être placés via une requête directe.
- Problème RDV : commentaire obligatoire, type et rappel conservés dans l'audit.
  Une fiche en problème sans RDV peut revenir en prise de RDV avec motif et
  contrôle de version ; une fiche déjà liée utilise **Replacer le RDV**.
- Commentaires : lecture native dans le calendrier et la replanification,
  enregistrement des nouvelles remarques du RDV dans l'historique du dossier.
  Effacer le commentaire courant n'efface pas l'historique. Ordre chronologique
  sur les dates d'événement, y compris pour les événements importés.
- Documents : les modals du calendrier utilisent les fichiers du dossier et des
  lots liés, par liens authentifiés, sans recopie ni ancien lien public Coffrac.
  Chargement groupé pour éviter une requête par RDV. Les fichiers historiques
  absents restent signalés comme non collectés ; leur collecte est encore en cours.
- Recherche dossier étendue à l'adresse, au téléphone et au code postal.
- Nettoyage V2 : plus de planification automatique des synchronisations Coffrac,
  plus de panneau de log distant dans le dashboard, plus de bouton de refresh
  Coffrac pour un RDV placé. Les anciens jobs d'upload documentaire Coffrac
  deviennent sans effet externe. Global+ conserve son circuit distinct.

### Écarts bloquants avant remplacement complet de Coffrac

| Domaine | État réel et suite nécessaire |
| --- | --- |
| Précontrôle et contrôle terrain | Les questionnaires/fiches métier de `FicheController` et leurs validations ne sont pas encore portés. Ne pas remplacer ces traitements par un menu libre de statuts. |
| Qualité, non-conformités, recontrôle, litiges | Catégories et historique repris ; décisions, transitions, responsabilités et effets métier à réimplémenter et recetter. |
| Rapports Genius | Les sources sont archivées ; génération, signature et gestion des révisions natives non finalisées. |
| Facturation/paiement | Statut historique visible, mais pas de workflow natif de facturation ou d'encaissement équivalent à Coffrac. |
| Accès technicien, agence, partenaire | Le registre et ses pièces privées restent réservés admin/gérant/planning. Ne pas ouvrir globalement les documents internes pour contourner l'absence du périmètre mobile. Les endpoints mobiles d'ajout/refresh documentaire restent à adapter au stockage natif. |
| Modifications des coordonnées | Le formulaire du planning modifie les informations de la visite ; la fiche dossier modifie les données de référence. La propagation bidirectionnelle vers les autres visites et lignes de lot n'est pas encore définie. Ne pas annoncer une synchronisation automatique de tous ces champs. |
| Prestation d'une visite | Le changement est refusé si la fiche a d'autres visites, un rapport CAPCEE ou un état de contrôle avancé, afin de ne pas réécrire leur prestation. Le parcours métier d'ajout d'une nouvelle fiche de contrôle reste à finaliser. |
| Global+ | Connecteur conservé, testé avec réponses simulées. Recette réelle création/affectation/documents/mise à jour encore nécessaire pour la V2. Aucun envoi distant pendant cet audit. |
| CAPCEE | Envoi de rapports existants implémenté, mais credentials et recette UAT manquants. Ce module ne génère pas les rapports de contrôle. |
| Documents historiques | Attendre la fin de copie, vérifier les correspondances/fichiers manquants et importer dans le stockage privé avant de promettre la consultation de toutes les pièces. |

### Recette de démo

Les tests `V2DemoWorkflowTest` couvrent les nouveaux parcours, rollback, état
avancé protégé, absence de doublon, source ambiguë, droits sur les pièces,
commentaires et abandon des anciens jobs Coffrac. Les suites V1 restent actives.
Résultat final : **511 tests PHP (3 769 assertions), 92 tests JavaScript, build
Vite et contrôles de syntaxe réussis**. Les tests métier utilisent SQLite isolé ;
la concurrence réelle multi-workers MySQL reste à recetter sur une préproduction.
Contrôle navigateur local desktop : dossiers, lots, prise de RDV, calendrier et
dashboard ; recherche AJAX, accès direct à Planifier/Reprendre et passage
liste -> détail -> modification. La fiche dossier a aussi été vérifiée sur mobile.
Pas d'erreur JavaScript ni de débordement horizontal de page constaté sur ce périmètre.

La démo doit rester sur une base et un stockage séparés de la V1, sans workers
ou identifiants de production partagés. Les actions d'envoi réelles Global+ et
CAPCEE sont hors recette locale ; garder les emails bloqués en simulation.
Aucune migration de base, aucun déploiement, aucune modification de Coffrac et
aucun envoi vers les CRM n'ont été effectués pendant cet audit.

## Objectif et état du chantier

Une application Laravel, une base métier centrale, un dossier de référence et un
seul planning. Coffrac devient un périmètre métier interne, pas un second CRM à
synchroniser. Global+ reste un système externe, avec ses jobs et ses identifiants.
CAPCEE / Salesforce est un second connecteur externe, dédié aux rapports
d'inspection, distinct des créations de dossiers et affectations Global+.

Développement sur la branche `v2` de TechCalendar, depuis `fd00af86`.
Référence Coffrac : branche `api`, commit `3b0ae3d`. Aucun changement dans Coffrac,
aucune migration des données existantes, aucun déploiement effectué dans cette étape.

Le registre local permet la création et la modification de dossiers, plusieurs
prestations par dossier, la recherche paginée, les commentaires, l'historique et
les documents privés. Un second incrément relie explicitement les rendez-vous
existants à ces prestations et expose leurs lots et pièces locales sans duplication.
Il ne remplace PAS encore les circuits de lots, de contrôle ou de facturation.
Les dossiers historiques ne sont pas importés dans ce registre. Le bandeau de
l'interface l'indique explicitement.

## Navigation harmonisée

Le menu est commun aux pages existantes et au registre V2, avec des entrées selon
les droits du compte. Admin, Planning technicien, Gérant et Planning se replient
indépendamment. Gérant contient aussi le groupe Gestion des données (utilisateurs,
délégataires, mails et prestations). Les états sont mémorisés par compte dans le
navigateur et partagés entre les variantes desktop et mobile.

Les entrées métier deviennent Les lots et Les RDV. Modifier un RDV est retiré du
menu mais ses routes restent disponibles, en attendant son intégration dans Les RDV.
Les dossiers apparaît dans Gérant et Planning et ouvre le même registre `/v2/dossiers`.
L'ancien groupe isolé Espace dossiers V2 est supprimé. Ce registre est le point
d'entrée de la reprise Coffrac ; il ne crée ni second calendrier ni copie des dossiers.
Maintenance API est retirée des paramètres, sans supprimer les données ni désactiver
les anciennes routes ou synchronisations Coffrac avant la bascule métier.

## Pourquoi ne pas copier le dashboard

Coffrac utilise Laravel 10 ; TechCalendar utilise Laravel 13. Le contrôleur dossier
de Coffrac concentre plus de 4 000 lignes, dont les statuts, le planning, les
documents, les rapports, les mails et la facturation. Copier ses vues et ses
contrôleurs ajouterait une deuxième gestion des utilisateurs, des lots et des RDV.

Le code comporte également des mutations par requêtes GET, du stockage documentaire
public et des identifiants de services externes en dur. Ces mécanismes ne seront
pas reproduits. Les identifiants concernés devront être déplacés vers la
configuration sécurisée et renouvelés avant la reprise de ces services.

## Décisions de structure

- Conserver le Laravel, l'authentification, les utilisateurs, les jobs et le design
  system de TechCalendar. Pas de fusion brute des dépôts ni de seconde application.
- Distinguer dossier, prestation du dossier et rendez-vous. Un dossier Coffrac a
  plusieurs `dossier_fiches`, chacune avec son cycle de contrôle, ses rapports et
  éventuellement ses RDV. Un simple déplacement n'est pas un dossier complet.
- Réutiliser `appointments`, `services`, les lots et leurs statistiques ; ne pas
  construire un second calendrier ni un second moteur d'échantillonnage.
- Conserver les références source avec des contraintes uniques, sans réutiliser
  les identifiants numériques Coffrac comme identifiants locaux.
- Ne jamais convertir directement un `users.type` Coffrac en `users.role`
  TechCalendar : leurs significations et périmètres ne sont pas équivalents.
- Séparer bénéficiaire, lieu d'inspection, délégataire, partenaire et installateur.
  Les correspondances Excel / Global+ corrigées restent valables.
- Les actions métier seront transactionnelles et auditées. Les envois externes,
  PDF et notifications utiliseront des jobs idempotents après commit.
- Pas de double écriture durable Coffrac / V2. Le propriétaire des écritures est
  explicite à chaque étape de la bascule ; aucun job de synchronisation ne doit
  réécrire un dossier après son transfert d'autorité vers la V2.

## Inventaire fonctionnel

Cet inventaire vient des routes, modèles, contrôleurs et vues présents dans le
dépôt. Les paramètres métier réellement enregistrés en production restent à
vérifier : statuts personnalisés, tarifs, habilitations et formulaires.

| Périmètre Coffrac | Référence du code | Reprise V2 et réutilisation |
| --- | --- | --- |
| Dashboard, chiffres par mois, agence, technicien, rapports et facturation | `HomeController`, `home`, `home_entreprise` | Fusionner avec les dashboards existants, avec des filtres et droits identiques aux listes |
| Liste, recherche, dossier, données client et entreprise | `DossierController::dossierAjaxNew`, `dossierShow`, `editDossierPost` | Registre et fiche dédiée ; socle réalisé, reprise des champs historiques à compléter |
| Création multi-fiches et lots | `dossierAddPost`, `dossierImport`, `csvImport`, `lotDossier` | Réutiliser l'import Excel et les lots TechCalendar ; mapper chaque fiche sans fusion aveugle |
| Prise de RDV, modification, agenda, trajets et RDV externes | `setDossierFicheRDV`, `takeDossierFicheRDV`, `agendaEditRDV`, `calendarAjax` | Garder le moteur de planification existant ; remplacer les appels Coffrac par des services locaux |
| Statuts, problèmes, sous-types et rappels | `statutChange`, `dossierUpdateStatus` | Workflow par prestation, commentaire obligatoire et rappel conditionnel ; préserver les valeurs réelles |
| Agences, administrateurs, auditeurs et techniciens | `dossierUpdateAgence`, `dossierUpdateAdmin`, `dossierUpdateAuditeur`, `technicienChange` | Affectations explicites et habilitations, sans assimiler tous ces comptes à des techniciens |
| Commentaires et historique | `DossierComments`, `DossierHistoriques` | Fil chronologique local réalisé pour les nouvelles données ; import et visibilité privée à mapper |
| Documents, pièces privées et accessibles aux délégataires | `DossierDocuments`, `FicheDocument`, `Media` | Stockage privé local réalisé ; conserver les ACL historiques avant ouverture aux partenaires et au mobile |
| Précontrôle, contrôle, questionnaires et conclusion | `FicheController::preControlPost`, `ControlPost`, `editRapportDetails` | Module de contrôle versionné, avec réponses et formulaires historiques préservés |
| Rapports PDF, médias, QR et horodatage | `rapportPDF`, `validationPopUpPost`, `fiches/rapport`, `dossier/pdf_rapport` | Jobs de génération, conservation des rapports existants et audit des services externes |
| Validation, litiges, clôture, V2 de rapport | `validationPopUpPost`, `letigePopUpPost`, `cloturerLetige`, `demandeVe2Post` | Workflow qualité indépendant du statut du rendez-vous |
| Facturation, remise, montant, paiement, actions correctives | `DossierFicheFacture`, `generateFacturationPost`, `payeFacturePost`, `facturerDossier` | Module dédié, montants décimaux et contrôles comptables ; ne pas confondre avec le statut d'un lot |
| SAV, Corse, DOM-TOM, avis et surfaces | `SAVRemove`, `CorseRemove`, `DomTomRemove`, `setAvisPost` | Attributs métier explicites et filtres, repris sans les perdre dans un statut générique |
| Employés, agences, propriétaires de compte, archivage | `EmployeController`, `User`, `UserType` | Étendre les utilisateurs et permissions existants ; rapprochement explicite, notamment email technicien |
| Partenaires, délégataires et entreprises | `configPartenairePost`, `Entreprise`, `Client`, relations `User` | Référentiels communs et espaces à accès limité, séparation des identités juridiques et de connexion |
| Types de fiches, tarifs, statuts et permissions | `ConfigController`, `FicheType`, `Status`, `UserType` | Réutiliser les prestations/alias, ajouter les capacités de contrôle et habilitations manquantes |
| Templates mails/SMS, notifications de statut et confirmation RDV | `ConfigController`, `sendSMS`, `sendMail`, `statusChanged` | Réutiliser expéditeurs/templates/mails/push TechCalendar ; adapter SMS et règles de destinataires |
| Export Excel et contrats PDF | `exportData`, `contratPDF` | Exports filtrés selon droits, jobs pour les gros volumes |
| Mobile et documents terrain | API mobile TechCalendar | Maintenir les contrats API, le mode hors ligne et les accès aux documents pendant la bascule |
| Global+ | Services et jobs Global+ TechCalendar | Préserver créations, affectations en cascade, mises à jour et documents ; changer uniquement la source métier interne |
| CAPCEE / Salesforce | Documentation « Intégration Genius Contrôle », version 1.0 du 12/10/2026 | Dépôt des rapports validés et de leurs versions, recherche EMMY, rattachement à une opération ou action existante ; connecteur sortant, pas accès entrant à notre base |

## Premier incrément livré

- Tables `dossiers`, `dossier_operations`, `dossier_activities`, `dossier_documents`.
- Relation vers le planning préparée, étendue au second incrément pour conserver
  plusieurs rendez-vous par prestation, sans création automatique de RDV.
- Référence dossier unique en base, création transactionnelle et refus des
  doubles soumissions ; aucun envoi API implicite.
- Mise à jour protégée par une version et un verrou transactionnel ; une fiche
  ouverte avant une modification concurrente ne peut pas écraser celle-ci.
- Recherche bornée et paginée, filtres délégataire/prestation, relations chargées
  en avance, pagination indépendante des documents et de l'historique.
- Documents limités à 10 Mo, formats autorisés et contrôle MIME/extension,
  noms de stockage aléatoires, téléchargement authentifié, nettoyage si l'écriture
  en base échoue. Toutes les pièces du socle sont internes, sans accès partenaire.
- Accès réservé aux gérants, planificateurs et administrateurs avec mot de passe initial
  changé. Les techniciens non administrateurs et futurs comptes partenaires n'ont
  pas encore accès à ce registre ; leurs périmètres doivent être définis avant ouverture.
- Interface avec navigation réduite et fiche pleine page : synthèse, prestations,
  documents, historique. Les détails rapides existants ne sont pas supprimés.

## Second incrément : raccordement au planning et aux lots

- Une prestation de dossier peut avoir plusieurs RDV. La migration
  `2026_10_14_150000_link_appointments_to_dossier_operations.php` remplace l'ancien
  lien unique par `appointments.dossier_operation_id` et conserve les liens déjà
  renseignés. Le retour arrière refuse une conversion qui perdrait des liens.
- Recherche paginée des RDV existants de la même prestation, par bénéficiaire,
  adresse, référence, identifiant local, email du technicien ou date. Aucun
  résultat sans critère et aucun rattachement automatique par ressemblance de nom.
- Rattachement explicite, idempotent, verrouillé et audité. Un RDV ne peut pas
  appartenir à deux prestations de dossier. Une version périmée est refusée.
  Le rattachement ne change ni les dates, ni les données, ni le statut du RDV ;
  il ne déclenche aucun mail, job ou appel CRM.
- La fiche expose les RDV, leurs techniciens, les lots associés et les documents
  locaux de leurs lignes. Les fichiers ne sont ni copiés ni déplacés. Leur
  téléchargement vérifie les droits, le dossier et le chemin de stockage privé.
- Les RDV retirés du planning restent visibles dans l'historique du dossier.
  Retirer une liaison ne supprime jamais le RDV, son lot ou ses documents.
- Un lien ouvre le RDV dans le calendrier existant. Réciproquement, son modal
  affiche « Ouvrir le dossier complet » uniquement aux utilisateurs habilités.
  La liste des dossiers filtre les RDV à venir, en problème ou sans RDV actif lié.
- Chaque liste de la fiche (RDV, pièces du lot, pièces du dossier, historique)
  possède une pagination indépendante. La présentation est vérifiée sur ordinateur
  et mobile avec des données fictives, sans connexion à Coffrac ou Global+.

### Activation et limites de cet incrément

Les migrations et les droits sont testés sur SQLite isolé. Aucun changement de
schéma n'a été appliqué à la base de travail ou de production. Avant activation
sur un environnement partagé, répéter les migrations sur une copie MySQL sauvegardée
et prévoir l'arrêt des écritures pendant le changement de relation. Le module
reste désactivé par défaut (`CRM_V2_ENABLED=false`).

Le raccordement concerne uniquement les RDV déjà présents dans TechCalendar.
Les lignes de lots téléphoniques ou sans RDV n'ont pas encore de lien natif au
registre. Les documents externes Coffrac ne sont pas rapatriés par cette étape.
Les données d'identité d'un dossier ne sont pas encore propagées à ses RDV.
Les synchronisations Coffrac restent inchangées : aucune bascule d'autorité
métier n'a eu lieu. Un créneau passé ne vaut jamais preuve d'inspection terminée.

## Suite de réalisation

1. Reconstituer et valider le schéma source ainsi que le catalogue des statuts,
   rôles et formulaires. Un export structure seul suffit à la première étape ;
   aucune donnée sensible n'est nécessaire pour démarrer cette analyse.
2. Construire la reprise à blanc, sans envoi de mails ou CRM, avec registre de
   correspondances, détection des conflits, totaux par table et contrôle des pièces.
3. Raccorder lots, RDV et demandes externes au dossier central. Les rapprochements
   doivent utiliser les identifiants Coffrac connus et non une ressemblance de nom.
4. Porter problèmes/rappels, affectations et documents, puis faire utiliser les
   mêmes services internes par la réservation web et l'API mobile.
5. Porter contrôle, rapports, validations, litiges et facturation avec leurs tests
   métier. Les variantes de questionnaire et anciennes versions ne sont pas jetées.
6. Reprendre permissions partenaires/délégataires/agences, configuration et
   notifications ; vérifier chaque liste, pièce jointe et export selon le rôle.
7. Recette sur copie de données, bascule avec fenêtre d'arrêt des écritures,
   désactivation des synchronisations Coffrac, contrôle des volumes et sauvegarde
   de retour arrière. Les identifiants Global+ déjà acquis sont conservés.

La structure, les référentiels et le dump des données métier ont été reçus le
14 octobre 2026 (voir les audits ci-dessous). La restauration isolée, les fichiers
et la version du code déployé restent nécessaires pour valider la reprise et la bascule.
L'API Coffrac actuelle ne donne qu'une partie des dossiers et ne constitue pas
un substitut à l'historique complet.

## Audit de la structure Coffrac reçue le 14 octobre 2026

### Provenance et limites

Fichier `coffrac-structure.sql`, généré à 12:31:38 UTC depuis la connexion Laravel
à `fiche_crm` : 26 tables, 19 478 octets, aucune ligne métier. SHA-256 :
`8f0b28ab41af23e2116cc37263bdf9501382b7662f476d85f62fd0839d06a60b`.
Le SQL fourni est analysé comme texte, pas exécuté. Il reste hors du dépôt.
L'export `SHOW CREATE TABLE` ne couvre pas les vues, triggers, routines ou événements.
Il ne constitue donc ni une sauvegarde complète ni une preuve de cohérence des données.

Les sorties Apache fournies associent `cofrac.geniuscontrole.com` et
`preprod.geniuscontrole.com` au même dossier `coffrac_preprod/public`. La production
HTTPS utilise PHP-FPM 8.3, alors que `php` en console utilise 8.4. Le dossier de
préproduction n'est pas une isolation fiable. Le runtime CLI déclare `local` ;
ne pas changer l'environnement ou les caches pendant la collecte. Le déploiement
n'a pas de répertoire Git : le commit local `3b0ae3d` ne prouve pas la version
du code en production. Prévoir une copie sécurisée ou des empreintes ciblées.

### Correspondances confirmées et à préparer

| Source | Reprise V2 | Précaution |
| --- | --- | --- |
| `dossiers` | Dossier central et correspondance source/id | Conserver `dossier_unique`, `numero_lot`, affectations, coordonnées, données chantier et métadonnées ; ne pas charger cette table sur `dossiers` TechCalendar |
| `dossier_fiches` | Opération du dossier, contrôle et rapport | Unité du statut métier ; conserver conclusion, sous-statut, problème, rappel, SAV/Corse/DOM-TOM, dépôt et date de changement |
| `fiche_type` | Prestation/alias et version du formulaire | `rapport` contient le modèle de questionnaire ; ne pas fusionner les formulaires parce que leurs prestations sont synonymes |
| `dossier_fiche_rdv` | Rendez-vous existants et historique des déplacements par opération | Pas de contrainte unique sur `dossier_fiche` ; plusieurs lignes sont possibles, à mesurer sans les supprimer |
| `rdv_extern`, `planings` | Autres occupations du planning | Conserver provenance et identifiants, sans créer de faux dossiers ni doubler les occupations déjà synchronisées |
| `dossier_document` | Documents privés et métadonnées de rapport | Préserver `is_private`, `is_delegataire`, auteur, statut, référence de contrôle, dates de RDV/émission et chemin source |
| `dossier_comment` | Commentaires avec auteur et visibilité | Ne pas exposer les commentaires privés aux nouveaux rôles par défaut |
| `dossier_historique` | Historique dossier/opération/statut | Préserver horodatage original et auteur, même si le compte n'est plus actif |
| `media`, `fiche_document` | Médias du contrôle et pièces du catalogue | `media.fiche_id` référence `dossier_fiches.id`, tandis que `fiche_document.fiche_id` désigne le type de fiche dans le code |
| `entreprise` | Installateurs | SIREN en texte libre, sans unicité : vérifier format et conflits avant rapprochement |
| `users`, `user_type`, `status` | Identités, rôles, périmètres et workflow | Rapprocher explicitement les identités ; ne pas convertir les identifiants de rôles/statuts en enums locaux sans table de correspondance |
| `lot_dossier` | Lots historiques rapprochés des lots existants | `numero_lot` est indépendant de `lot_id` ; l'API crée volontairement des dossiers avec `lot_id = null` |
| `dossier_fiche_facturation`, `dossier_letige` | Facturation et qualité | Montants en texte ; conserver la valeur source, valider la conversion décimale et ne pas convertir les valeurs invalides en zéro |
| `mail_templates`, `sms_tempalte` | Templates/notifications existants | Préserver encodage et variables ; pas d'envoi ni réexécution des notifications pendant l'import |
| `clients` | Source historique à inventorier | Ne pas déduire de son nom que `dossiers.client_id` pointe vers elle : le modèle Coffrac le relie à `users` |
| `migrations`, `failed_jobs`, `personal_access_tokens`, `password_resets`, `password_reset_tokens` | Pas de réinjection opérationnelle | Migrations locales distinctes, aucun rejeu de jobs, aucune reprise de tokens de session/réinitialisation/API |

### Pièges à traiter avant l'import

- **Conclusion non booléenne.** Le code Coffrac local et `global.rapport_avis`
  associent 0 à satisfaisant, 1 à non satisfaisant, 2 à inaccessible/non vérifiable.
  Un cast booléen inverse les conclusions ; `null` n'est ni satisfaisant ni
  insatisfaisant. La collecte de production confirme ces valeurs, mais le champ
  `is_favorable` est vide sur toutes les opérations : voir l'avis au niveau dossier
  dans l'audit des référentiels ci-dessous.
- **Identités distinctes.** `client_id` et `delegataire_id` pointent vers `users`
  dans le code, `entreprise_id` vers `entreprise`. Le nom du bénéficiaire ne doit
  pas être déduit du compte client. L'API TechCalendar utilise aussi les colonnes
  `nom_demandeur`/`adresse_demandeur` pour le bénéficiaire : cette sémantique est
  à valider pour les anciens dossiers manuels avant normalisation générale.
- **Deux statuts.** `dossiers.status_id` et `dossier_fiches.status_id` n'ont pas
  le même rôle ; le code masque certains dossiers par `dossiers.status_id = -1`.
  Le statut courant d'une opération ne se déduit pas du seul dossier parent.
- **Rapports historiques.** `dossier_fiches.rapport` et `rapport_extra` sont des
  `longtext`, pas des colonnes JSON validées. Le contrôleur décode/écrit le rapport
  comme JSON. Préserver l'original et auditer la validité sur copie isolée avant
  conversion ; ne pas remplacer les anciennes réponses par le formulaire actuel.
  Aucune table de révisions dédiée n'est présente dans cet export.
- **Cardinalité des RDV.** Le code local choisit souvent le RDV avec le plus grand
  id, mais la base autorise plusieurs RDV par opération. Le lien unique du socle
  `dossier_operations.appointment_id` est remplacé au second incrément par
  `appointments.dossier_operation_id`. La reprise historique doit encore rapprocher
  les RDV déjà présents dans TechCalendar via leurs références externes, sans créer
  une copie par dossier ni supprimer les anciens déplacements.
- **Durées.** L'API locale lit `rdv_duree` comme des heures et convertit en minutes,
  alors que la colonne source est un entier. Ne pas interpréter 1 comme une minute
  et ne pas inventer les fractions éventuellement perdues lors des anciennes écritures.
- **Contraintes et longueurs.** Références source jusqu'à 256 caractères sans
  unicité, adresses/villes nullables, plusieurs liens sans clé étrangère. Le socle
  V2 impose référence unique de 100 caractères, ville/adresse obligatoires et
  des noms plus courts. Prévoir contrôle de doublons, données à qualifier et
  conservation de la valeur source ; aucun tronquage ni rejet silencieux.
- **Confidentialité des pièces.** Les deux indicateurs privé/délégataire peuvent
  coexister. La relation Coffrac applique un filtre particulier aux délégataires ;
  l'import reste fermé tant que les règles de visibilité ne sont pas validées.
  Le code utilise notamment `public/documents`, `public/media`, `public/pdf` et
  `public/lot_dossier` : le dump SQL ne contient pas ces fichiers.
- **Encodages.** Mélange de latin1, utf8mb3, utf8mb4 et utf32, ainsi que de
  collations MySQL 8. Reprise avec conversion contrôlée vers utf8mb4, pas par
  remplacement textuel aveugle des déclarations SQL. `clients` est en MyISAM :
  une transaction InnoDB ne lui fournit pas de snapshot cohérent.
- **CAPCEE.** Aucune colonne explicitement nommée EMMY dans cette structure.
  `numero_rapport`, `reference_controle` et `dossier_unique` ne sont pas des preuves
  de référence EMMY. Son emplacement et les dates de signature/inspection restent
  à confirmer ; aucun envoi CAPCEE ne doit être déduit de ces seuls champs.

### Collecte sans données nominatives

`tools/coffrac/collect-reference-audit.php` retourne une fonction acceptant la
connexion PDO de Coffrac. Elle exécute seulement des `SELECT` fixes, sans modèles,
événements, appels API, modification de configuration ou écriture en base.
Chaque requête porte une limite MySQL `MAX_EXECUTION_TIME(10000)` ; les contrôles
sont séquentiels, sans transaction longue ni verrouillage explicite des tables.
Les résultats sont une photographie approximative d'une base qui continue à vivre,
pas un snapshot cohérent utilisable pour une migration.

La collecte inclut les statuts, permissions, catalogue de fiches sans contenu des
formulaires, migrations appliquées, multiplicités et compteurs d'anomalies. Les
emails sont utilisés seulement pour compter les doublons normalisés, jamais
exportés. Aucun mot de passe, token, réponse de rapport, commentaire, nom client
ou chemin de document n'est exporté. La configuration autorisée est limitée à
`rapport_avis`, `user_type_column_by_status` et `user_type_btns_by_status`.

Une requête impossible est marquée `error` avec son seul SQLSTATE ; elle ne devient
pas un faux compteur à zéro. `complete = false` signale un résultat partiel.
Tests locaux sur SQLite avec fixtures synthétiques ; les 22 requêtes ont également
abouti sur la base Coffrac selon l'export reçu. Cela ne teste pas le déclenchement
effectif du timeout MySQL en cas de requête trop longue.
L'outil ne se lance pas automatiquement et ne fait pas partie des routes de l'app.

Après collecte : examiner les vrais référentiels et les collisions, puis préparer
une copie isolée des données, une copie contrôlée des documents/rapports et une
reprise à blanc. Aucune migration métier ni import de production n'a été exécuté.

## Audit des référentiels reçus le 14 octobre 2026

### Source et périmètre

Fichier `coffrac-references.json`, format 1, généré à 12:56:51 UTC :
`complete = true`, 22 sections sans erreur. SHA-256 :
`005c410895cb841949ba2624e96f8bbbac1eac6a769702fc9a2c10e8f4020d20`.
Le JSON reste hors du dépôt ; seuls les constats nécessaires sont reportés ici.
Les compteurs ci-dessous proviennent des agrégats exacts, pas de `TABLE_ROWS`,
qui est une estimation InnoDB. La collecte est séquentielle et ne constitue pas
un snapshot transactionnel ; ces volumes sont datés, pas des objectifs figés.

Les trois clés de configuration collectées sont identiques à celles du fichier
`config/global.php` du dépôt Coffrac local au commit `3b0ae3d`. Cela ne confirme
ni la version de tout le code déployé, ni l'application des permissions côté serveur.

### Volumes et qualité

| Ensemble | Volume | Conséquence pour la reprise |
| --- | ---: | --- |
| Dossiers | 17 348 | 639 sans fiche et 7 avec plusieurs fiches ; conserver les parents et toutes leurs opérations |
| Opérations / fiches | 16 717 | Unité de statut et de contrôle, distincte du dossier |
| Lignes de RDV des fiches | 16 423 | 1 162 opérations ont plusieurs RDV ; ne pas assimiler ces lignes à autant de RDV actifs |
| RDV externes | 3 755 | Rapprocher aussi les occupations hors dossier |
| Métadonnées de documents | 103 452 | Les fichiers physiques et leur intégrité restent à vérifier |
| Commentaires | 64 449 | Dont 22 013 privés ; préserver auteur, date et visibilité |
| Historique | 128 547 | Reprise par lots idempotents, sans rejouer les actions ni notifications |
| Comptes | 284 | Dont 60 de type technicien ; ne pas considérer tous les comptes comme des techniciens |
| Installateurs | 1 731 | Audit SIREN et rapprochement encore nécessaires |
| Types de fiches | 57 | 55 actifs, 2 inactifs ; préserver les types historiques |

- **Références :** 21 groupes de références dossier dupliquées. Le nombre de
  dossiers excédentaires n'est pas fourni : ne pas annoncer « 21 doublons » ni les
  supprimer. Utiliser l'identifiant source comme clé de reprise, pas `dossier_unique`.
- **Données incomplètes :** 219 dossiers sans adresse, 146 sans ville, 600 lignes
  de RDV sans date ou heure complète. Les ensembles peuvent se recouper. Préserver
  ces entrées à qualifier, sans inventer d'adresse, d'horaire ni appeler Mapbox
  automatiquement pendant la reprise historique.
- **Longueurs :** ville jusqu'à 145 caractères, code postal jusqu'à 56 caractères.
  Garder les valeurs brutes et signaler les formats invalides ; pas de troncature.
- **Liens :** aucun parent, type de fiche ou statut manquant pour les opérations
  contrôlées ; un dossier référence un technicien inexistant. Aucun auteur de
  document manquant selon ce contrôle. Cela n'est pas un audit de toutes les relations.
- **Identités :** aucun doublon d'email normalisé dans Coffrac. Le rapprochement
  avec les comptes TechCalendar reste à faire ; les identifiants numériques ne
  sont pas interchangeables entre les bases.
- **Durées :** valeurs 1, 2, 3, 4 et 8, cohérentes avec les heures utilisées par
  le code local. Convertir explicitement en minutes sans modifier la valeur source.
- **Catalogue :** 11 fiches ont un modèle dans `fiche_type.rapport`, 46 n'en ont
  pas. Cela ne prouve pas l'absence de rapports ou PDF historiques. Le champ
  `price` contient 19 valeurs numériques, 4 vides et 34 textes non numériques :
  ne pas convertir ces textes en montants à zéro.

### Statuts métier constatés

Les volumes portent sur `dossier_fiches.status_id`, pas sur `dossiers.status_id`.
Conserver les identifiants source dans une correspondance dédiée et les libellés
bruts ; la normalisation d'affichage ne doit pas modifier la signification métier.

| ID source | Libellé Coffrac | Opérations | Actif |
| --- | --- | ---: | --- |
| 1 | PRISE DE RDV | 8 | Oui |
| 10 | Problème rendez- vous | 608 | Oui |
| 2 | RDV ATTENTE VISITE | 374 | Oui |
| 9 | RDV VISITE | 100 | Oui |
| 3 | CONTROLE RT | 27 | Oui |
| 8 | NON CONFORMITES | 364 | Oui |
| 12 | RECONTROLE | 4 | Oui |
| 4 | RAPPORT GENIUS | 1 166 | Oui |
| 11 | DOSSIERS FACTURER | 12 873 | Oui |
| 6 | DOSSIER ANNULE | 1 193 | Oui |
| 5 | DOSSIER LITIGE | 0 | Non |
| 7 | AUDITS | 0 | Non |

La table `dossier_fiche_facturation` est vide : le statut 11 ne prouve ni l'existence
d'une facture ni son paiement. Ne pas en déduire une écriture financière.
Un dossier parent a `status_id = -1` : préserver cet état masqué séparément.
Les statuts 2 et 8 ont `has_mail = 1` ; la reprise ne déclenche pas ces emails.

### Satisfaction : deux sources à ne pas confondre

Les 16 717 opérations ont `is_favorable = null`. La configuration confirme
0 = satisfaisant, 1 = non satisfaisant, 2 = inaccessible/non vérifiable, mais
aucun de ces codes n'est présent dans le champ collecté.

Le code Coffrac local apporte une autre piste :

- `DossierController::setAvisPost()` écrit `dossiers.avis_satisfaction` et
  `avis_surface` ; l'écran détail propose les valeurs textuelles `SATISFAISANT`
  et `NON SATISFAISANT`.
- L'affichage des listes lit cet avis du dossier, l'ancien affichage de
  `is_favorable` y étant commenté.
- `TechCalendarApiController` exporte en revanche la conclusion de la fiche
  via `is_favorable` et `satisfactionPayload()`.

C'est une divergence constatée dans le code local, compatible avec des conclusions
absentes via l'API. L'export ne contient pas la distribution d'`avis_satisfaction` :
il ne permet ni d'affirmer que toutes les conclusions sont perdues, ni de confirmer
la population de cette autre colonne. Vérifier sur le dump ou par agrégat ciblé.
Conserver séparément avis du dossier, conclusion de l'opération et conclusion du
rapport signé. Ne pas recopier automatiquement l'avis parent sur plusieurs fiches,
et ne pas envoyer de conclusion CAPCEE sans correspondance validée.

### Droits et confidentialité

| Type source | Libellé | Comptes | Précaution |
| --- | --- | ---: | --- |
| 1 | SUPER ADMIN | 34 | Pas d'attribution automatique du rôle super-admin TechCalendar |
| 2 | REFERENT TECHNIQUE | 2 | Type inactif mais comptes actifs |
| 3 | AGENCE | 1 | Type inactif mais compte actif |
| 4 | TECHNICIEN TERRAIN | 60 | 48 actifs non archivés, 11 inactifs, 1 actif archivé |
| 5 | ENTREPRISE/CLIENT/INSTALLATEUR/PARTENAIRE | 176 | Rôle composite : périmètres client et installateur à distinguer |
| 6 | AUDITEURS | 1 | Type inactif mais compte actif |
| 7 | DELEGATAIRE | 10 | 9 actifs, 1 inactif ; isolation par délégataire à conserver |

Les listes de colonnes et boutons du front ne constituent pas des autorisations
serveur. Les policies V2 doivent préserver les périmètres et rester restrictives
tant que la correspondance n'est pas validée. Ne pas réactiver un compte ou élargir
son accès lors de son rapprochement par email.

Pour les documents : 70 039 ont les deux flags à zéro, 32 139 sont privés seuls,
1 238 sont délégataire seuls, et 36 ont les deux flags actifs. Conserver les deux
informations, sans les réduire à un booléen « public ». L'accès V2 à ces derniers
reste interne jusqu'à validation de la règle de visibilité.

### Suite de la reprise

Les référentiels et les volumes sont maintenant disponibles. La prochaine étape
est une restauration isolée du dump complet avec une copie des fichiers et le
code correspondant, pas une nouvelle synchronisation API. Elle permettra de
qualifier les avis, formulaires, doublons, identités et chemins réels puis de
préparer les correspondances et l'import idempotent sans perte d'historique.
La présence de `2026_09_17_150000_backfill_techcalendar_lot_names` dans le dépôt
local, mais pas dans les cinq migrations exportées, devra aussi être rapprochée
de la version réellement déployée ; ne pas lancer cette migration en production
sur la seule base de ce constat.

Aucun import, aucune modification du workflow actif et aucune bascule de droits
ne sont effectués à partir de cet audit.

## Réception et lecture du dump complet

Le dump `coffrac-migration-20261014/coffrac.sql.gz` a été téléchargé et vérifié
par l'opérateur le 14 octobre 2026 : empreinte SHA-256 et intégrité gzip valides.
Il reste dans Downloads, hors dépôt et hors répertoire public. Provenance :
`/home/ubuntu/coffrac-export-W6VpfOZZ`, horodatage final du dump
`2026-10-14 13:09:50`, serveur MySQL 8.0.46.

- Taille compressée : 36 146 713 octets ; décompressée : 385 605 127 octets.
- SHA-256 : `7dedff81106a92d375b4e990f81581fdd2737c38910c75c80e8e0ac00418abcc`.
- Les 26 définitions de tables attendues sont présentes. Les tables vides n'ont
  naturellement pas d'instructions d'insertion.
- Lecture locale en flux du SQL uniquement, sans exécution de ses instructions,
  sans chargement de modèles et sans restitution des données nominatives.
  Ce premier examen ne remplace pas une restauration isolée ni un audit complet
  des objets SQL exécutables, privilèges, contraintes et encodages.

### Conclusions du premier examen des données

Les lignes lues confirment 17 348 dossiers, 16 717 fiches et 57 types de fiches.
Les statuts des opérations sont identiques à ceux de la collecte des référentiels.
Le dump contient 103 454 métadonnées de documents, contre 103 452 lors de la
collecte précédente : les deux exports ne sont pas un même instantané.

`dossiers.avis_satisfaction` contient 7 valeurs `SATISFAISANT`, 1 valeur
`NON SATISFAISANT` et 17 340 valeurs nulles. Aucun des huit dossiers avec avis
n'a plusieurs opérations dans ce dump. Les 16 717 valeurs `is_favorable` restent
nulles : l'avis dossier explique donc au plus une partie de l'absence de conclusions
via cette API. Les autres conclusions peuvent relever des documents ou des données
de rapports ; aucune valeur ne doit être déduite automatiquement d'un statut.

Pour `dossier_fiches.rapport`, après décodage des chaînes SQL du dump :
4 105 contenus passent un décodage JSON strict, 222 contenus non vides échouent
et 12 390 sont vides. Cela ne prouve ni la validité métier des JSON ni la perte
des 222 autres rapports. Préserver tous les contenus originaux et distinguer
encodage ancien, format historique et données incomplètes avant transformation.
`rapport_extra` est vide partout ; une seule fiche renseigne `date_rapport`,
aucune ne renseigne `signature`, `rapport_pdf` ou `numero_rapport`.
Ces champs ne suffisent donc pas à retrouver tous les rapports signés : utiliser
également les métadonnées de documents et les fichiers, sans recréer de faux PDF.

### Volume documentaire et copie de travail

L'inventaire serveur fourni indique 214 Go dans `public/documents`, 315 Mo dans
`public/pdf`, 284 Mo dans `public/media` et quelques Ko dans `public/signature`,
`public/lot_dossier` et `storage/app`. Le Mac dispose de 89 Gio libres : ne pas
lancer le transfert complet vers ce disque. Les cinq petits répertoires peuvent
être copiés séparément (environ 600 Mo) ; le gros répertoire nécessitera un disque
adapté ou un échantillon explicitement borné avant la copie complète.

Les 103 454 chemins de documents sont relatifs, ce qui ne prouve pas qu'ils sont
sûrs, uniques ou présents sur disque. Valider leur résolution et les liens
symboliques avant tout accès. Ne pas exposer les fichiers via les anciennes URL.
Une copie en ligne des fichiers n'est pas synchronisée avec la transaction SQL.
L'intégrité et la complétude documentaire restent à contrôler avant la bascule.

### Réception des répertoires légers et alerte de sécurité

La copie limitée s'est terminée avec un code rsync 0. L'inventaire local retrouve
1 349 fichiers et 624 739 772 octets, identiques aux totaux transmis par l'opérateur,
ainsi que 12 répertoires et aucun lien symbolique. Ce contrôle de volumes ne remplace
pas une comparaison d'empreintes avec la source ni un contrôle des fichiers actifs
pendant le transfert. `public/documents` n'a pas été téléchargé.

La copie contient 413 fichiers nommés `.pdf`, 864 `.png`, 63 `.jpg`, 7 `.htaccess`
et 2 `.gitignore`. Les en-têtes des 413 PDF et des 63 JPEG correspondent au type
annoncé. Parmi les 864 `.png`, 446 ont un en-tête PNG et 418 sont identifiés comme
JPEG par leur en-tête. Ne pas renommer les sources : conserver les chemins bruts
et déterminer le type réel lors du futur import. L'examen des en-têtes n'est pas
une analyse antivirus ni une validation complète des documents.

Trois fichiers de configuration présentent des règles suspectes, sans rapport
identifié avec les fonctionnalités métier Coffrac :

- `public/pdf/875032/.htaccess`
- `public/media/440055/.htaccess`
- `public/lot_dossier/533657/.htaccess`

Ils sont identiques, font chacun 420 octets et possèdent l'empreinte SHA-256
`b4cdad551a2258c7b743f0dda5075af1f6e9b10f88cf237bfa7c66b15cc93e5d`.
L'horodatage conservé est `2026-07-13T06:42:04Z`, sans preuve que cette date
corresponde à leur création ou à un incident. Leurs règles incluent `Allow from all`
pour `about.php`, `radio.php`, `index.php`, `content.php`, `lock360.php`,
`admin.php` et `wp-login.php`, puis une réécriture vers `/index.php`.
Les `.htaccess` parents de PDF, médias et lots contiennent au contraire des règles
de refus pour plusieurs extensions exécutables.

C'est un indice de fichiers parasites ou d'une compromission passée ou présente,
pas une preuve d'exécution ni de compromission active. L'effet réel dépend de la
configuration Apache, des modules et d'`AllowOverride`. Aucun fichier portant une
extension PHP n'a été trouvé dans cette copie limitée ; le gros répertoire de
documents et le reste du serveur n'ont pas été inspectés.

Conserver les originaux et les empreintes, avertir le mainteneur de Coffrac et
examiner la configuration effective, le code déployé et les journaux avant toute
remédiation. Ne pas visiter les URL suspectes, exécuter leurs cibles ou supprimer
des fichiers pendant la collecte. Aucun changement serveur n'a été réalisé.
Ne jamais transférer en bloc ces anciens répertoires vers le `public` de la V2 :
la reprise devra sélectionner les pièces métier, valider leur contenu et les
stocker sur un disque privé servi par des routes autorisées, sans importer les
fichiers de configuration serveur. Cette règle ne constitue pas encore un import
documentaire implémenté.

Références : [portée et activation des .htaccess Apache](https://httpd.apache.org/docs/2.4/howto/htaccess.html)
et [recommandations OWASP pour les fichiers téléversés](https://cheatsheetseries.owasp.org/cheatsheets/File_Upload_Cheat_Sheet.html).

Le contrôle complémentaire fourni par l'opérateur (`find public -type f` avec
filtres d'extensions PHP/PHTML/PHAR et `.user.ini`) relève notamment
`public/universign/css/images/108492/index.php`, en plus du contrôleur frontal
et de fichiers PHP de compatibilité XML-RPC. L'emplacement du premier, combiné
aux règles suspectes déjà collectées, justifie un examen prioritaire de son contenu.
Le répertoire `public/universign` est absent du checkout Coffrac local au commit
`3b0ae3d` : aucune comparaison de contenu avec cette version n'est possible.
Ce seul inventaire ne valide pas les autres scripts et ne prouve pas qu'un code
malveillant a été exécuté. Relever métadonnées et empreinte puis lire le fichier
comme texte, sans requête HTTP, interprétation PHP, suppression ou changement de droits.
Le contrôle ne couvre pas les scripts sous d'autres extensions, les cibles de liens
symboliques ni les fichiers situés hors du répertoire `public`.

## Activation du socle en développement ou recette

La V2 est désactivée par défaut. Le premier schéma ajoute les tables du registre ;
le second transfère les liens de planning existants vers une relation un-à-plusieurs.
Aucun appel réseau ni import Coffrac n'est exécuté par ces migrations. Les valider
sur une copie isolée avant tout déploiement ; elles ne constituent pas une bascule
du métier Coffrac.

```sh
php artisan migrate
npm run build
# Dans la configuration de l'environnement de recette : CRM_V2_ENABLED=true
php artisan config:clear
```

L'entrée Les dossiers apparaît alors dans Gérant et Planning, selon les droits du
compte, et ouvre `/v2/dossiers`. Les techniciens non administrateurs n'y ont pas accès.
La variable ne coupe ni les anciennes routes ni les synchronisations Coffrac.
Ne pas exécuter de rollback de la migration après saisie de données à conserver :
il supprimerait les nouvelles tables. Pour masquer la V2, désactiver le flag.

## Vérifications de référence

```sh
APP_ENV=testing APP_CONFIG_CACHE=/tmp/techcalendar-v2-no-config-cache.php \
DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_URL= CACHE_STORE=array \
php vendor/bin/pest tests/Feature/DossierRegistryTest.php \
    tests/Feature/DossierPlanningTest.php tests/Feature/DossierPlanningMigrationTest.php --compact
node --test tests/Frontend/*.test.mjs
```

Les tests couvrent le flag, les autorisations, les doublons, les recherches,
les conflits d'édition, l'échappement HTML, les fichiers, les rattachements multiples,
la conservation des liens lors de la migration, les paginations indépendantes et
l'absence d'effets externes. Les tests JavaScript vérifient les raccourcis du calendrier.
Les contrôles sur le schéma/volumes source, MySQL de recette et
comportements métier complets seront nécessaires avant livraison de la V2.

## Connecteur sécurisé CAPCEE

### Périmètre de la documentation reçue

La documentation du 12 octobre 2026 décrit des appels **de TechCalendar vers
CAPCEE**, sur Salesforce. Elle ne définit ni des endpoints entrants TechCalendar,
ni un accès à notre base, ni la création d'une opération CAPCEE. L'opération doit
déjà exister et être identifiée sans ambiguïté par sa référence EMMY. Une éventuelle
API entrante pour des partenaires nécessitera un contrat séparé, des identités
techniques révocables, des droits par délégataire et des quotas.

### Préparation réalisée

`App\Services\Capcee\CapceeClient` prépare les appels documentés :

1. `POST /services/oauth2/token` avec `grant_type=client_credentials`.
2. `GET /services/apexrest/getObjectByClientReference?ref=...` et contrôle d'une
   unique réponse du type attendu (`Operation__c` ou `Action__c`).
3. Recherche du dernier rapport par `ExternalReference__c` dans `ContentVersion`,
   avec vérification de la cible et refus des résultats ambigus ou incomplets.
4. `POST /services/data/v58.0/sobjects/ContentVersion`, en conservant le
   `ContentDocumentId` pour les nouvelles versions.
5. Recherche du `ContentDocumentId` par identifiant de version, puis
   `POST /services/data/v58.0/sobjects/ContentDocumentLink`.

Le connecteur est **désactivé par défaut**. La fiche dossier expose désormais une
section Rapports CAPCEE, avec un formulaire de dépôt par prestation, des révisions
et un suivi automatique. Aucun rapport réel n'a été envoyé pendant le développement.
Les routes sont authentifiées et protégées par la policy du dossier ; seuls les
gérants et administrateurs peuvent autoriser une transmission ou sa reprise.

Les deux drapeaux `CRM_V2_ENABLED` et `CAPCEE_ENABLED` sont nécessaires. Le choix
`CAPCEE_ENVIRONMENT=uat2` cible uniquement l'hôte sandbox fourni ; `production`
cible uniquement l'hôte production. Aucune URL arbitraire ni redirection HTTP
n'est acceptée. Les identifiants `CAPCEE_CLIENT_ID` et `CAPCEE_CLIENT_SECRET`
restent côté serveur, séparés par environnement et exclus du dépôt via `.env`.

Les jetons sont chiffrés en cache avec une courte durée de réutilisation ; leur
clé dépend de l'environnement et des credentials. Un verrou empêche les échanges
OAuth concurrents. Un HTTP 401 invalide le jeton et autorise un seul renouvellement.
Les logs ne contiennent que méthode, chemin sans paramètres, statut et durée,
jamais les credentials, références métier, corps du rapport ou erreurs brutes.
Un cache partagé avec support des verrous est requis entre les workers.

Le client ne rejoue pas de POST après un timeout ou un HTTP 5xx : le serveur peut
avoir reçu le document malgré l'erreur. L'exception distingue les refus définitifs,
les lectures réessayables et les écritures au résultat inconnu. Les étapes sont
maintenant persistées dans `capcee_reports` et `capcee_deliveries` : une reprise
n'effectue pas de nouveau dépôt lorsque l'identifiant de version est déjà confirmé.

### Correspondance des rapports

| Donnée métier | Champ CAPCEE | Règle |
| --- | --- | --- |
| Référence EMMY | Recherche de cible | Référence explicite de l'opération ; ne pas assimiler automatiquement au nom de lot ni à sa référence interne |
| Identifiant stable du rapport | `ExternalReference__c` | `GC_` suivi de la référence locale, identique pour les révisions ; distinct de l'identifiant du dossier ou RDV |
| Inspection physique | `TypeDocument__c`, `TypeRapportInspection__c` | `RISITE`, `ControleSurSite` |
| Inspection par contact | mêmes champs | `RINSPCON`, `ControleParContact` |
| Conclusion du rapport validé | `StatutRapportInspection__c` | `Satisfaisant`, `NonSatisfaisant`, `NonVerifiable`, `Autre` ; ne pas déduire des statistiques du lot |
| Signature / émission et inspection | `DateEmission__c`, `DateInspection__c` | Dates explicites `YYYY-MM-DD` ; la date du RDV prévu ne remplace pas celle de la visite réalisée |
| Cible résolue | `TECH_ParentId__c`, `Objet__c` | Identifiant Salesforce et `Opération`, ou `Action` pour mission groupée explicitement choisie |
| Émetteur | `Emetteur__c`, `Origine__c` | `BC`, `GENIUS Contrôle` |
| Décision d'envoi au bénéficiaire | `StatutEnvoiEmail__c` | Choix obligatoire `NePasEnvoyer` ou `AEnvoyer`, jamais un effet implicite du dépôt |
| Fichier signé | `VersionData`, `Title`, `PathOnClient` | PDF en base64 ; nom technique sans espaces, accents ou `/`, extension `.pdf` conservée |

Le premier constructeur de payload accepte les rapports PDF uniquement, plafonnés
à 35 000 000 octets bruts par prudence. La doc liste aussi d'autres pièces, qui ne
sont pas encore prises en charge par ce flux de rapport. Le base64 ajoute environ
un tiers au volume, puis le JSON et les copies mémoire ajoutent encore une charge :
les fichiers ne doivent pas être inclus dans les payloads des jobs. Un job transporte
un identifiant, lit une version immuable du fichier privé et traite un seul fichier
à la fois. Le nom technique et le titre sont bornés à 255 caractères ; une référence
EMMY longue reste complète pour la recherche, même si son affichage dans le titre
doit être tronqué.

Un contrôle conservateur du budget mémoire précède l'encodage et refuse proprement
la préparation si le processus n'a pas assez de marge. Prévoir des workers dédiés
avec une limite d'au moins 512 Mo pour les rapports proches de 35 Mo, à confirmer
par un test de charge ; les requêtes web ne doivent pas prendre ce coût.

### Workflow de transmission raccordé

Le formulaire exige le PDF validé, la référence EMMY explicite, la conclusion,
les dates réelles et une confirmation de transmission. Il ne certifie pas lui-même
la signature du PDF et ne déduit pas la validation du statut d'un dossier ou des
statistiques de lot. L'email bénéficiaire est réglé sur Ne pas envoyer par défaut.
La référence stable générée est `GC_TC-<ULID>`, réutilisée pour les révisions.
Un rapport par prestation, environnement et type de contrôle est autorisé ; les
versions suivantes passent par Nouvelle version avec un motif obligatoire.

La livraison conserve la version locale, son empreinte SHA-256, les métadonnées
et l'environnement. Son fichier est une copie privée immuable, contrôlée à nouveau
avant envoi. Un job ne transporte que son identifiant et est distribué après commit.
Les pièces internes ordinaires et les archives Coffrac ne sont pas transmises.

Chaque étape acquittée conserve son identifiant distant avant la suivante : cible,
version, document et lien. Une contrainte unique sur intégration, environnement et
version locale, complétée d'un verrou par rapport, empêche deux envois locaux
concurrents. Un rejeu reprend l'étape manquante, pas le dépôt initial. Les actions
groupées doivent faire l'objet d'une règle de rattachement explicite.

Après une interruption d'écriture, rapprocher la référence externe, la cible et la
version distante avant toute nouvelle création. Sans preuve suffisante, placer la
livraison en vérification manuelle. `ExternalReference__c` seul n'est pas présenté
comme une clé d'idempotence garantie par Salesforce. Prévoir un suivi visible
« en attente / en cours / envoyé / échec / résultat à vérifier », historique des
tentatives, reprise contrôlée et limitation de concurrence. Le quota indiqué
(100 000 appels / 24 h) est à confirmer au niveau de l'organisation Salesforce.

### Exploitation du suivi CAPCEE

Appliquer la migration `2026_10_14_220000_create_capcee_report_deliveries.php`
avant de déployer les vues correspondantes. Elle ajoute deux tables, sans reprise
automatique de documents ni modification des dossiers existants.

Configuration serveur (credentials fournis par CAPCEE, jamais dans le navigateur) :

```dotenv
CRM_V2_ENABLED=true
CAPCEE_ENABLED=true
CAPCEE_ENVIRONMENT=uat2
CAPCEE_CLIENT_ID=
CAPCEE_CLIENT_SECRET=
CAPCEE_QUEUE_CONNECTION=database
CAPCEE_QUEUE=default
DB_QUEUE_RETRY_AFTER=1800
```

Le worker existant de la queue `default` peut exécuter les jobs. Pour isoler les
gros rapports, configurer `CAPCEE_QUEUE=capcee` et un worker de cette queue :

```bash
php -d memory_limit=512M artisan queue:work database --queue=capcee --timeout=480 --tries=1 --sleep=2
```

Le délai de réservation `retry_after` doit dépasser 600 secondes (vérifié avant
mise en file). Pour SQS, régler également la visibilité côté AWS. Le verrou dure
600 secondes, le job au plus 480. Le timeout d'arrêt du gestionnaire de service
doit permettre au job de terminer. Adapter également les limites d'upload PHP
et du serveur HTTP pour accepter 35 Mo bruts.

`capcee:dispatch`, planifié chaque minute, récupère les demandes validées en
attente, les lectures réessayables et les workers interrompus. Il ne collecte
aucun dossier et ne déclenche aucun rapport sans validation explicite. Il tourne
aussi en simulation si les API sont autorisées et CAPCEE activé. Les jobs en
attente sont dédupliqués et leur traitement est verrouillé par rapport. Ne pas
activer CAPCEE production sur une copie destinée à des essais.

Le suivi se rafraîchit toutes les cinq secondes pendant un traitement, sans
recharger la fiche. Une erreur réseau du suivi n'annule pas le travail du worker.
Les historiques métier conservent les tentatives ; les logs ne contiennent ni
PDF, token, secret OAuth, ni message distant brut.

- En attente / Envoi en cours : vérifier le worker et le planificateur si cela dure.
- Envoyé et rattaché : version, document et liaison confirmés côté CAPCEE.
- Envoi interrompu : corriger le problème de configuration/droits puis utiliser
  Reprendre l'envoi ; les identifiants déjà acquis sont conservés.
- Résultat à vérifier : timeout, arrêt du worker pendant un POST ou référence
  distante inattendue. Aucun bouton de renvoi aveugle. Faire rapprocher par CAPCEE
  la référence `GC_TC-...`, les identifiants déjà confirmés et le PDF conservé.
  La réconciliation manuelle des écritures indéterminées reste à traiter avant
  reprise ; ne pas créer une autre référence pour contourner le blocage.

Les révisions, validations et choix EMMY sont explicites : le module de génération
et de signature des rapports natifs reste un chantier séparé. La recette distante
UAT2 reste obligatoire ; les tests locaux utilisent des réponses API simulées.

### Points à confirmer avant la recette CAPCEE

- La section 2.1 annonce « JWT Bearer » alors que 3.1 utilise `client_credentials`.
  L'implémentation suit le contrat détaillé 3.1 ; faire confirmer ce grant et les
  droits de l'utilisateur d'intégration avant activation. Si JWT est exigé, un
  certificat et un autre échange seront nécessaires.
- La doc écrit `Allusers` ; Salesforce utilise `AllUsers`. Le connecteur retient
  cette casse et `ShareType=V`. Faire valider le périmètre de lecture côté CAPCEE,
  particulièrement pour les portails externes. Cela n'est pas un lien public anonyme.
- Définir qui décide d'envoyer un email au bénéficiaire : la doc prévoit
  `AEnvoyer` par défaut, alors que le connecteur impose une décision explicite.
- Fournir les credentials UAT2, les droits sur les champs personnalisés et des
  références EMMY de test avec résultats attendus. Confirmer le préfixe `GC`,
  la limite 35 MB (fichier brut ou requête base64), les droits sur les versions
et la visibilité documentaire.
- Identifier dans le dump Coffrac la vraie référence EMMY, l'identifiant stable
  du rapport, sa signature, ses révisions et ses fichiers. Ne pas les déduire
  d'un simple statut de RDV ou d'une satisfaction de lot.

Références officielles vérifiées :
[OAuth client credentials Salesforce](https://developer.salesforce.com/blogs/2024/02/invoke-rest-apis-with-the-salesforce-integration-user-and-oauth-client-credentials)
et [visibilité des fichiers Salesforce](https://help.salesforce.com/s/articleView?id=000384243&language=en_US&type=1).

### Réception du dump Coffrac

La structure, les référentiels agrégés et le dump complet ont été reçus.
Le dump a fait l'objet d'une première lecture textuelle, sans restauration.
Le conserver hors du dépôt et hors du répertoire public, avec accès restreint.
Il peut contenir des données personnelles, hashes de mots de passe et secrets.
L'inspecter puis le restaurer dans une base locale isolée, jamais directement sur
la base active TechCalendar. Le dump ne contient généralement pas les fichiers :
demander également une copie des documents et rapports avec leurs chemins, ainsi
que la version du code correspondant à l'export. Aucune restauration automatique
ne doit être lancée à la réception sans contrôle de sa destination et de son contenu.

### Clients et délégataires externes (15 octobre 2026)

Un dossier et un lot ont **un seul client affecté**, classique ou délégataire.
Le bénéficiaire reste une information distincte. Le référentiel existant
`external_delegataires` est conservé pour ne pas casser les références historiques
et Global+ ; `kind` distingue les deux catégories. La gestion commune se trouve
dans **Gérant > Gestion des données > Clients et délégataires**. Les deux types
figurent dans les mêmes sélecteurs de dossiers et de lots.

Les utilisateurs internes restent dans leur écran actuel. Chaque organisation
externe peut avoir plusieurs comptes nominatifs (`users.external_client_id`, rôle
externe 3), avec activation et droits individuels. Le portail `/espace-client`
expose uniquement les dossiers et lots affectés à cette organisation. Il ne
donne aucun accès aux pages internes, API mobile technicien, canaux privés Reverb,
autres organisations, fichiers source des lots, intégrations ou changements d'état.
Les contrôles portent aussi sur les URLs directes de téléchargement.

#### Droits vérifiés dans le code Coffrac

Lecture seule du dépôt Coffrac : `DossierController` pour les affectations,
`Models/Dossier.php` pour les documents/commentaires, et `dossier/show.blade.php`
pour les sections accessibles. Aucun accès ni changement en production.

- Types Coffrac 5/6 : clients classiques. Par défaut, lecture des documents et
  commentaires partagés, ajout de documents/commentaires, consultation des lots.
- Type Coffrac 7 : délégataire. Par défaut, documents autorisés aux délégataires
  et consultation des lots ; commentaires et ajouts désactivés, activables par le gérant.
- Le marquage privé gagne toujours sur le marquage délégataire. La V2 ne reprend
  pas le comportement historique qui pouvait ignorer ce premier marquage.
- Aucune visibilité héritée des relations Coffrac propriétaire/parent : seule
  l'affectation exacte fait autorité. Aucun mot de passe Coffrac n'est réutilisé.

Les nouveaux documents et commentaires internes sont privés par défaut. Le
gérant/planning peut les partager ou retirer leur partage depuis le dossier.
Les ajouts autorisés depuis le portail sont visibles par l'organisation et
l'équipe. Les documents historiques conservent leurs indicateurs de visibilité ;
les métadonnées de confidentialité absentes sont traitées comme privées.
Les documents rattachés indirectement aux lots restent internes : leur partage
externe n'est pas déduit de l'affectation du lot ou du réglage Global+.

La désactivation d'un compte ou de son organisation bloque dès la requête
suivante une session déjà ouverte. Un mot de passe initial/réinitialisé doit être
changé avant consultation des données. Aucun email d'identifiants n'est envoyé
automatiquement. Changer le type d'organisation conserve les droits explicites
de ses comptes, à revoir manuellement si nécessaire.

#### Migration et rapprochement

Après sauvegarde, sur la **base V2 visée uniquement** :

```bash
php artisan migrate --force
php artisan clients:reconcile-legacy
# Après contrôle du bilan uniquement :
php artisan clients:reconcile-legacy --apply
```

Cette commande exploite `legacy_records` localement et n'appelle jamais Coffrac.
Elle ajoute les organisations absentes et ne complète que les dossiers sans
affectation. Elle préserve toutes les affectations existantes. Pour un ancien
dossier avec deux attributions, le délégataire historique est prioritaire,
sinon le client direct ; le bilan compte ces doubles attributions. Aucun compte
de connexion n'est créé ou activé. L'import de simulation réutilise ce même
rapprochement, pour rester reproductible après réinitialisation.

Les lots et prévisualisations conservent désormais l'identifiant du client,
pas seulement son nom. La migration reprend uniquement les correspondances de
noms exactes et non ambiguës. Le placement physique reprend cet identifiant et
un changement de client du lot réaffecte ses dossiers natifs, avec historique,
afin de retirer l'accès à l'ancienne organisation.

Contrôle de la copie locale le 15 octobre : 177 organisations ajoutées,
4 480 dossiers rattachés, 12 842 affectations conservées, 26 dossiers sans
correspondance laissés sans accès externe. Aucune production modifiée.

Avant l'ouverture de vrais comptes : vérifier l'organisation, ses attributions
et les indicateurs de partage, puis créer un accès nominatif et tester un
dossier autorisé ainsi qu'un dossier d'une autre organisation.

## Audit SMS Coffrac du 15 octobre 2026

Audit de la copie locale du dépôt Coffrac, sans modification de sa production
et sans appel au fournisseur. Ce constat ne certifie pas la validité actuelle
de la clé ni le solde du compte.

- Le menu « Actions / SMS » de `resources/views/dossier/list_new.blade.php`
  ouvre un choix de modèle et un champ message, puis soumet
  `POST /dossier/send/sms` à `DossierController::sendSMS`.
- Fournisseur : **SMSFactor**, envoi JSON vers
  `https://api.smsfactor.com/send`, authentification `Authorization: Bearer`.
  Il ne s'agit pas d'un serveur SMS auto-hébergé ou d'une connexion SMTP.
- La clé est codée en dur dans le contrôleur Coffrac. Aucune variable SMS
  n'est déclarée dans son `.env.example` ; le `.env` de production n'a pas
  été consulté. Ne jamais recopier cette clé dans le dépôt, les logs ou le chat.
- L'expéditeur historique est `GenuisControle` (14 caractères). La limite
  française documentée est de 11 caractères alphanumériques : valider une
  identité courte avant activation, sans modifier silencieusement l'identité.
- Le destinataire vient de `dossier.mobile`. Les variables reconnues sont
  `{client_name}`, `{adresse}`, `{date_rdv}`, `{date_rappel}`, `{societe}` et
  `{technicien}`. L'ancien traitement utilise la première fiche du dossier
  malgré un `fiche_id` reçu : ne pas reproduire cette ambiguïté en V2.
- Les modèles viennent de la table historique `sms_tempalte`. Les **deux
  modèles** du dump sont déjà préservés dans `legacy_records` en simulation,
  mais ne sont pas encore des modèles SMS natifs utilisables depuis la V2.
- Coffrac ignore la réponse de cURL et écrit systématiquement « SMS envoyé »
  dans un commentaire public. Ce comportement ne prouve ni l'acceptation ni
  la livraison du message et ne doit pas être repris.

Configuration préparée dans `services.smsfactor`, `.env.example` et le `.env`
local ignoré par Git : `SMSFACTOR_ENABLED=false`, `SMSFACTOR_API_TOKEN`,
`SMSFACTOR_SENDER`, `SMSFACTOR_TIMEOUT`, `SMSFACTOR_CONNECT_TIMEOUT`.
La valeur historique de l'expéditeur est conservée localement pour l'audit.
La clé n'a été copiée que dans le `.env` local, sans test réseau et sans envoi.
Le workflow d'envoi natif n'est **pas encore branché** ; un changement du booléen
ne suffit pas à créer cette fonctionnalité. Les tests neutralisent la clé.

Avant de brancher l'envoi : nouvelle clé dédiée à TechCalendar (l'ancienne est
présente dans le code Coffrac), permissions internes explicites, aperçu avec
destinataire et prestation exacts, confirmation utilisateur, file de traitement
et suivi des retours fournisseur. Distinguer « accepté » de « livré » ; ne pas
réessayer aveuglément un envoi dont la réponse est incertaine, au risque de
produire des doublons. L'historique doit rester interne par défaut.

Références fournisseur :
[exemple PHP](https://www.smsfactor.com/api-sms-php),
[réponses d'envoi](https://dev.smsfactor.com/en/api/sms/send/send-single),
[contraintes de l'expéditeur](https://www.smsfactor.com/faq-page).

### Branchement natif SMS V2 (15 octobre 2026)

Le constat ci-dessus décrit l'état avant intégration. La fiche dossier propose
maintenant **SMS**, avec modèles historiques `sms_tempalte` lus depuis
`legacy_records`, texte libre, choix explicite du RDV, aperçu et confirmation.
Les variables manquantes bloquent l'aperçu au lieu d'envoyer un texte incomplet.
La modification du texte n'altère jamais le modèle Coffrac.

- Accès réservé aux administrateurs, gérants et planning internes ; contrôle
  répété dans le worker pour les accès révoqués. Historique toujours interne.
- Aperçu chiffré/authentifié, lié au dossier et à l'auteur, valable 20 minutes.
  L'envoi utilise exactement le texte et le numéro confirmés, pas les champs
  renvoyés par le navigateur. Numéro international, un destinataire par envoi.
- Table `dossier_sms` avec contenu/numéro chiffrés, clé de soumission unique,
  anti-double-clic et anti-doublon de contenu sur cinq minutes.
- Job sur la queue configurée ; prise atomique avant l'appel. Pas de retry HTTP
  ni de redirection. Un timeout, HTTP 5xx ou code fournisseur -8 reste incertain
  et ne déclenche jamais un second envoi. Un worker arrêté en cours de traitement
  nécessite une vérification du compte SMSFactor, pas `queue:retry` pour renvoyer.
- États distincts : en attente, transmission, simulation, accepté (pas livré),
  refusé, bloqué, inconnu. Suivi AJAX automatique et bouton d'actualisation.
- SMS d'alerte transactionnelle (`pushtype=alert`), pas une campagne marketing.
  Estimation GSM-7/Unicode affichée avant confirmation. Pas de webhook de
  livraison installé : ne jamais présenter l'acceptation API comme une livraison.
- En `local`, `demo`, ou sur une base de simulation, le mode effectif est
  **toujours simulate**, même avec `SMSFACTOR_MODE=live`. La démo autorise
  uniquement `POST https://api.smsfactor.com/send/simulate`. Elle conserve les
  autres restrictions HTTP, emails, Coffrac et CAPCEE. La simulation transmet
  le texte et le numéro au fournisseur, mais n'envoie pas de SMS et ne débite pas.
- `sms:dispatch` ne reprend que les demandes jamais transmises ; planification
  chaque minute hors simulation. En démo sans cron, le bouton « Reprendre la
  mise en file » fonctionne aussi. Il ne remet jamais un résultat inconnu en file.

#### Retrouver la configuration historique (lecture seule sur Coffrac)

```bash
cd /home/ubuntu/www/coffrac_preprod
# Afficher uniquement les NOMS des éventuelles variables SMS, jamais leur clé.
sed -nE 's/^(SMS[A-Z0-9_]*|SMSFACTOR[A-Z0-9_]*)=.*/\1=<defini>/p' .env
# Dans la version auditée, $token et $sender se trouvent dans sendSMS.
less -N '+/function sendSMS' app/Http/Controllers/DossierController.php
```

Ne pas copier la sortie contenant `$token` dans le chat, les logs ou Git.
Préférer un nouveau jeton SMSFactor dédié à TechCalendar : la clé historique
est présente dans le code Coffrac. Ne pas révoquer l'ancienne clé tant que
Coffrac travaille avec. Aucun changement n'est nécessaire sur ce serveur.

#### Configuration TechCalendar

Sur le CT : `/var/www/TechCalendar-v2/.env` (pas le `.env` V1).
En local : le `.env` de TechCalendar, déjà ignoré par Git.

```dotenv
SMSFACTOR_ENABLED=true
SMSFACTOR_MODE=simulate
SMSFACTOR_API_TOKEN="CLE_PRIVEE_DEDIEE"
SMSFACTOR_SENDER=Genius
SMSFACTOR_TIMEOUT=20
SMSFACTOR_CONNECT_TIMEOUT=5
SMSFACTOR_QUEUE_CONNECTION=database
SMSFACTOR_QUEUE=default
SIMULATION_ALLOW_EXTERNAL_APIS=true
```

`Genius` est un exemple d'identité courte à valider sur le compte SMSFactor.
Ne pas reprendre l'expéditeur historique trop long. Une valeur vide demande
l'expéditeur par défaut du fournisseur ; aucune troncature silencieuse.
Ne pas utiliser de préfixe `VITE_` pour la clé.

Déployer le code et sa migration avec le script de mise à jour V2, puis :

```bash
cd /var/www/TechCalendar-v2
runuser -u www-data -- php8.4 artisan config:cache
runuser -u www-data -- php8.4 artisan sms:check
systemctl restart techcalendar-v2-queue.service
systemctl reload php8.4-fpm
```

`sms:check` n'appelle pas le fournisseur et masque la clé. La clé, les crédits
et le réseau ne sont donc pas validés par cette commande. Pour un test complet,
ouvrir une fiche, saisir un numéro de test autorisé, prévisualiser et confirmer
la **simulation** ; le worker doit retourner « Simulation réussie ».

Les logs techniques ne contiennent ni numéro, ni texte, ni clé, ni réponse brute.
Filtres utiles : `SMS :` dans `storage/logs/laravel.log`, puis `sms_id` pour la
corrélation. Si le statut est « En attente du worker », vérifier le service de
queue et sa queue `default`. Ne pas lancer une seconde demande pour débloquer
la première. Aucun test réseau ni SMS réel n'est exécuté par les tests automatisés.

Contrat fournisseur vérifié :
[envoi JSON](https://dev.smsfactor.com/en/api/sms/send/send-campaign),
[simulation sans création de campagne](https://dev.smsfactor.com/en/api/sms/send/send-list-simulate).

### Fiche dossier, destinataires et documents (16 octobre 2026)

Le dossier accepte désormais deux affectations distinctes : `client_id` et
`delegataire_id`, chacune limitée au bon type d'organisation. Les listes
préremplissent des coordonnées qui restent modifiables sur le dossier. Le choix
« Saisie manuelle » conserve ces informations sans créer de compte ni donner
d'accès au portail. Les coordonnées sont des instantanés du dossier, pas une
modification de la fiche de l'organisation.

Les documents et commentaires proposent trois audiences : **interne uniquement**,
**client uniquement**, **client et délégataire**. L'affectation au dossier et les
permissions du compte externe restent obligatoires, y compris pour les liens de
téléchargement directs. Un contenu partagé n'est jamais accessible anonymement.
Les archives conservent leurs indicateurs historiques privé/délégataire jusqu'à
une décision locale explicite. Les commentaires retirés et documents supprimés
logiquement ne sont plus servis ; leurs traces et fichiers sources sont conservés.
Un document utilisé par un contrôle ne peut pas être supprimé depuis cette liste.

La section Documents réunit les fichiers natifs, ceux des lots et les archives :
auteur, date, description, audience, métadonnées de contrôle, aperçu PDF/image et
téléchargement. Les actions de lot existantes restent la référence pour retirer
un fichier de lot. L'aperçu exclut HTML et SVG et ne publie aucun répertoire privé.

Le formulaire **Libre / Rapport de contrôle** enregistre la référence du contrôle,
la prestation, les dates de visite et d'émission, la conclusion et le PDF. Il peut
simplement enregistrer le rapport ou déclencher une action autorisée du workflow
existant. Il n'existe pas de deuxième circuit de validation : les règles après
visite, les versions concurrentes et la satisfaction des lots restent pilotées
par `PostVisitWorkflow`. Les informations du rapport validé préremplissent le
formulaire CAPCEE ; le dépôt seul ne déclenche aucune transmission externe.

Les actions d'état sont accessibles depuis chaque fiche prestation. Le changement
de technicien avant visite réutilise `AppointmentEditor` : qualification,
disponibilités, verrouillage et traçabilité ne sont pas contournés.

#### Déploiement et reprise des affectations

- Sauvegarder la base avant la migration
  `2026_10_16_100000_separate_dossier_clients_and_content_audiences`.
- La migration déplace les affectations communes de type client vers `client_id`.
  Elle préserve les partages explicites existants et ne réactive aucun compte.
- La reprise des deux affectations historiques est une étape distincte, fondée
  exclusivement sur les identifiants de l'archive locale, jamais sur une similarité
  de nom ou d'email. Les affectations déjà renseignées ne sont pas écrasées.
- Contrôler `php artisan clients:reconcile-legacy` sans option avant de décider
  d'appliquer `php artisan clients:reconcile-legacy --apply`. Cette dernière
  commande modifie les affectations et donc le périmètre des comptes externes
  déjà actifs ; elle ne crée ni utilisateur ni mot de passe.
- Pas de retour automatique à l'ancien modèle : il ferait perdre les deux
  destinataires et les restrictions de partage. Utiliser la sauvegarde avec le
  code correspondant si un retour arrière est nécessaire.
- Les paramètres SMS et les protections du mode simulation restent inchangés.
