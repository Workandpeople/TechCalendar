# Commentaires mis en forme

## Perimetre

Barre compacte dans les commentaires de dossiers internes et externes, les
reponses de non-conformite et les notes/problemes RDV de la prise et du suivi
de rendez-vous. Les droits, portees et categories accessibles restent inchanges.

Formats : gras, italique, souligne, barre, listes, annuler/retablir, trois
familles de polices locales, tailles bornees et huit couleurs lisibles.
La palette partagee PHP/JS se trouve dans `resources/comment-formats.json`.
Pas de police distante, image, video, formule, lien ni HTML libre.

## Stockage et affichage

- Aucun changement de schema ni reecriture des commentaires historiques.
- `CommentContent` reconstruit une liste blanche de balises et styles avec
  le parseur HTML5 de PHP 8.4. Aucun attribut de script, URL ou CSS arbitraire.
- Les nouveaux commentaires du dossier sont nettoyes dans le service metier.
  Les commentaires vides apres nettoyage sont refuses.
- Le composant `comment-body` effectue aussi le nettoyage lors de la lecture,
  notamment pour les archives importees. Le contenu sur peut etre rendu sans JS.
- Les modals dynamiques emploient la meme liste blanche cote navigateur.
- Les tooltips utilisent uniquement le texte lisible, sans couleur, police,
  taille ni balises. Les variables de mails sont aussi converties en texte ;
  la notification technicien peut conserver le HTML nettoye.
- L'extrait de deux lignes mesure la hauteur reellement visible, pour ne pas
  couper les caracteres plus grands avec une hauteur fixe inadaptee.

## Editeur et performance

Quill est charge dans un chunk separe quand le champ devient visible ou prend
le focus. Le champ textarea reste utilisable si le chargement echoue. Son nom,
sa valeur et les affectations `.value` des modals existants sont conserves.
La validation du contenu remplace le `required` du textarea masque afin d'eviter
les erreurs de formulaire non focalisable. Le serveur reste autoritaire.

Seul `quill/core` et les formats explicitement listes sont importes. Aucun
format video/formule/image/lien n'est enregistre. Les collages et exports HTML
sont reconstruits par notre liste blanche avant affichage ou stockage.

### Avis de dependance Quill 2.0.3

L'avis [GHSA-v3m3-f69x-jf25](https://github.com/advisories/GHSA-v3m3-f69x-jf25)
vise l'export HTML des formats video/formule. Ces modules sont absents de notre
build et l'export n'est jamais utilise comme HTML brut : nettoyage navigateur
puis serveur. Des tests couvrent ces vecteurs, les styles et attributs dangereux,
les entites litterales et les imbrications profondes. L'avis reste visible dans
`npm audit` ; reevaluer cette exception a chaque mise a jour de Quill.

## Verification

Tests PHP : nettoyage/idempotence, commentaires internes et clients, droits,
formulaires, historique non reecrit et tooltips en texte simple.
Tests JS : liste blanche, validation de l'editeur et extraits de tailles variees.
Controle navigateur local : formats combines, annuler/retablir, blocage des
commentaires vides et absence de debordement horizontal a 390 px.

Build necessaire : `npm ci --ignore-scripts && npm run build`.
Pas de migration, seeder, activation de connecteur ni envoi de message.
