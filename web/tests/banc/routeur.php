<?php
// Routeur pour `php -S` : fichiers existants servis tels quels, tout le reste passe par index.php (comme .htaccess).
$racine = realpath(__DIR__ . '/../../public');
$chemin = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($chemin !== '/' && is_file($racine . $chemin)) {
    return false;
}
if ($chemin === '/') {
    readfile($racine . '/index.html');
    return true;
}
require $racine . '/index.php';
