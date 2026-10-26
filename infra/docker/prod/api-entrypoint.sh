#!/bin/sh
# Titik masuk image API produksi: memastikan konfigurasi wajib ada, mengisi cache framework dari environment
# container (bukan dari image), lalu menjalankan perintah yang diminta (edge, queue worker, scheduler, atau migrasi).
set -eu

cd /app

: "${APP_KEY:?APP_KEY wajib diisi (php artisan key:generate --show)}"

if [ "${APP_ENV:-production}" = "production" ]; then
  php artisan config:cache --no-interaction
  php artisan route:cache --no-interaction
  php artisan event:cache --no-interaction
  php artisan view:cache --no-interaction
fi

exec "$@"
