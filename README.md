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
- **Salles récentes** (les 10 dernières des 3 derniers jours, avec leur date) sous la liste, et **Historique complet** (`history.php`) : toutes
  les salles terminées ou annulées des 30 derniers jours, par jour (`recent_rooms_max`, `recent_rooms_days` et `history_days` dans `config.php`).

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

## Wars de légion (onglet War)

Les wars sont créées toutes seules chaque semaine, le **jeudi et le samedi à 21:00** (`war_schedule` dans `config.php`),
7 jours à l'avance (`war_days_ahead`). Aucune tâche planifiée : elles apparaissent à la première visite de l'onglet War.

- **Membres** : pour chaque war, ils répondent **Présent**, **En retard**, **Provisoire**, **Banc**
  ou **Absent**, jusqu'à la fin de la war (2 h après le début, `war_length_minutes`) pour les retardataires. La page affiche
  les réponses par rôle, le banc et les absents, comme Raid-Helper. Ils voient les troupes et leur place **seulement une fois
  la compo publiée**, puis chaque changement en direct.
- **Officiers et admins** : ils organisent les troupes à plusieurs en même temps ; la page se met à jour toutes les 2 s
  (`war_poll_ms`) et indique qui a fait le dernier changement. Chaque war démarre avec « Troupe 1 », « Troupe 2 » et
  « Retardataires », de 4 groupes de 5 (`war_troops`, `war_group_size`).
  - Glisser un joueur de « À placer » vers une place, ou clic sur le joueur puis clic sur la place (téléphone).
    Sur une place occupée, les deux échangent. Lâcher un joueur dans « À placer », ou n'importe où hors des groupes,
    le sort de sa troupe.
  - Troupes : renommer, monter / descendre, ajouter un groupe, supprimer, ajouter une troupe de 20.
    Groupes : une place de plus ou de moins, supprimer. Ce qui est supprimé renvoie les joueurs dans « À placer ».
  - **Publier la compo** / Masquer, **Reprendre la compo** de la war précédente (les absents laissent leur place vide),
    Tout remettre à placer, Message affiché en haut, **Répondre pour un membre** (réponse donnée sur Discord), Annuler la war.
- **Rôle en war d'après la classe** (`war_roles`) : Gladiateur et Templier = Tank, Aède et Clerc = Support, les autres = DPS.
  Le rôle du profil (Soin, Polyvalent…) ne sert qu'aux salles de raid. Si la classe change en jeu, le rôle de war suit.
- **CP** affiché sur chaque joueur, avec le CP moyen par troupe et par rôle ; « À placer » est trié par CP (banc à la fin).
- Un joueur qui passe Absent quitte sa place. Les groupes sont numérotés à la suite sur toutes les troupes (Groupe 1 à 12).

## Bot Discord des wars

Le bot poste chaque war dans un salon Discord, avec les inscrits par rôle et des boutons **Présent / En retard /
Provisoire / Banc / Absent**, plus **Ma place** (troupe et groupe, une fois la compo publiée) et un lien vers le site.
Un clic sur Discord = une réponse sur le site, dans la même base ; une réponse sur le site met le message Discord à jour.
Il fonctionne par « interactions HTTP » : pas de programme allumé en permanence, Discord appelle `public/discord.php`.

**Liaison des comptes** (automatique, au premier clic) : le site retrouve le membre par son identifiant Discord ; sinon
par son pseudo sur le serveur (« [Legacy]Junee » → Junee, « Ledormeur|Trafesk » → Trafesk, emojis et lettres
décoratives ignorés) ; sinon il crée le profil (classe et CP lus en jeu ensuite, « Vérifié : non »). Plusieurs profils
possibles, ou profil déjà lié à un autre compte : refus, un officier lie à la main dans le profil (section Compte Discord).
Seuls les membres qui ont un rôle de `member_role_ids` peuvent répondre.

**Ce que fait le bot tout seul** (`public/cron.php`, planificateur Infomaniak toutes les 15 min) : il poste les wars
`auto_post_hours` avant (48 h), envoie un rappel `remind_minutes` avant (60 min), désactive les boutons des wars
terminées. Quand un officier publie la compo, il l'annonce dans le salon (mention de `ping_role_id`).

### Mise en place

