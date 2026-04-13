<?php

use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__).'/vendor/autoload.php';

if (method_exists(Dotenv::class, 'bootEnv')) {
    (new Dotenv())->bootEnv(dirname(__DIR__).'/.env');
}

if ($_SERVER['APP_DEBUG']) {
    umask(0000);
}

// Reset test database
passthru('php bin/console doctrine:schema:drop --force --env=test --quiet 2>/dev/null');
passthru('php bin/console doctrine:schema:create --env=test --quiet');
