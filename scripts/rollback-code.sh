#!/usr/bin/env bash
#
# رجوعُ الكود وحده إلى إيداعٍ سابق — والقاعدةُ لا تُمسّ. بحساب النشر لا root.
#
#   bash /var/www/abaad/scripts/rollback-code.sh <commit>
#   DRY_RUN=1 bash /var/www/abaad/scripts/rollback-code.sh <commit>   # يفحص ويقول ما سيفعل، ولا يفعل
#
# والإيداعُ المقصود في /var/log/abaad-deploy/ROLLBACK.txt («commit قبل النشر»).
#
# ═══ لماذا الكود وحده ═══
#
# كلُّ ما جرى بعد النشر في القاعدة: طلباتٌ ودفعاتُ Paymob وقيودٌ وحركاتُ
# مخزونٍ ومستحقّاتُ بوتيك. واستعادةُ نسخةٍ فوق القاعدة الحيّة (`pg_restore -c`)
# تمحوها كلَّها، وتُعيد عدّادات الفواتير فتتكرّر أرقامُها، ويبقى مالٌ حُصِّل بلا
# طلب. فالرجوعُ هنا رجوعُ كود — لا فوق `abaad`.
#
# ═══ ولماذا لا root ═══
#
# كـdeploy.sh: الشجرةُ يملكها حسابُ النشر، فالرجوعُ بحسابه — git وcomposer
# وnpm وتبديل الأصول — بلا sudo ولا safe.directory. وأوامرُ Laravel بحساب
# www-data عبر sudo، وإعادةُ تحميل php-fpm وحدها بصلاحية root.
#
# ═══ ومتى يرفض ═══
#
#   - بين الحاليّ والهدف هجرة: الكودُ القديم على شكلٍ جديد قد لا يعمل،
#     و`migrate:rollback` يعتمد على down() وقد يحذف عمودًا بما فيه. ذاك قرارٌ
#     يُدرس، لا سطرٌ يُنفَّذ في عجلة. ومن درسها فوجدها إضافيّةً يسمّيها:
#       ALLOW_MIGRATIONS="2026_..._x.php" bash /var/www/abaad/scripts/rollback-code.sh <commit>
#   - في الشجرة تعديلٌ غير مودَع: `reset --hard` يمحوه.
#   - الإيداعُ غير معروف هنا.
#
# ═══ وبعده ═══
#
# أرجِع main نفسَه (Revert PR) — وإلّا سحب النشرُ التالي main كما هو وأعاد
# الكودَ الذي رجعتَ عنه.

set -euo pipefail

# ينفَّذ من نسخةٍ في TMPDIR — السببُ نفسُه في deploy.sh: `reset --hard` يبدّل
# الملفَّ الذي يقرأ منه bash، وقد يمحوه إن كان الهدفُ أقدمَ منه
if [[ "${ROLLBACK_DETACHED:-}" != "1" ]]; then
    SELF="$(mktemp "${TMPDIR:-/tmp}/abaad-rollback.XXXXXX")"
    cat "${BASH_SOURCE[0]}" > "$SELF"
    ROLLBACK_DETACHED=1 exec bash "$SELF" "$@"
fi
# والنسخةُ تحذف نفسَها حين تنتهي، نجحت أو فشلت
ME="${BASH_SOURCE[0]}"
[[ "$(basename "$ME")" == abaad-rollback.* ]] && trap 'rm -f "$ME"' EXIT

APP_DIR="${APP_DIR:-/var/www/abaad}"
APP_USER="${APP_USER:-www-data}"
DEPLOY_USER="${DEPLOY_USER:-abaad-deploy}"
STATE_DIR="${STATE_DIR:-/var/log/abaad-deploy}"
HEALTH_URL="${HEALTH_URL:-https://app.abaadapp.om/health}"
DRY_RUN="${DRY_RUN:-0}"