1. **Portail développeur Discord** (https://discord.com/developers/applications) : *New Application*.
   - *General Information* : note l'**Application ID** et la **Public Key**.
   - *Bot* : *Reset Token* et copie le **jeton** (il ne s'affiche qu'une fois). Désactive *Public Bot*.
     Aucun « Privileged Gateway Intent » n'est nécessaire.
   - *Installation* (ou *OAuth2 > URL Generator*) : scope `bot`, permissions *View Channels*, *Send Messages*,
     *Embed Links*, *Read Message History* (+ *Mention All Roles* si le rôle à mentionner n'est pas mentionnable,
     *Use External Emojis* pour des emblèmes de classe). Ouvre le lien et ajoute le bot au serveur de la légion.
2. **Discord** : *Paramètres > Avancés > Mode développeur*, puis clic droit > *Copier l'identifiant* sur le serveur,
   le salon des wars et le(s) rôle(s) de la légion.
3. **`config.php`**, bloc `'discord'` : `'enabled' => true`, `application_id`, `public_key`, `guild_id`,
   `war_channel_id`, `member_role_ids`, `ping_role_id` (facultatif). Envoie le fichier sur le serveur.
4. **SSH** : `php bin/discord.php setup` (colle le jeton : il ne s'affiche pas ; il est écrit dans `data/discord.php`,
   jamais dans git), puis `php bin/discord.php test`. La commande `setup` affiche l'adresse du cron.
5. **Portail Discord**, *General Information* > *Interactions Endpoint URL* : `https://legacy.majorsystems.ch/discord.php`,
   puis *Save Changes* (Discord vérifie l'adresse à ce moment ; le site doit déjà être à jour).
6. **Infomaniak** : *Planificateur de tâches* > *Planifier une tâche*, adresse du cron affichée à l'étape 4,
   toutes les 15 minutes.
7. Page War d'une war > **Publier sur Discord** (ou attendre la publication automatique).

En SSH : `php bin/discord.php post ID` poste une war, `php bin/discord.php cron` lance les tâches du cron tout de suite.
Si le jeton fuite : *Reset Token* sur le portail, puis `php bin/discord.php setup`.

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
   crée la base et ton compte admin au premier lancement, puis ouvre l'app sur http://localhost:8080.

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
Pour le refaire à partir de l'Excel (onglet « Membres ») : `python tools/excel_vers_json.py` (Python 3 + `pip install openpyxl`).
Pour l'importer dans une base qui existe déjà (par exemple sur le serveur), en SSH :

```bash
php bin/import.php --dry-run   # montre ce qui sera ajouté
php bin/import.php             # ajoute les membres absents, sans toucher aux comptes existants
php bin/sync.php               # lie leurs personnages en jeu (GS, CP, classe)
```

Envoie `seed/membres.json` sur le serveur juste pour l'import, puis supprime-le.

## Permissions

| | Membre | Raid lead | Officier | Admin |
|---|---|---|---|---|
| Rejoindre les salles, modifier son propre profil | Oui | Oui | Oui | Oui |
| Gérer **toutes** les salles (créer, modifier, déplacer, retirer, terminer) | Seulement les siennes | Oui | Oui | Oui |
| Consulter le profil des autres membres et leurs signalements (pas activé, hors légion…) | Non | Oui, sans rien modifier | Oui | Oui |
| Modifier les autres membres, reset, listes du site | Non | Non | Oui | Oui |
| Donner ou retirer le rang de raid lead | Non | Non | Oui | Oui |
| Retirer de la légion, réinitialiser l'accès | Non | Non | Membres et raid leads | Membres, raid leads et officiers |
| Donner ou retirer le rang d'officier | Non | Non | Non | Oui |
| Nommer d'autres admins | Non | Non | Non | Oui |
| Organiser les wars (troupes, publication) | Non | Non | Oui | Oui |

Les rangs se changent dans le profil du membre, section **Permissions** (visible seulement des officiers ; on ne
peut pas changer ses propres permissions). Un officier n'agit jamais sur un autre officier ni sur un admin,
et un admin n'agit pas sur un autre admin : pas de retrait de la légion, pas de code de réinitialisation (il permettrait
de prendre le compte), pas de changement de rang.

Il peut y avoir plusieurs **admins**. Le premier est désigné par `php bin/install.php Pseudo`. Ensuite, un admin
en nomme d'autres depuis leur profil (bouton « Nommer admin », avec confirmation) ; il garde son rang. Le membre
doit avoir activé son profil. Retirer le rang d'un admin (il reste officier) se fait en SSH :
`php bin/install.php --retirer Pseudo`, refusé pour le dernier admin. `php bin/install.php` avec son propre pseudo sert
aussi à retrouver l'accès, car personne ne peut réinitialiser celui d'un admin depuis le site.

Chaque membre porte le tag de son rang le plus élevé : « Admin », « Officier » ou « Raid lead ». Helper,
shot caller et « clear cette semaine » sont des choix du joueur, dans « Ce que je propose ».

## Premiers pas

1. `php bin/install.php TonPseudo` te passe admin et affiche un code d'activation.
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
- Une fois le personnage trouvé en jeu, son profil et la liste des membres ont des liens vers **kisk.app** et
  **shugo.gg** pour voir son équipement détaillé (`aion_armory_links` dans `config.php` pour en ajouter ou en retirer).

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
bin/install.php       création de la base + premier admin
bin/discord.php       bot Discord : setup (jeton), test, post, cron
public/discord.php    clics Discord (requêtes signées) ; public/cron.php : tâches à heure fixe
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
