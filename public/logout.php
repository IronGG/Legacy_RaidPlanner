<?php
require __DIR__ . '/../src/bootstrap.php'; // vérifie le jeton CSRF sur POST
if (is_post()) {
    $_SESSION = [];
    session_regenerate_id(true);
    session_destroy();
}
redirect('login.php');
