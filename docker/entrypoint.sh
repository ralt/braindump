#!/bin/sh
set -e

# Wait for the database when one is configured over TCP (Postgres in Compose). SQLite has
# no host, so this loop is skipped.
DB_HOST="$(printf '%s' "${DATABASE_URL:-}" | sed -n 's#.*://[^@]*@\([^:/]*\).*#\1#p')"
if [ -n "$DB_HOST" ]; then
	echo "Waiting for database at $DB_HOST ..."
	until php -r 'exit(@fsockopen($argv[1], (int)($argv[2] ?: 5432)) ? 0 : 1);' "$DB_HOST" "${DB_PORT:-5432}" 2>/dev/null; do
		sleep 2
	done
fi

# Only the web role prepares the schema and seeds a demo user; the worker just consumes.
if [ "${RUN_MIGRATIONS:-0}" = "1" ]; then
	php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration
	# Idempotent: create a demo admin on first boot so there's something to log in with.
	php bin/console app:create-user \
		"${DEMO_USER_EMAIL:-admin@example.com}" \
		"${DEMO_USER_PASSWORD:-password}" \
		"Admin" --admin 2>/dev/null \
		&& echo "Seeded demo user ${DEMO_USER_EMAIL:-admin@example.com} / ${DEMO_USER_PASSWORD:-password}" \
		|| echo "Demo user already exists (or seeding skipped)."
fi

exec "$@"
