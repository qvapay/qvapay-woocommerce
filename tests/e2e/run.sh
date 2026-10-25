#!/usr/bin/env bash
# End-to-end webhook scenarios against a throwaway WordPress + WooCommerce in Docker.
# Needs Docker with compose. Exits non-zero if any scenario fails; always tears down.
#
#   bash tests/e2e/run.sh

set -euo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")"
export COMPOSE_PROJECT_NAME=qvapay-wc-e2e

trap 'docker compose down -v >/dev/null 2>&1 || true' EXIT
docker compose up -d --quiet-pull

wp() { docker compose exec -T cli wp "$@"; }

echo "Waiting for the database…"
for _ in $(seq 1 60); do wp db check >/dev/null 2>&1 && break; sleep 1; done
# A second store on the same MySQL server (own database and user), for the lock-name scenario
docker compose exec -T db mariadb -uroot -proot -e \
	"CREATE DATABASE otherstore; CREATE USER 'otherstore'@'%' IDENTIFIED BY 'otherstore'; GRANT ALL ON otherstore.* TO 'otherstore'@'%';"

wp core install --url=http://wp --title=QvaPay-E2E --admin_user=admin \
	--admin_password="$(openssl rand -hex 12)" --admin_email=admin@example.test --skip-email
# Current WooCommerce requires a newer WordPress than the image ships
wp core update --quiet
wp core update-db --quiet
wp plugin install woocommerce --activate --quiet
wp plugin activate qvapay-for-woocommerce --quiet
wp option update woocommerce_currency USD --quiet
wp option update woocommerce_qvapay_settings --format=json --quiet \
	'{"enabled":"yes","title":"QvaPay","description":"","app_id":"11111111-2222-3333-4444-555555555555","app_secret":"e2e-app-secret","webhook_url_override":"","webhook_url":"","debug":"yes"}'
echo "WordPress $(wp core version) · WooCommerce $(wp plugin get woocommerce --field=version)"

wp eval-file /tests/webhook-scenarios.php
