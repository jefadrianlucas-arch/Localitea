#!/bin/sh
# Localitea container start-up.
# Runs as root before Apache starts. Prepares the Railway volume that is
# mounted at assets/uploads, then hands over to the normal command.
set -e

APP_DIR="${APP_DIR:-/var/www/html}"
SEED_DIR="${SEED_DIR:-/usr/local/share/uploads-seed}"
OWNER="${UPLOAD_OWNER:-www-data:www-data}"

UPLOADS="$APP_DIR/assets/uploads"
MARKER="$UPLOADS/.seeded"

mkdir -p "$UPLOADS"

# 1) First boot only: copy the photos that were baked into the image
#    into the (empty) volume. The marker file stops this from running
#    again, so photos deleted later by an admin do not come back.
if [ ! -f "$MARKER" ]; then
    if [ -d "$SEED_DIR" ]; then
        echo "[entrypoint] First boot: copying seed uploads into $UPLOADS"
        # -n: never overwrite a file that is already on the volume.
        cp -a -n "$SEED_DIR"/. "$UPLOADS"/ || echo "[entrypoint] Some seed files were skipped (already present)."
    fi
    touch "$MARKER"
    echo "[entrypoint] Seeding done."
fi

# 2) Make sure every upload folder exists.
for dir in products promotions receipts refunds admins discount-ids; do
    mkdir -p "$UPLOADS/$dir"
done

# 3) Keep ID photos private even if the Apache rule is ever removed.
HTACCESS="$UPLOADS/discount-ids/.htaccess"
if [ ! -f "$HTACCESS" ]; then
    printf 'Require all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n' > "$HTACCESS"
fi

# 4) A Railway volume is mounted as root; Apache/PHP run as www-data.
chown -R "$OWNER" "$UPLOADS"

exec "$@"