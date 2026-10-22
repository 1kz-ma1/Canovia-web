#!/bin/sh
# Run ONLY inside a disposable, network-disabled image. Never run on a real host.
set -eu

[ "${CANOVIA_HOTFIX_CI_ISOLATED:-}" = "yes" ] || {
  echo 'Isolated image marker missing' >&2
  exit 42
}
[ "$(id -u)" -eq 0 ] || {
  echo 'Disposable root image expected for mocks' >&2
  exit 42
}

# Shadow *only within this throwaway Docker layer*. The real services remain
# untouched. Record whether the original startup invokes any Artisan command.
mv /usr/local/bin/php /tmp/php-real-hotfix-test
cat > /usr/local/bin/php <<'PHP_MOCK'
#!/bin/sh
case "$*" in
  *migrate*|*schema*|*db:*)
    echo 'BLOCKED: unexpected database command' >&2
    exit 99
    ;;
esac
printf '%s\n' "$*" >> /tmp/canovia-hotfix-php-invocations
exit 0
PHP_MOCK
chmod +x /usr/local/bin/php

mv /usr/bin/supervisord /tmp/supervisord-real-hotfix-test
cat > /usr/bin/supervisord <<'SUPERVISOR_MOCK'
#!/bin/sh
echo 'SUPERVISORD_STUB_REACHED'
exit 0
SUPERVISOR_MOCK
chmod +x /usr/bin/supervisord

cd /var/www/html
: > /tmp/canovia-hotfix-php-invocations

# Correct mode is a no-DDL path. Exact script runs but PHP and the supervisor
# are mocked and all network access is disabled by the calling Docker command.
APP_ENV=production CANOVIA_STARTUP_MIGRATIONS=check CANOVIA_APPROVED_MIGRATION_SHA= \
  sh ./docker/render-start.sh > /tmp/allowed-hotfix-startup 2>&1
grep -Fq 'SUPERVISORD_STUB_REACHED' /tmp/allowed-hotfix-startup
grep -Fxq 'artisan config:cache' /tmp/canovia-hotfix-php-invocations
grep -Fxq 'artisan route:cache' /tmp/canovia-hotfix-php-invocations
grep -Fxq 'artisan view:cache' /tmp/canovia-hotfix-php-invocations
if grep -Ei 'migrat|schema|db:' /tmp/canovia-hotfix-php-invocations; then
  echo 'Unexpected migration or database command invoked' >&2
  exit 1
fi

# Unsafe settings must fail *before* any PHP/Artisan call.
check_refusal() {
  : > /tmp/canovia-hotfix-php-invocations
  set +e
  "$@" sh ./docker/render-start.sh > /tmp/refused-hotfix-startup 2>&1
  code=$?
  set -e
  [ "$code" -eq 42 ] || {
    echo "Expected fail-closed exit 42; actual: $code" >&2
    exit 1
  }
  [ ! -s /tmp/canovia-hotfix-php-invocations ] || {
    echo 'PHP invoked before unsafe setting was rejected' >&2
    exit 1
  }
}

check_refusal env APP_ENV=staging CANOVIA_STARTUP_MIGRATIONS=check CANOVIA_APPROVED_MIGRATION_SHA=
check_refusal env APP_ENV=production CANOVIA_STARTUP_MIGRATIONS=apply CANOVIA_APPROVED_MIGRATION_SHA=
check_refusal env APP_ENV=production CANOVIA_STARTUP_MIGRATIONS=bogus CANOVIA_APPROVED_MIGRATION_SHA=
check_refusal env APP_ENV=production CANOVIA_STARTUP_MIGRATIONS=check CANOVIA_APPROVED_MIGRATION_SHA=0000000000000000000000000000000000000000

echo 'HOTFIX_NO_DDL_OFFLINE_TEST=PASS'
