<?php
require('config.php');
require('src/controller.php');
$ver = '0.0.1'; $base = 'https://'.$config['base'];

try {
    $db = new PDO('mysql:host='.$config['mysql']['host'].';dbname='.$config['mysql']['database'],
        $config['mysql']['username'], $config['mysql']['password'], [PDO::ATTR_PERSISTENT => true]);
    controller();
} catch (PDOException $e) {
    exit('Error: ' . $e->getMessage());
}