PHP_BIN="${PHP_BIN:-/usr/bin/php}"
SYSTEMCTL="${SYSTEMCTL:-/usr/bin/systemctl}"
FPM_SERVICE="${FPM_SERVICE:-php8.4-fpm}"
export npm_config_cache="${NPM_CACHE:-$HOME/.npm-cache}"

step()  { printf '\n\033[1m▸ %s\033[0m\n' "$*"; }
ok()    { printf '  \033[32m✓\033[0m %s\n' "$*"; }
die()   { printf '\n\033[31m✗ %s\033[0m\n' "$*" >&2; exit 1; }

art() { sudo -n -u "$APP_USER" "$PHP_BIN" "$APP_DIR/artisan" "$@"; }
reload_fpm() { sudo -n "$SYSTEMCTL" reload "$FPM_SERVICE"; }

TARGET="${1:-}"
[[ -n "$TARGET" ]] || die "اذكر الإيداعَ الذي يُرجَع إليه — «commit قبل النشر» في $STATE_DIR/ROLLBACK.txt"
# ═══ لا root ═══ كـdeploy.sh. والتجربةُ الجافّة لا تبدّل شيئًا، فتُعفى
[[ "$DRY_RUN" == "1" || "$(id -u)" != 0 ]] \
    || die "لا تشغّله بحساب root — الرجوعُ يجري بحساب $DEPLOY_USER الذي يملك $APP_DIR. راجع DEPLOYMENT.md"
[[ -d "$APP_DIR" ]] || die "لا مشروع في $APP_DIR"
[[ "$DRY_RUN" == "1" || -O "$APP_DIR/.git" ]] \
    || die "حساب الرجوع ($(id -un)) لا يملك $APP_DIR/.git. راجع DEPLOYMENT.md"

cd "$APP_DIR"

git cat-file -e "$TARGET^{commit}" 2>/dev/null || die "لا إيداعَ «$TARGET» في $APP_DIR — git fetch أوّلًا"
FROM="$(git rev-parse --short HEAD)"
TO="$(git rev-parse --short "$TARGET^{commit}")"
[[ "$FROM" != "$TO" ]] || die "الكودُ على $TO أصلًا — لا شيء يُرجَع"

[[ -z "$(git status --porcelain --untracked-files=no)" ]] \
    || die "في الشجرة تعديلٌ غير مودَع — \`reset --hard\` يمحوه. انظر: git status"

MIGRATIONS="$(git diff --name-only "$TO" "$FROM" -- database/migrations)"
# وما سمّاه ALLOW_MIGRATIONS بعد دراسته يُعبَر — وكلُّ ما لم يُسمَّ يردّ الرجوعَ كلَّه
UNLISTED=""
while IFS= read -r m; do
    [[ -z "$m" ]] && continue
    [[ " ${ALLOW_MIGRATIONS:-} " == *" $(basename "$m") "* ]] || UNLISTED+="$m"$'\n'
done <<<"$MIGRATIONS"
if [[ -n "$UNLISTED" ]]; then
    die "بين $TO و$FROM هجرات — الكودُ القديم قد لا يعمل على الشكل الجديد، والشكلُ لا يُرجَع بأمر:
$(sed '/^$/d; s/^/    /' <<<"$UNLISTED")
توقّف هنا. ولا تستعِد نسخةً فوق القاعدة الحيّة: تمحو كلَّ ما بِيع بعدها.
وإن درستَها فوجدتَها إضافيّةً لا يقرؤها الكودُ القديم، فسمِّها في ALLOW_MIGRATIONS وأعِد الأمر."
fi
if [[ -n "$MIGRATIONS" ]]; then
    ok "هجراتٌ بين $TO و$FROM سمّاها ALLOW_MIGRATIONS — تبقى جداولُها وبياناتُها، ولا down():
