<?php

use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__).'/vendor/autoload.php';

if (method_exists(Dotenv::class, 'bootEnv')) {
    (new Dotenv())->bootEnv(dirname(__DIR__).'/.env');
}

if ($_SERVER['APP_DEBUG']) {
    umask(0000);
}

// Reset the test database. Use --full-database: the metadata-based drop reports success
// but silently leaves tables when the schema was built by migrations (as on a deployed
// branch cloned from main), which then makes schema:create fail with "ai_session already
// exists" and leaves a half-built schema. Dropping by introspection clears it reliably.
passthru('php bin/console doctrine:schema:drop --full-database --force --env=test --quiet 2>/dev/null');
passthru('php bin/console doctrine:schema:create --env=test --quiet');
