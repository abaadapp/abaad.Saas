#!/usr/bin/env bash
#
# نشر أبعاد على خادم الإنتاج.
#
#   sudo bash /var/www/abaad/scripts/deploy.sh
#
# كُتب من الخطوات التي نُفِّذت يدويًّا يوم ٩ أغسطس ٢٠٢٦، بما فيها العطب الذي
# ظهر أثناءها: npm ينهار لأن بيت www-data هو /var/www وهو غير قابل للكتابة.
# حُلَّ يومها بحيلةٍ في الرأس؛ وهذا الملفّ هو أن تُكتب الحيلة مرّةً واحدة.
#
# ولا يُفترض شيء: كل خطوة تُفحص، وأول فشلٍ يوقف الباقي ويطبع كيف تُرجِع.

set -euo pipefail

APP_DIR="${APP_DIR:-/var/www/abaad}"
APP_USER="${APP_USER:-www-data}"
BRANCH="${BRANCH:-main}"
BACKUP_DIR="${BACKUP_DIR:-/root/pre-deploy}"
HEALTH_URL="${HEALTH_URL:-https://app.abaadapp.om/health}"

# npm يكتب في $HOME، وبيت www-data غير قابل للكتابة: بلا هذا ينهار
# `npm ci` بخطأٍ عن /var/www/.npm/_logs لا عن سببه الحقيقي
NPM_ENV=(env HOME=/tmp npm_config_cache=/tmp/npm-cache)

step()  { printf '\n\033[1m▸ %s\033[0m\n' "$*"; }
ok()    { printf '  \033[32m✓\033[0m %s\n' "$*"; }
die()   { printf '\n\033[31m✗ %s\033[0m\n' "$*" >&2; exit 1; }

as_app() { sudo -u "$APP_USER" "$@"; }

[[ $EUID -eq 0 ]] || die "شغّله بـsudo: يحتاج التبديل إلى $APP_USER وإعادة تحميل php-fpm"
[[ -d "$APP_DIR" ]] || die "لا مشروع في $APP_DIR"

cd "$APP_DIR"
FROM_COMMIT="$(git -c safe.directory="$APP_DIR" rev-parse --short HEAD)"

# ---------------------------------------------------------------------------
# النسخة أولًا — قبل أي لمسة.
#
# الهجرات تُغيّر الشكل ولا تُرجَع بأمر: `migrate:rollback` يعتمد على down()
# وقد لا تكون صحيحة، وقد تكون البيانات ضاعت قبلها. النسخة هي طريق الرجوع
# الوحيد المضمون.
# ---------------------------------------------------------------------------
step "نسخة القاعدة قبل النشر"
mkdir -p "$BACKUP_DIR"
STAMP="$(date +%Y%m%d-%H%M%S)"
DUMP="$BACKUP_DIR/abaad-$STAMP.dump"

if [[ "$(as_app php artisan tinker --execute='echo config("database.default");' 2>/dev/null | tail -1)" == "pgsql" ]] \
   || grep -q '^DB_CONNECTION=pgsql' .env; then
    sudo -u postgres pg_dump -Fc "$(grep '^DB_DATABASE=' .env | cut -d= -f2)" > "$DUMP"
else
    cp "$(grep '^DB_DATABASE=' .env | cut -d= -f2)" "$DUMP" 2>/dev/null || die "تعذّرت نسخة القاعدة"
fi

printf 'commit قبل النشر: %s\nالنسخة: %s\n' "$FROM_COMMIT" "$DUMP" > "$BACKUP_DIR/ROLLBACK.txt"
ok "$(du -h "$DUMP" | cut -f1) → $DUMP"

# ---------------------------------------------------------------------------
step "سحب $BRANCH"
git config --global --add safe.directory "$APP_DIR" 2>/dev/null || true
as_app git fetch --tags --quiet origin
as_app git pull --ff-only --quiet origin "$BRANCH"
TO_COMMIT="$(git rev-parse --short HEAD)"

if [[ "$FROM_COMMIT" == "$TO_COMMIT" ]]; then
    ok "لا جديد ($TO_COMMIT) — يُعاد البناء والذاكرة على كل حال"
else
    ok "$FROM_COMMIT → $TO_COMMIT"
    git --no-pager log --oneline "$FROM_COMMIT..$TO_COMMIT" | sed 's/^/    /'
fi

# ---------------------------------------------------------------------------
step "اعتماديات PHP"
as_app composer install --no-dev --optimize-autoloader --no-interaction --quiet
ok "composer"

# ---------------------------------------------------------------------------
# البناء قبل الهجرة عمدًا: البناء أطول خطوة وأكثرها عرضةً للفشل (الذاكرة
# ٩٦١MB على هذا الخادم). فشلُه قبل الهجرة يترك القاعدة كما هي، وفشلُه بعدها
# يترك شكلًا جديدًا بواجهة قديمة.
# ---------------------------------------------------------------------------
step "بناء الواجهة"
as_app "${NPM_ENV[@]}" npm ci --no-audit --no-fund --silent
as_app "${NPM_ENV[@]}" npm run build 2>&1 | tail -3
[[ -f public/build/manifest.json ]] || die "البناء لم يُنتج manifest — لا تُكمل"
ok "الأصول"

# ---------------------------------------------------------------------------
step "الهجرات"
as_app php artisan migrate --force 2>&1 | tail -4

# ---------------------------------------------------------------------------
step "الذاكرة المؤقتة"
as_app php artisan config:cache --quiet
as_app php artisan route:cache --quiet
as_app php artisan view:cache --quiet
systemctl reload php8.4-fpm
ok "config · route · view · php-fpm"

# ---------------------------------------------------------------------------
# الفحص بعد النشر لا قبله: preflight يقرأ الحالة الفعلية، والذاكرة المؤقتة
# لم تكن قد بُنيت بعد قبل قليل.
# ---------------------------------------------------------------------------
step "فحص ما بعد النشر"
set +e
as_app php artisan abaad:preflight
PREFLIGHT=$?
set -e

step "الموقع"
CODE="$(curl -s -o /dev/null -w '%{http_code}' --max-time 15 "$HEALTH_URL" || echo 000)"
if [[ "$CODE" == "200" ]]; then
    ok "$HEALTH_URL → 200"
else
    die "الموقع يردّ $CODE — أرجِع فورًا:
    cd $APP_DIR && sudo -u $APP_USER git reset --hard $FROM_COMMIT
    sudo -u postgres pg_restore -c -d abaad $DUMP
    sudo -u $APP_USER php artisan config:cache route:cache view:cache
    systemctl reload php8.4-fpm"
fi

printf '\n\033[1mتمّ: %s → %s\033[0m\n' "$FROM_COMMIT" "$TO_COMMIT"
[[ $PREFLIGHT -eq 0 ]] || printf '\033[33m! preflight يبلّغ عن موانع أعلاه — النشر تمّ، والموانع تبقى قرارك\033[0m\n'
printf 'للرجوع: %s\n' "$BACKUP_DIR/ROLLBACK.txt"
