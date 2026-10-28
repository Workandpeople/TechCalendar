# Essai local du calendrier sur mesure

Cet essai ne remplace pas FullCalendar en production. Aucun commit ou deploiement
n'est necessaire pour l'essayer. Le layout, l'aside, le menu, le parcours de prise
de RDV et les composants habituels sont conserves.

## Lancer la demonstration fictive

```bash
cd /Users/dedinnich/Projets/TechCalendar
bash tools/local/start-calendar-lab.sh
```

Ouvrir http://127.0.0.1:8808/local/calendar. Un autre port peut etre fourni en
premier argument. Ctrl+C arrete le serveur et efface son stockage temporaire.
Le serveur ecoute uniquement sur 127.0.0.1 et ne sert que cette page et les
ressources statiques publiques. Il ignore le .env du projet et public/hot,
utilise SQLite en memoire et une cle temporaire, sans donnees personnelles,
SMTP, SMS, API externe ou ecriture dans une base existante.

Les RDV et leurs modifications sont conserves seulement en memoire dans l'onglet.
Le changement de technicien, de date, de duree, la remise a placer et la
confirmation explicite d'un chevauchement sont simulables. Ce controle fictif
est volontairement limite aux horaires, ce n'est pas le validateur metier reel.
La carte n'appelle pas Mapbox dans cette demonstration. Les liens du menu
Planning basculent la demonstration sans quitter la page.

Les propositions et les rendez-vous places affichent le tooltip de trajets
habituel au survol et au focus clavier : domicile, rendez-vous precedent,
rendez-vous suivant ou retour domicile, nom du technicien et fiche. Les valeurs
sont explicitement fictives et calculees localement, sans requete Mapbox. Les
vraies pages locales conservent leur fournisseur de trajet existant.

## Essayer avec les vraies pages locales

Sur le serveur local habituel, avec sa connexion et sa base locale :

- `/planner/book?calendar=native`
- `/planner/tracking?calendar=native`

Seul APP_ENV=local accepte le parametre calendar=native. En production, le
moteur est choisi explicitement par COFRAC_CALENDAR_ENGINE (fullcalendar par
defaut, native apres activation du second lot). /local/calendar renvoie 404.
Les endpoints, policies, controles de concurrence, trajets, absences,
habilitations, autorisations de forcage et modals existants sont conserves.
Les actions de ces vraies pages utilisent leur base locale : contrairement a
la demonstration isolee, ce ne sont pas de simples simulations en memoire.

## Affichage

- Semaine : jours en colonnes, heures a gauche, cartes classees par heure de
  debut. La hauteur des lignes s'adapte au contenu ; elle n'est pas une echelle
  proportionnelle des durees. Les horaires de debut et de fin font foi.
- Jour : frise horaire par technicien, zoom et barre de duree reelle. Les cartes
  conservent une largeur lisible et sont separees si elles se rapprochent.
- Mois et Liste : navigation secondaire, sans chargement d'historique complet.
- Par tech. : vue comparative optionnelle, non imposee a la place de la semaine.

Le moteur reprend les plages chargees par le backend existant. Une ancienne
reponse reseau ne peut pas ecraser la periode la plus recente ; une erreur garde
les dernieres donnees et affiche un message, sans envoyer d'action metier.
L'essai reste a valider avec des journees chargees avant tout remplacement en
production. Aucun glisser-deposer ni redimensionnement de RDV n'est introduit.

## Verification

```bash
node --test tests/Frontend/*.test.mjs
php -d memory_limit=512M vendor/bin/pest --compact
npm run build
```