$(sed 's/^/    /' <<<"$MIGRATIONS")"
else
    ok "لا هجرةَ بين $TO و$FROM — الرجوعُ بالكود وحده كامل، والقاعدةُ لا تُمسّ"
fi

if [[ "$DRY_RUN" == "1" ]]; then
    printf '\nتجربةٌ جافّة — لن يُنفَّذ شيء. لو شُغّل لفعل بالترتيب:\n'
    printf '  ١. git reset --hard %s   (الكود: %s → %s)\n' "$TO" "$FROM" "$TO"
    printf '  ٢. composer install --no-dev --no-scripts ثمّ package:discover بحساب %s\n' "$APP_USER"
    printf '  ٣. بناء الواجهة في public/build.new ثمّ تبديلها بالحيّة\n'
    printf '  ٤. config:cache · route:cache · view:cache (بحساب %s) · إعادة تحميل php-fpm · queue:restart\n' "$APP_USER"
    printf '  ٥. فحص %s\n' "$HEALTH_URL"
    printf 'والقاعدةُ لا تُمسّ: لا استعادةَ نسخة، ولا هجرة، ولا رجوعَ هجرة.\n'
    exit 0
fi

# ---------------------------------------------------------------------------
step "الكود: $FROM → $TO"
mkdir -p "$STATE_DIR"
printf '%s  رجوعُ الكود: %s → %s\n' "$(date -Is)" "$FROM" "$TO" >> "$STATE_DIR/ROLLBACK.log"
git reset --hard --quiet "$TO"
ok "$(git log --oneline -1)"

step "اعتماديات PHP"
composer install --no-dev --optimize-autoloader --no-interaction --no-scripts --quiet
art package:discover --quiet
ok "composer (بلا سكربتات) · package:discover بحساب $APP_USER"

# الواجهةُ ليست في git: بلا بنائها يعمل JS الإصدارِ المتروك على PHP القديم.
# وتُبنى جانبًا ثمّ تُبدَّل كما في deploy.sh — لا فوق المجلّد الحيّ
step "بناء الواجهة"
npm ci --no-audit --no-fund --silent
rm -rf public/build.new public/build.old
npm run build -- --outDir public/build.new --emptyOutDir 2>&1 | tail -3
[[ -f public/build.new/manifest.json ]] || { rm -rf public/build.new; die "البناء لم يُنتج manifest — الأصولُ الحيّة لم تُمسّ، والكودُ صار $TO: أعِد المحاولة أو ارجع إلى $FROM بالأمر نفسه"; }
if [[ -d public/build ]]; then mv public/build public/build.old; fi
mv public/build.new public/build || { mv public/build.old public/build; die "فشل تبديل الأصول — أُعيد القديم"; }
rm -rf public/build.old
ok "الأصول (بُنيت جانبًا وبُدِّلت)"

step "الذاكرة المؤقتة"
art config:cache --quiet
art route:cache --quiet
art view:cache --quiet
reload_fpm
# العاملُ يُعاد كلَّ ساعة لا عند النشر — فيُطلب منه أن يُعيد نفسَه بعد مهمّته الجارية
art queue:restart --quiet || true
ok "config · route · view · php-fpm · queue"

step "الموقع"
CODE="$(curl -s -o /dev/null -w '%{http_code}' --max-time 15 "$HEALTH_URL" || echo 000)"
[[ "$CODE" == "200" ]] || die "الموقع يردّ $CODE بعد الرجوع إلى $TO. لا تستعِد القاعدة؛ انظر سجلَّ storage/logs، أو عُد إلى $FROM:
    bash $APP_DIR/scripts/rollback-code.sh $FROM"
ok "$HEALTH_URL → 200"

printf '\n\033[1mرجع الكود: %s → %s — والقاعدة كما هي.\033[0m\n' "$FROM" "$TO"
printf '\033[33mالآن أرجِع main نفسَه (Revert PR)، وإلّا أعاد النشرُ التالي %s.\033[0m\n' "$FROM"
