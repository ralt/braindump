<?php

use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__).'/vendor/autoload.php';

if (method_exists(Dotenv::class, 'bootEnv')) {
    (new Dotenv())->bootEnv(dirname(__DIR__).'/.env');
}

if ($_SERVER['APP_DEBUG']) {
    umask(0000);
}

// Build the test schema from entity metadata ONLY on SQLite (local dev), where no
// migrations run. On a real database (PostgreSQL — e.g. a deployed CI branch) the schema
// is owned by migrations and per-test isolation is handled by DatabaseTestCase::setUp()'s
// DELETEs. We must NOT drop it there: a --full-database drop also wipes
// doctrine_migration_versions, after which the next deploy's `migrate` re-runs every
// migration and fails ("relation already exists").
$databaseUrl = $_SERVER['DATABASE_URL'] ?? $_ENV['DATABASE_URL'] ?? (getenv('DATABASE_URL') ?: '');
if (str_starts_with((string) $databaseUrl, 'sqlite')) {
    passthru('php bin/console doctrine:schema:drop --full-database --force --env=test --quiet 2>/dev/null');
    passthru('php bin/console doctrine:schema:create --env=test --quiet');
}
