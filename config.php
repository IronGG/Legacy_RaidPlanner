<?php
// Réglages de l'application. Tout ce qui est propre à ta légion se change ici.
return [
    'app_name'  => 'Légion Legacy',
    'tagline'   => 'Salles de raid Aion 2',
    'timezone'  => 'Europe/Paris',

    // Base SQLite (créée automatiquement au premier lancement) et import initial des membres
    'db_path'   => __DIR__ . '/data/guildraid.sqlite',
    'seed_path' => __DIR__ . '/seed/membres.json',

    // Valeurs par défaut proposées à la création d'une salle
    'default_capacity'    => 10,
    'default_reserve'     => 8,
    'join_windows'        => [15 => '15 min', 30 => '30 min', 60 => '1 h', 120 => '2 h', 180 => '3 h', 360 => '6 h', 1440 => '24 h'],
    'default_join_window' => 30,

    // Un joueur ne peut être que dans une seule salle active à la fois (comme une room de jeu)
    'one_room_per_member' => true,

    // Qui peut créer une salle : 'all' (tout membre connecté) ou 'officers'
    'room_creation' => 'all',

    // Une salle active est archivée automatiquement X heures après son départ
    // (ou après la fin des inscriptions si aucune heure de départ n'est fixée)
    'archive_after_hours' => 12,

    // Fréquence de rafraîchissement des salles dans le navigateur (ms)
    'poll_ms' => 4000,

    // Lecture automatique du GS (niveau d'objet) et du CP depuis le site officiel d'AION 2
    'aion_sync'          => true,
    'aion_region'        => 'eu',     // nae, naw, eu, la ou as
    'aion_server_id'     => 2317,     // identifiant officiel du serveur Quai
    'aion_server_name'   => 'Quai',
    'aion_lang'          => 'fr-FR',  // classes en français, comme les listes du site
    'aion_legion'        => 'Legacy', // légion attendue en jeu (les officiers sont avertis sinon)
    'aion_refresh_hours' => 6,        // âge maximum d'une fiche avant relecture
    'aion_user_agent'    => 'GuildRaid-Legacy/1.0 (organisation de raids de la legion Legacy)',
];
