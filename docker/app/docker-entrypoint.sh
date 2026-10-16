#!/bin/sh
set -e

echo "date.timezone=\"${TZ:-UTC}\"" > "${PHP_INI_DIR}/conf.d/timezone.ini"

# Multi-user installation (DREEVE_USER_URL is set): the users live in the user registry.
MULTI_USER=""
if [ -n "$DREEVE_USER_URL" ]; then
    MULTI_USER="1"
fi

active_users() {
    php /var/www/bin/dreeve-users list
}

# Runs a console command once, or once for every active user on a multi-user installation.
console_for_each_user() {
    if [ -z "$MULTI_USER" ]; then
        php /var/www/bin/console "$@"
        return
    fi

    for user in $(active_users); do
        echo "[$user] bin/console $*"
        DREEVE_USER="$user" php /var/www/bin/console "$@"
    done
}

# The app and daemon containers share the same image and the same SQLite database
# volume, so only one of them may migrate. The web container is the migrator, every other
# container waits for it to finish before touching the database.
if [ "$1" = "frankenphp" ]; then
    if [ -n "$MULTI_USER" ]; then
        # Creates the owner on the very first start and prints the link to pick a password.
        php /var/www/bin/dreeve-users bootstrap
    fi
    console_for_each_user app:db:migrate --no-interaction
    console_for_each_user app:cache:render:clear --with-version-check
elif [ -z "$MULTI_USER" ]; then
    echo 'Waiting for the database schema to be up to date...'
    php /var/www/bin/console app:db:wait-for-schema
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

# Multi-user installation: run one daemon per active user. Users are added, disabled and
# removed while the app runs, and their settings change, so the
# list is checked every 30 seconds: daemons are started, stopped and restarted to match.
if [ -n "$MULTI_USER" ] && [ "$1" = "bin/console" ] && [ "$2" = "app:daemon:run" ]; then
    RUNNING=""  # "name:fingerprint:pid" entries

    stop_all() {
        for entry in $RUNNING; do
            kill "${entry##*:}" 2>/dev/null || true
        done
        wait
        exit 0
    }
    trap stop_all TERM INT

    while :; do
        if ! WANTED=$(php /var/www/bin/dreeve-users list --fingerprints); then
            echo "Could not read the users, retrying in 30 seconds"
            sleep 30 &
            wait $! || true
            continue
        fi

        STILL_RUNNING=""
        for entry in $RUNNING; do
            user="${entry%%:*}"
            fingerprint="${entry#*:}"
            fingerprint="${fingerprint%%:*}"
            pid="${entry##*:}"
            if ! echo "$WANTED" | grep -qx "$user $fingerprint"; then
                echo "[$user] user was disabled, removed or has new settings, stopping its daemon"
                kill "$pid" 2>/dev/null || true
            elif kill -0 "$pid" 2>/dev/null; then
                STILL_RUNNING="$STILL_RUNNING $entry"
            else
                echo "[$user] daemon stopped, it will be restarted"
            fi
        done
        RUNNING="$STILL_RUNNING"

        echo "$WANTED" | while read -r user fingerprint; do
            [ -n "$user" ] && echo "$user:$fingerprint"
        done > /tmp/dreeve-wanted-users

        while IFS=: read -r user fingerprint; do
            case " $RUNNING " in
                *" $user:"*) ;;
                *)
                    if DREEVE_USER="$user" php /var/www/bin/console app:db:wait-for-schema; then
                        echo "[$user] starting daemon"
                        DREEVE_USER="$user" "$@" &
                        RUNNING="$RUNNING $user:$fingerprint:$!"
                    fi
                    ;;
            esac
        done < /tmp/dreeve-wanted-users

        sleep 30 &
        wait $! || true
    done
fi

exec "$@"
