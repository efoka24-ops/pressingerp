#!/usr/bin/env bash
# Déploiement FTP sur l'hébergement Camoo (sans SSH). La racine FTP est la racine web.
# Usage : ops/deploy.sh code | push <fichiers> | config | migrate | run <tâche> | check | all
#   code     envoie les fichiers modifiés depuis le dernier déploiement (tout au premier passage)
#   config   (re)génère config/config.local.php depuis ops/.deploy.env et l'envoie
#   migrate  migrations à usage unique : jeton, exécution, suppression du script, jeton vidé
#   run      tâche à usage unique via public/ops-run.php : selftest (recette automatisée), backup
#   check    vérifie les protections (403 sur le code, 404 sur les scripts de migration)
#   all      code + config + migrate + check
# Prérequis : bash, curl, php en local ; ops/.deploy.env renseigné (non versionné).
set -euo pipefail
cd "$(dirname "$0")/.."
set -a; . ./ops/.deploy.env; set +a

FTP="ftp://$FTP_HOST"
CRED=(--user "$FTP_USER:$FTP_PASS")
SITE="${SITE_URL%/}"
SITE_HTTP="${SITE/https:/http:}"   # pas de TLS tant que le certificat n'est pas actif
LAST=ops/.last-deploy

up()  { curl -sS --connect-timeout 30 --retry 4 --retry-delay 3 --retry-all-errors --ftp-create-dirs -T "$1" "${CRED[@]}" "$FTP/$2" >/dev/null; }
del() { curl -sS "${CRED[@]}" -Q "-DELE $1" "$FTP/" >/dev/null 2>&1 || true; }
code_of() { curl -s -o /dev/null -w '%{http_code}' --max-time 30 "$1" || echo 000; }

build_config() {  # $1 = jeton de migration (vide = désactivé)
  if [ -z "${AUDIT_KEY:-}" ]; then   # clé de signature de l'audit : générée une fois, conservée dans ops/.deploy.env
    AUDIT_KEY=$(php -r 'echo bin2hex(random_bytes(32));'); echo "AUDIT_KEY=$AUDIT_KEY" >> ops/.deploy.env
  fi
  DBN="$DB_NAME" DBU="$DB_USER" DBP="$DB_PASS" TOK="$1" URL="$SITE_HTTP" \
  AK="$AUDIT_KEY" SK="${SUNGKU_API_KEY:-}" SS="${SUNGKU_WEBHOOK_SECRET:-}" SB="${SUNGKU_BASE_URL:-https://sungku.trugroup.cm}" \
  php -r '
    $c = [
      "app"   => ["url" => getenv("URL"), "debug" => false, "env" => "production"],
      "db"    => ["dsn" => "mysql:host=localhost;port=3306;dbname=" . getenv("DBN") . ";charset=utf8mb4", "user" => getenv("DBU"), "pass" => getenv("DBP")],
      "sungku" => ["base_url" => getenv("SB"), "api_key" => getenv("SK"), "webhook_secret" => getenv("SS")],
      "audit" => ["key" => getenv("AK")],
      "migrate_token" => getenv("TOK"),
    ];
    echo "<?php\nreturn " . var_export($c, true) . ";\n";' > ops/config.local.generated.php
  up ops/config.local.generated.php pressing/config/config.local.php
}

deploy_code() {
  local list
  if [ -f "$LAST" ] && git cat-file -e "$(cat "$LAST")" 2>/dev/null; then
    list=$( { git diff --name-only "$(cat "$LAST")"; git ls-files -o --exclude-standard; } | sort -u )
  else
    list=$(git ls-files)
  fi
  local n=0
  while IFS= read -r f; do
    [ -f "$f" ] || continue
    case "$f" in
      app/*|bin/*|config/*|database/*|public/*|tests/*) ;;
      *) continue ;;
    esac
    case "$f" in config/config.local.php|public/migrate.php|public/ops-run.php|public/uploads/*) continue ;; esac
    up "$f" "pressing/$f"; n=$((n+1))
  done <<< "$list"
  for d in app bin config database tests; do up ops/htaccess/deny.htaccess "pressing/$d/.htaccess"; done
  up ops/htaccess/root.htaccess ".htaccess"
  git rev-parse HEAD > "$LAST"
  echo "code : $n fichier(s) envoyé(s)"
}

deploy_migrate() {
  local token; token=$(php -r 'echo bin2hex(random_bytes(16));')
  build_config "$token"
  up public/migrate.php pressing/public/migrate.php
  echo "--- migrations"
  curl -sS -X POST --max-time 180 "$SITE_HTTP/migrate.php?token=$token" || true
  del pressing/public/migrate.php
  build_config ""
  echo "--- script supprimé, jeton invalidé"
}

deploy_run() {  # $1 = tâche
  local task="${1:?tâche manquante (selftest|backup|restore-test|smoke)}" extra="${2:-}" token; token=$(php -r 'echo bin2hex(random_bytes(16));')
  build_config "$token"
  up public/ops-run.php pressing/public/ops-run.php
  echo "--- $task"
  curl -sS -X POST --max-time 180 "$SITE_HTTP/ops-run.php?token=$token&task=$task" ${extra:+--data-urlencode "$extra"} || true
  del pressing/public/ops-run.php
  build_config ""
  echo "--- script supprimé, jeton invalidé"
}

check() {
  local bad=0 c
  for u in pressing/config/config.php pressing/app/routes.php pressing/bin/install.php pressing/database/migrations/0001_base.sql pressing/tests/run.php pressing/storage/backups/x.sql.gz; do
    c=$(code_of "$SITE_HTTP/$u"); [ "$c" = 403 ] || { echo "ALERTE $u -> $c (attendu 403)"; bad=1; }
  done
  for u in migrate.php ops-run.php reset-admin.php pressing/config/config.local.php; do
    c=$(code_of "$SITE_HTTP/$u"); case "$c" in 403|404) ;; *) echo "ALERTE $u -> $c"; bad=1;; esac
  done
  c=$(code_of "$SITE_HTTP/login"); [ "$c" = 200 ] || { echo "ALERTE /login -> $c"; bad=1; }
  [ $bad = 0 ] && echo "check : OK (code protégé, scripts supprimés, /login 200)"
  return $bad
}

case "${1:-}" in
  code) deploy_code ;;
  config) build_config "" ; echo "config envoyée" ;;
  push) shift; for f in "$@"; do up "$f" "pressing/$f"; echo "envoyé : $f"; done ;;
  migrate) deploy_migrate ;;
  run) deploy_run "${2:-}" "${3:-}" ;;
  check) check ;;
  all) deploy_code; deploy_migrate; deploy_run selftest; check ;;
  *) sed -n '2,9p' "$0"; exit 1 ;;
esac
