#!/bin/sh
set -e

echo "date.timezone=\"${TZ:-UTC}\"" > "${PHP_INI_DIR}/conf.d/timezone.ini"

# Multi-user installation: every sub directory of /var/www/users with a .env file is a user.
USERS=""
for env_file in /var/www/users/*/.env; do
    [ -f "$env_file" ] || continue
    USERS="$USERS $(basename "$(dirname "$env_file")")"
done

# Runs a console command once, or once for every user on a multi-user installation.
console_for_each_user() {
    if [ -z "$USERS" ]; then
        php /var/www/bin/console "$@"
        return
    fi

    for user in $USERS; do
        echo "[$user] bin/console $*"
        DREEVE_USER="$user" php /var/www/bin/console "$@"
    done
}

# The app and daemon containers share the same image and the same SQLite database
# volume, so only one of them may migrate. The web container is the migrator, every other
# container waits for it to finish before touching the database.
if [ "$1" = "frankenphp" ]; then
    console_for_each_user app:db:migrate --no-interaction
    console_for_each_user app:cache:render:clear --with-version-check
else
    echo 'Waiting for the database schema to be up to date...'
    console_for_each_user app:db:wait-for-schema
    echo 'Database schema updated, starting daemon'
fi

if [ -n "$PUID" ] && [ -n "$PGID" ] && [ "$(id -u)" = "0" ]; then
    echo "Setting permissions for PUID=$PUID PGID=$PGID..."

    chown -R "$PUID:$PGID" \
        /var/www \
        /config/caddy \
        /data/caddy || true

    echo "Permissions have been set"
fi

# Multi-user installation: run one daemon per user. When one of them stops, stop
# them all, so the container exits and Docker's restart policy brings it back up.
if [ -n "$USERS" ] && [ "$1" = "bin/console" ] && [ "$2" = "app:daemon:run" ]; then
    PIDS=""
    for user in $USERS; do
        DREEVE_USER="$user" "$@" &
        PIDS="$PIDS $!"
    done

    trap 'kill $PIDS 2>/dev/null; wait; exit 0' TERM INT

    while :; do
        for pid in $PIDS; do
            if ! kill -0 "$pid" 2>/dev/null; then
                echo "A daemon stopped, stopping the daemons of all users"
                kill $PIDS 2>/dev/null || true
                wait
                exit 1
            fi
        done
        sleep 5
    done
fi

exec "$@"
