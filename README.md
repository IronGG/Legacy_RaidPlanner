# Légion Legacy : salles de raid (Aion 2)

Petite app web PHP pour organiser les raids de la légion, construite à partir du classeur
`organisation_ludra_legion_LEGACY.xlsx` :

- **Recensement** : les colonnes de l'onglet « Membres » (classe, rôle, GS, CP, connaissance, meilleure étape,
  objectif, jours, créneau, helper, inscription, clear semaine, shot caller, notes, vérifié). Les 22 membres de l'Excel
  sont importés au premier lancement.
- **Profils** : chaque membre s'inscrit ou active son profil importé, puis modifie ses infos. Changer ses stats
  remet « Vérifié » à non jusqu'à validation par un officier.
- **Salles** (comme des rooms de jeu en ligne) : un membre crée une salle avec une durée d'inscription
  (15 min à 24 h, ou jusqu'au départ). Les autres la rejoignent, la page se met à jour toute seule.
- **Classes avec emblème** partout (grille de groupe, liste des salles, roster, choix de classe).

## Règles des salles

| Phase | Ce qui se passe |
|---|---|
| Inscriptions ouvertes (compte à rebours : 15 min à 7 jours, liste dans `config.php`) | On rejoint une place libre réservée à son rôle, sinon une place « Tous rôles », sinon la réserve. |
| Remplacements (délai passé) | La salle reste ouverte. Si quelqu'un part, le premier réserviste dont le rôle convient à la place monte. S'il n'y a personne en réserve, n'importe qui peut prendre la place libre (badge « Remplaçant »). La réserve n'accepte plus de nouveaux. |
| Terminée / annulée | Le chef de salle (ou un raid lead, ou un officier) clique « Clear réussi » (les titulaires passent « clear cette semaine »), « Finir sans clear » ou « Annuler ». Sans action, la salle s'archive 12 h après le départ, jamais tant que les inscriptions sont ouvertes. |

Autres règles (reprises de l'Excel) :
- un joueur déjà clear cette semaine ne peut pas rejoindre, sauf si la salle accepte les helpers ;
- un joueur « Indisponible » ne peut pas rejoindre ;
- GS minimum facultatif par salle ;
- un joueur peut être dans plusieurs salles à la fois. Quand une salle réussit son clear, ses titulaires quittent
  d'eux-mêmes les autres salles qui n'acceptent pas les joueurs déjà clear, et leur place va à la réserve
  (`one_room_per_member` dans `config.php` pour revenir à une seule salle à la fois) ;
- le chef peut prolonger / rouvrir 30 min, clore les inscriptions, passer quelqu'un en réserve, le remonter, le retirer ;
- « Modifier la salle » (chef, raid leads et officiers) reprend le formulaire de création sans retirer personne : on ne peut pas
  descendre sous le nombre de joueurs déjà dans le groupe ou la réserve, et les places supprimées sont replacées.

### Raid ou donjon

À la création (ou dans « Modifier la salle »), on choisit **Raid** (par défaut) ou **Donjon**. Un donjon n'a ni étape,
ni objectif, ni GS minimum, ni délai d'inscription : on le rejoint tant que le chef ne l'a pas terminé (bouton
« Terminer »). Il ne compte pas pour le clear de la semaine : un joueur déjà clear peut le rejoindre, et le terminer
ne marque personne « clear ». Les places à rôle, la réserve et les déplacements marchent comme pour un raid.

### Places du groupe

Le chef de salle, les raid leads et les officiers cliquent sur « Changer le rôle » sous une place libre pour choisir le rôle recherché (Tank, Soin…) ou « Tous rôles ». Seuls les joueurs de ce rôle peuvent prendre la place ; les autres vont en réserve et montent automatiquement dès qu'une place pour leur rôle s'ouvre (départ, ou rôle de place changé).

On peut aussi s'inscrire volontairement en réserve (« M'inscrire en réserve », pendant les inscriptions), ou laisser sa place du groupe (« Passer en réserve »). Un réserviste volontaire ne monte jamais automatiquement : il clique lui-même sur « Rejoindre le groupe », ou le chef le fait monter. Un joueur arrivé en réserve faute de place peut aussi choisir « Ne pas monter automatiquement ».

Pour déplacer quelqu'un : glisser-déposer, ou clic sur le joueur puis clic sur la place (marche au téléphone). Sur une place occupée, les deux joueurs échangent. Sur la réserve, le joueur y passe. Un chef peut forcer un joueur sur une place d'un autre rôle : il garde un badge « Place X » pour que ça se voie. Échap annule une sélection.

## Lancer l'app

### Avec Docker (le plus simple)

```bash
docker compose up -d --build
docker compose exec app php bin/install.php TonPseudo
```

Ouvre http://localhost:8080/activate.php?pseudo=TonPseudo et entre le code affiché par la commande.
La base SQLite vit dans le volume `guildraid-data`.

### Sous Windows, sans Docker

1. Installe PHP une fois : `winget install --id PHP.PHP.8.3 -e`, puis ferme et rouvre le terminal.
2. Double-clique sur `start.bat` (ou lance-le dans le terminal). Il charge les extensions nécessaires,
   crée la base et ton compte officier au premier lancement, puis ouvre l'app sur http://localhost:8080.

### Sans Docker (Linux / macOS / autre)

PHP 8.1 ou plus avec les extensions `pdo_sqlite` et `mbstring` (activées par défaut avec XAMPP).

```bash
php bin/install.php TonPseudo
php -S localhost:8080 -t public
```

Sur un hébergement PHP classique : la racine web doit pointer sur `public/`. Les dossiers `src/`, `data/`, `seed/`
et `bin/` contiennent aussi un `.htaccess` qui bloque l'accès si ce n'est pas possible.

## Importer le recensement de l'Excel

`seed/membres.json` (hors git) contient les membres de l'Excel. Il est importé tout seul à la création de la base.
Pour l'importer dans une base qui existe déjà (par exemple sur le serveur), en SSH :

```bash
php bin/import.php --dry-run   # montre ce qui sera ajouté
php bin/import.php             # ajoute les membres absents, sans toucher aux comptes existants
php bin/sync.php               # lie leurs personnages en jeu (GS, CP, classe)
```

Envoie `seed/membres.json` sur le serveur juste pour l'import, puis supprime-le.

## Permissions

| | Membre | Raid lead | Officier |
|---|---|---|---|
| Rejoindre les salles, modifier son propre profil | Oui | Oui | Oui |
| Gérer **toutes** les salles (créer, modifier, déplacer, retirer, terminer) | Seulement les siennes | Oui | Oui |
| Consulter le profil des autres membres et leurs signalements (pas activé, hors légion…) | Non | Oui, sans rien modifier | Oui |
| Modifier les autres membres, codes d'accès, reset, listes du site | Non | Non | Oui |

Un officier attribue ces rangs dans le profil du membre, section **Permissions** (visible seulement des officiers ;
on ne peut pas changer ses propres permissions). Chaque membre porte le tag de son rang le plus élevé : « Officier »
ou « Raid lead ». Helper, shot caller et « clear cette semaine » sont des choix du joueur, dans « Ce que je propose ».

## Premiers pas

1. `php bin/install.php TonPseudo` te passe officier et affiche un code d'activation.
2. Active ton compte avec ce code, puis va dans **Officiers**.
3. Pour chaque membre importé, clique **Générer un code** et envoie-le en MP sur Discord avec le lien de
   `activate.php`. Les nouveaux joueurs peuvent aussi s'inscrire eux-mêmes via **S'inscrire**.
4. Le reset hebdomadaire (« clear cette semaine » remis à non pour tous) est automatique chaque mercredi à 10:00
   (`weekly_reset_day` et `weekly_reset_time` dans `config.php`). **Officiers > Faire le reset maintenant** reste
   disponible au besoin.

## Personnaliser

- `config.php` : nom de la légion, fuseau horaire, tailles par défaut, durées proposées, une salle à la fois,
  qui peut créer une salle, fréquence de rafraîchissement.
- **Officiers > Listes du site** : classes (avec leur icône), rôles (couleur, alerte si absent d'un groupe), étapes du raid,
  objectifs, niveaux de connaissance. Ajouter, renommer (les profils et salles suivent), réordonner, masquer, supprimer
  (si inutilisée). Les icônes de classe fournies sont génériques : envoie tes propres images (PNG, JPG, WebP, GIF) depuis cette page.
- `public/assets/app.css` : couleurs générales (variables en haut du fichier).

## GS et CP lus en jeu

L'app lit le **niveau d'objet (GS)**, le **CP**, la classe, le niveau et la légion de chaque membre sur le site
officiel d'AION 2 (serveur Quai, réglable dans `config.php`), comme shugo.gg ou Questlog.

- Le nom du personnage est le pseudo du membre. S'il diffère (accent, autre perso), le membre l'indique dans
  **Mon profil > Personnage en jeu** ; si aucun nom ne correspond, l'app propose les noms proches (« C'est moi »).
- Une fois lié, le GS, le CP et la classe ne se saisissent plus à la main : ils sont relus toutes les 6 h.
- Relecture en arrière-plan, sans tâche planifiée : un petit lot au plus toutes les 10 minutes, après l'affichage
  d'une page (PHP-FPM, comme chez Infomaniak). Les officiers peuvent aussi tout relire depuis **Officiers**.
- En ligne de commande : `php bin/sync.php` (fiches à relire), `php bin/sync.php --all`, `php bin/sync.php Pseudo`.
  Une tâche planifiée qui lance `php bin/sync.php` toutes les heures est possible, mais pas nécessaire.
- Les officiers voient un badge si un personnage n'est pas dans la légion en jeu, ou introuvable.

**Limite à connaître** : NCSOFT ne publie pas d'API officielle. L'app interroge les mêmes adresses que la recherche
de personnage du site officiel (comme les autres outils communautaires), avec peu de requêtes et des pauses.
Si NCSOFT les modifie, la lecture s'arrête : l'app garde les dernières valeurs, l'erreur s'affiche chez les
officiers, et la saisie manuelle redevient possible (`'aion_sync' => false` dans `config.php` pour la forcer).

## Données personnelles et git

Les informations sur les membres ne sont jamais versionnées (voir `.gitignore`) :
`data/` (la base), `seed/membres.json` (le recensement importé de l'Excel), les icônes envoyées,
les exports CSV, les sauvegardes `.sqlite` et les fichiers `.xlsx` ou `.zip`.

- Le code ne contient aucun nom de membre. `seed/membres.example.json` contient deux membres fictifs :
  copie-le en `seed/membres.json` pour tester l'app sur une base vide.
- `seed/membres.json` ne sert qu'au tout premier lancement (création de la base). Sur un serveur déjà installé,
  il n'est plus utile.
- Avant un `git add`, vérifie avec `git status` qu'aucun de ces fichiers n'apparaît.

## Sauvegarde

Tout est dans un seul fichier : `data/guildraid.sqlite` (membres, comptes avec les mots de passe hachés, salles,
listes, journal). Les réglages restent dans `config.php`, les icônes envoyées dans `public/assets/classes/custom/`.
Le site ne propose volontairement aucun téléchargement de la base : on la récupère par SSH/SFTP.

- **Automatique** : toutes les 12 h, une copie dans `data/backups/auto-AAAA-MM-JJ-HHMMSS.sqlite`. Les 14 dernières
  sont gardées (7 jours), les plus anciennes effacées. Réglages `backup_every_hours` et `backup_keep` dans `config.php`.
  Pas de tâche planifiée : la sauvegarde se fait après l'affichage d'une page quand 12 h se sont écoulées
  (sans visite, rien ne change dans la base, donc rien à sauvegarder). L'espace Officiers affiche la dernière.
- **Manuelle**, en SSH : `php bin/backup.php` crée `data/backups/manuel-….sqlite`, jamais effacée automatiquement.
- Ces copies sont sur le même serveur : télécharge-en une de temps en temps (SFTP) pour en avoir une ailleurs.
- Restaurer : remplace `data/guildraid.sqlite` sur le serveur (SFTP) par une sauvegarde. Il faut aussi le même
  `data/pepper.php` qu'au moment de la sauvegarde.
- **Fabriquer le zip de déploiement** : commite tes changements, puis double-clique sur `package.bat`. Il crée
  `../guildraid-app-infomaniak.zip` avec `git archive` : seuls les fichiers suivis par git y entrent (jamais `data/`,
  la clé, `seed/membres.json` ni les icônes envoyées). `.gitattributes` écarte en plus `start.bat` et Docker.
- **La clé de poivre `data/pepper.php`** est créée par `php bin/install.php` (ou au premier compte). Elle n'est
  volontairement dans aucune sauvegarde : récupère-la une fois par SFTP et garde-la à part (gestionnaire de mots de
  passe). Sans elle, plus aucun mot de passe ni code d'activation ne marche : il faudrait repartir d'une base vide.
  Ne la modifie jamais à la main. Ne l'envoie pas d'un PC vers le serveur : chaque installation a la sienne.
  `php bin/pepper.php` affiche son état ; `php bin/pepper.php rotate` la change (tout le monde doit alors
  réactiver son profil avec un nouveau code).
- Mettre à jour l'app : remplace tous les fichiers **sauf** `data/` et `public/assets/classes/custom/`. Les évolutions
  de la base se font toutes seules au premier chargement.

## Structure

```
config.php            réglages
bin/install.php       création de la base + premier officier
seed/membres.json     membres extraits de l'Excel (import au premier lancement)
data/                 base SQLite (créée automatiquement)
src/                  logique : base, authentification, salles, rendu
public/               pages web (racine du site) + assets
```

## Sécurité en place

Mots de passe salés et poivrés : HMAC-SHA256 avec une clé secrète (le poivre, dans `data/pepper.php`, hors de la base),
puis bcrypt (`password_hash`, sel aléatoire par mot de passe). Une copie de la base seule (fuite, sauvegarde égarée)
ne permet pas de tester des mots de passe. Codes d'activation à usage unique, valables 7 jours, stockés eux aussi
en HMAC avec le poivre. Jeton CSRF sur tous les formulaires, requêtes préparées, échappement HTML,
Content-Security-Policy stricte (pas de script inline), cookies `HttpOnly` / `SameSite=Lax` (et `Secure` en HTTPS),
limitation des tentatives de connexion par IP, verrou d'écriture SQLite pour qu'une salle ne dépasse jamais sa capacité,
aucun téléchargement de la base depuis le site. En production, mets l'app derrière HTTPS.

## Pistes pour aller plus loin

- **Connexion Discord (OAuth2)** à la place des mots de passe : plus besoin de codes d'activation, et on peut
  vérifier l'appartenance au serveur Discord de la légion.
- **Webhook Discord** : poster automatiquement « Nouvelle salle : Ludra mercredi 21:00 » dans un salon.
- **Temps réel** : remplacer le polling (toutes les 4 s) par des Server-Sent Events ou Mercure si la légion grandit.
- Reset hebdomadaire automatique (tâche cron) et historique des clears par joueur.
