<?php

require_once dirname(__DIR__).DIRECTORY_SEPARATOR.'smarty.php';

use nntmux\Users;

$page = new AdminPage();

if (isset($_GET['id'])) {
    $users = new Users();
    $users->deleteRole($_GET['id']);
}

$referrer = $_SERVER['HTTP_REFERER'];
header('Location: '.$referrer);
