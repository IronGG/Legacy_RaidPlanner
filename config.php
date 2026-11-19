<?php
// Réglages de l'application. Tout ce qui est propre à ta légion se change ici.
return [
    'app_name'  => 'Légion Legacy',
    'tagline'   => 'Salles de raid Aion 2',
    'timezone'  => 'Europe/Paris',

    // Base SQLite (créée automatiquement au premier lancement) et import initial des membres
    'db_path'   => __DIR__ . '/data/guildraid.sqlite',
    // Clé secrète (poivre) des mots de passe : créée toute seule, hors de la base. À sauvegarder à part.
    'pepper_path' => __DIR__ . '/data/pepper.php',
    'seed_path' => __DIR__ . '/seed/membres.json',

    // Valeurs par défaut proposées à la création d'une salle
    'default_capacity'    => 10,
    'default_reserve'     => 8,
    'join_windows'        => [15 => '15 min', 30 => '30 min', 60 => '1 h', 120 => '2 h', 180 => '3 h', 360 => '6 h', 1440 => '24 h', 4320 => '3 jours', 10080 => '7 jours'],
    'default_join_window' => 30,

    // false : un joueur peut être dans plusieurs salles à la fois. Quand une salle réussit son clear, ses titulaires
    // quittent d'eux-mêmes les autres salles qui n'acceptent pas les joueurs déjà clear.
    // true : une seule salle active à la fois (comme une room de jeu).
    'one_room_per_member' => false,

    // Qui peut créer une salle : 'all' (tout membre connecté) ou 'officers' (officiers et raid leads)
    'room_creation' => 'all',

    // Salles terminées ou annulées : « Salles récentes » sur la page Salles, « Historique complet » (history.php)
    'recent_rooms_days'   => 3,
    'recent_rooms_max'    => 10,   // au plus les X plus récentes ; le reste est dans l'historique
    'history_days'        => 30,

    // Une salle active est archivée automatiquement X heures après son départ
    // (ou après la fin des inscriptions si aucune heure de départ n'est fixée)
    'archive_after_hours' => 12,

    // Fréquence de rafraîchissement des salles dans le navigateur (ms)
    'poll_ms' => 4000,

    // Reset hebdomadaire automatique des « clear cette semaine » (heure locale, fuseau ci-dessus)
    'weekly_reset_day'  => 3,        // 1 = lundi … 7 = dimanche (3 = mercredi) ; 0 pour désactiver
    'weekly_reset_time' => '10:00',

    // Sauvegardes automatiques de la base dans data/backups/ (hors du site : à récupérer par SFTP)
    'backup_every_hours' => 12,   // 0 pour désactiver
    'backup_keep'        => 14,   // sauvegardes automatiques conservées (14 × 12 h = 7 jours)

    // Lecture automatique du GS (niveau d'objet) et du CP depuis le site officiel d'AION 2
    'aion_sync'          => true,
    'aion_region'        => 'eu',     // nae, naw, eu, la ou as
    'aion_server_id'     => 2317,     // identifiant officiel du serveur Quai
    'aion_server_name'   => 'Quai',
    'aion_lang'          => 'fr-FR',  // classes en français, comme les listes du site
    'aion_legion'        => 'Legacy', // légion attendue en jeu (les officiers sont avertis sinon)
    'aion_refresh_hours' => 6,        // âge maximum d'une fiche avant relecture
    // Sites communautaires pour voir l'équipement détaillé d'un personnage (liens dans les profils et la liste des membres).
    // {region} = aion_region, {server} = aion_server_name en minuscules, {name} = nom du personnage en jeu,
    // {id} = identifiant officiel du personnage (lu avec le GS et le CP ; sans lui, le lien n'est pas affiché).
    'aion_armory_links'  => [
        'kisk.app'    => 'https://kisk.app/c/{region}/{server}/{name}',
        'shugo.gg'    => 'https://shugo.gg/character/global/{region}-{server}/{name}',
        'questlog.gg' => 'https://questlog.gg/aion-2/en/armory/{region}/{id}',
    ],
    'aion_user_agent'    => 'GuildRaid-Legacy/1.0 (organisation de raids de la legion Legacy)',

    // Wars de légion (onglet War) : créées toutes seules chaque semaine, ouvertes aux inscriptions à l'avance.
    'war_schedule'       => [[4, '21:00'], [6, '21:00']], // [jour, heure] : 1 = lundi … 7 = dimanche ; ici jeudi et samedi à 21:00
    'war_days_ahead'     => 7,      // une war apparaît (et s'ouvre aux inscriptions) X jours avant
    'war_length_minutes' => 120,    // durée : inscriptions possibles jusqu'à la fin (retardataires), puis la war passe dans l'historique
    'war_group_size'     => 5,      // places par groupe
    // Rôle en war, déduit de la classe (le rôle du profil, lui, sert aux salles de raid). Ordre des colonnes = ordre ici.
    'war_roles'          => [
        'Tank'    => ['Gladiateur', 'Templier', 'Gladiator', 'Templar'],
        'DPS'     => [],                                   // toutes les autres classes (war_default_role)
        'Support' => ['Aède', 'Clerc', 'Chanter', 'Cleric'],
    ],
    'war_default_role'   => 'DPS',
    // Troupes créées pour chaque nouvelle war : [nom, nombre de groupes]. Les officiers les renomment et les modifient ensuite.
    'war_troops'         => [['Troupe 1', 4], ['Troupe 2', 4], ['Retardataires', 4]],
    'war_poll_ms'        => 2000,   // rafraîchissement de la page War (ms), plus rapide pour que les officiers voient les changements des autres

    // Bot Discord des wars (README, section « Bot Discord »). Rien de secret ici : le jeton du bot et la clé du cron
    // sont dans data/discord.php, créé sur le serveur par « php bin/discord.php setup ».
    'discord' => [
        'enabled'         => false,
        'application_id'  => '',   // Portail développeur Discord > General Information > Application ID
        'public_key'      => '',   // … > Public Key (vérifie que les clics viennent bien de Discord)
        'guild_id'        => '',   // serveur de la légion (mode développeur : clic droit > Copier l'identifiant)
        'war_channel_id'  => '',   // salon où le bot poste les wars
        'member_role_ids' => [],   // rôles autorisés à répondre, p. ex. ['123456789'] ; vide = tout le serveur
        'ping_role_id'    => '',   // rôle mentionné dans les annonces (vide = aucune mention)
        'site_url'        => 'https://legacy.majorsystems.ch',
        'auto_post_hours' => 48,   // le bot poste chaque war X heures avant (0 = seulement avec le bouton du site)
        'remind_minutes'  => 60,   // rappel dans le salon X minutes avant (0 = pas de rappel)
        'announce_compo'  => true, // message dans le salon quand la compo est publiée
        'role_emojis'     => ['Tank' => '🛡️', 'DPS' => '⚔️', 'Support' => '✨'],
        'class_emojis'    => [],   // facultatif : 'Gladiateur' => '<:gladiateur:123456789012345678>'
    ],
];
