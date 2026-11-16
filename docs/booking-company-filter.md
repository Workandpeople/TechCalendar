# Filtre et tri societe dans la file Cofrac

La rubrique Prise de RDV > RDV Cofrac combine la recherche client existante avec un selecteur de societe et un tri Societe A-Z / Z-A. Le libelle est celui deja affiche sur la ligne (client, sinon delegataire), pas le nom du beneficiaire. Une entree Societe non renseignee couvre les dossiers sans rattachement affiche.

Recherche, societe et tri s'appliquent a toute la file deja chargee avant la pagination. La recherche ignore les accents et combine les mots du beneficiaire, de la societe et de la prestation. Le compteur filtre indique le nombre de prestations affichees ; le compteur global des dossiers en prise de RDV reste inchange. Les valeurs sont rendues en texte, jamais en HTML.

Une actualisation de la file conserve le filtre et le tri, meme si la societe choisie ne compte plus de dossiers (option conservee avec 0 resultat). Un changement de filtre retourne a la premiere page. Reinitialiser efface recherche et societe et restaure l'ordre initial fourni par le serveur. Les autres onglets (lots/MAR) gardent leur fonctionnement distinct.

Aucune requete externe ni geolocalisation supplementaire, aucune modification de dossier et aucune nouvelle permission ne sont ajoutees par ce filtrage.
