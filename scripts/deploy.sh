#!/usr/bin/env bash
set -euo pipefail

APP_DIR="/var/www/domcrm.com.ua"
REPO="git@github.com:ignatyuk71/domcrm.git"
DEPLOY_SHA="${1:-}"
KEEP_RELEASES="${KEEP_RELEASES:-5}"

# Розгортаємо тільки повний SHA, який пройшов перевірки в цьому запуску CI.
if [[ ! "$DEPLOY_SHA" =~ ^[0-9a-f]{40}$ ]]; then
  echo "Потрібен повний SHA перевіреного коміту." >&2
  exit 1
fi

if [[ ! "$KEEP_RELEASES" =~ ^[1-9][0-9]*$ ]]; then
  echo "KEEP_RELEASES має бути додатним цілим числом." >&2
  exit 1
fi

mkdir -p "$APP_DIR/releases" "$APP_DIR/shared"

# Сервер також не допускає одночасних деплоїв цієї CRM.
exec 9>"$APP_DIR/.deploy.lock"
flock -w 600 9

TS="$(date +%F_%H%M%S)_${DEPLOY_SHA:0:12}"
RELEASE_DIR="$APP_DIR/releases/$TS"

# Персистентні зображення залишаються у shared між релізами.
SHARED_PUBLIC_DIRS="storage ai-gallery saved inbox-uploads inbox-context inbox-media inbox-avatars"

git clone --no-checkout "$REPO" "$RELEASE_DIR"
git -C "$RELEASE_DIR" checkout --detach "$DEPLOY_SHA"
[[ "$(git -C "$RELEASE_DIR" rev-parse HEAD)" == "$DEPLOY_SHA" ]]

# .env, storage та bootstrap/cache залишаються спільними.
ln -sfn "$APP_DIR/shared/.env" "$RELEASE_DIR/.env"
rm -rf "$RELEASE_DIR/storage"
ln -sfn "$APP_DIR/shared/storage" "$RELEASE_DIR/storage"
rm -rf "$RELEASE_DIR/bootstrap/cache"
ln -sfn "$APP_DIR/shared/bootstrap/cache" "$RELEASE_DIR/bootstrap/cache"

for directory in $SHARED_PUBLIC_DIRS; do
  rm -rf "$RELEASE_DIR/public/$directory"
  ln -sfn "$APP_DIR/shared/public/$directory" "$RELEASE_DIR/public/$directory"
done

cd "$RELEASE_DIR"
composer install --optimize-autoloader --no-interaction
php artisan optimize:clear
php artisan migrate --force

# public/build надходить із того самого перевіреного коміту.
# Тимчасове посилання дозволяє атомарно замінити current через rename.
ln -sfn "$RELEASE_DIR" "$APP_DIR/.current-next"
mv -Tf "$APP_DIR/.current-next" "$APP_DIR/current"

# Скидаємо opcache після перемикання релізу.
sudo -n /usr/bin/systemctl reload php8.4-fpm || systemctl reload php8.4-fpm || true

# Чистимо лише старі релізи цієї CRM, ніколи не видаляючи активний.
cd "$APP_DIR/releases"
ls -1dt -- */ | tail -n +"$((KEEP_RELEASES + 1))" | while IFS= read -r old_release; do
  if [[ "${old_release%/}" != "$TS" ]]; then
    rm -rf -- "$APP_DIR/releases/$old_release"
  fi
done

echo "DEPLOY_OK: $TS ($DEPLOY_SHA)"
