MYMAR : CALIBRATION CIBLEE, LECTURE SEULE

Depuis le Mac : bash fetch-audit.sh
Serveur : ns365601 / ubuntu@46.105.110.76 exclusivement.
Application source : /home/ubuntu/www/MYMAR. Schema source : MYMAR.

BUT
Comparer le RDV du dossier 91 a l'heure observee dans l'interface :
2 novembre 2026, 09:30, technicien source 26 (Anis).
Identifier les valeurs et libelles de duree du formulaire calendrier.
Ne pas confondre la cle dossier 91 avec la cle du rendez-vous.

CONTENU
- Lecture statique des controleurs, du calendrier Vue et de leurs dependances
  locales (64 fichiers, 8 Mio maximum). Aucun fichier applicatif execute.
- Seuls les controles de duree a valeurs numeriques, libelles horaires courts,
  noms d'appels temporels et faits PHP masques sont publies. Pas de code brut,
  de corps de formulaire, de configuration secrete ni de cache execute.
- Schema InnoDB, types et index verifies avant la lecture du RDV.
- SQL sur le seul dossier 91, is_old=0, dix lignes maximum. Aucune date finale
  inventee et aucune conversion UTC/Paris ni minutes/heures appliquee.
- Reference : IDs techniques, date stockee, duree brute et etat uniquement.
  Aucun nom de beneficiaire, adresse, telephone, email, mot de passe ou document.

GARDE-FOUS
- Transactions READ ONLY, limite SELECT 1500 ms et session 20 s.
- Socket SQL local et compte de maintenance existant pour cet audit ponctuel.
  Ce compte ne sera jamais celui du futur connecteur.
- Aucun INSERT/UPDATE/DELETE/DDL, import, bootstrap Laravel, artisan ou paquet.
- Aucun appel HTTP/API, meme vers l'ancienne API MYMAR. Aucun mail/SMS.
- Aucun service, parametre, compte, route, droit ou fichier applicatif modifie.
- Empreintes des fichiers selectionnes controlees avant et apres. Les requetes,
  erreurs brutes et empreintes de configuration restent root-privees sur le serveur.
- Les fichiers crees sont uniquement les paquets et rapports prives hors apps.

Un resultat incomplet ou une source modifiee ne publie pas la reference SQL.
Plusieurs RDV actifs, une date ou un technicien changes sont signales sans
choisir une ligne arbitrairement. Une concordance d'heure seule ne prouve pas
le fuseau historique. L'unite doit etre confirmee avec les controles du formulaire.
Le connecteur reste desactive dans tous les cas ; aucune integration automatique.

Rapport local prive : ne transmettre que LOCAL_DIR pour poursuivre l'analyse.
