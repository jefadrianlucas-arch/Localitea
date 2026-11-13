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

# 5) Enforce a single Apache MPM at runtime.
echo "[entrypoint] Checking Apache MPM configuration..."

echo "[entrypoint] MPM configuration links before cleanup:"
ls -la /etc/apache2/mods-enabled/mpm_* 2>&1 || true

echo "[entrypoint] Removing all enabled MPM configuration links..."
rm -f /etc/apache2/mods-enabled/mpm_*.load \
      /etc/apache2/mods-enabled/mpm_*.conf

echo "[entrypoint] Enabling mpm_prefork..."
a2enmod mpm_prefork

echo "[entrypoint] Checking Apache modules before startup..."
apache2ctl -M 2>&1

MPM_COUNT="$(apache2ctl -M 2>&1 | grep -Ec 'mpm_(event|worker|prefork)_module' || true)"

echo "[entrypoint] Loaded MPM count: $MPM_COUNT"

if [ "$MPM_COUNT" -ne 1 ]; then
    echo "[entrypoint] ERROR: Apache must have exactly one MPM loaded."
    exit 1
fi

echo "[entrypoint] Apache MPM configuration is valid."

exec "$@"