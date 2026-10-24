#!/usr/bin/env bash
#
# رجوعُ الكود وحده إلى إيداعٍ سابق — والقاعدةُ لا تُمسّ.
#
#   sudo bash /var/www/abaad/scripts/rollback-code.sh <commit>
#   DRY_RUN=1 bash /var/www/abaad/scripts/rollback-code.sh <commit>   # يفحص ويقول ما سيفعل، ولا يفعل
#
# والإيداعُ المقصود في /root/pre-deploy/ROLLBACK.txt («commit قبل النشر»).
#
# ═══ لماذا الكود وحده ═══
#
# كلُّ ما جرى بعد النشر في القاعدة: طلباتٌ ودفعاتُ Paymob وقيودٌ وحركاتُ
# مخزونٍ ومستحقّاتُ بوتيك. واستعادةُ نسخةِ ما قبل النشر فوق القاعدة الحيّة
# (`pg_restore -c`) تمحوها كلَّها، وتُعيد عدّادات الفواتير فتتكرّر أرقامُها،
# ويبقى مالٌ حُصِّل بلا طلب. فالرجوعُ هنا رجوعُ كود، والنسخةُ تُفتح في قاعدةٍ
# منفصلة للمقارنة إن احتيج إليها — لا فوق `abaad`.
#
# ═══ ومتى يرفض ═══
#
#   - بين الحاليّ والهدف هجرة: الكودُ القديم على شكلٍ جديد قد لا يعمل،
#     و`migrate:rollback` يعتمد على down() وقد يحذف عمودًا بما فيه. ذاك قرارٌ
#     يُدرس، لا سطرٌ يُنفَّذ في عجلة.
#   - في الشجرة تعديلٌ غير مودَع: `reset --hard` يمحوه.
#   - الإيداعُ غير معروف هنا.
#
# ═══ وبعده ═══
#
# أرجِع main نفسَه (Revert PR) — وإلّا سحب النشرُ التالي main كما هو وأعاد
# الكودَ الذي رجعتَ عنه.

set -euo pipefail

# ينفَّذ من نسخةٍ في /tmp — السببُ نفسُه في deploy.sh: `reset --hard` يبدّل
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
BACKUP_DIR="${BACKUP_DIR:-/root/pre-deploy}"
HEALTH_URL="${HEALTH_URL:-https://app.abaadapp.om/health}"
DRY_RUN="${DRY_RUN:-0}"
NPM_ENV=(env HOME=/tmp npm_config_cache=/tmp/npm-cache)

step()  { printf '\n\033[1m▸ %s\033[0m\n' "$*"; }
ok()    { printf '  \033[32m✓\033[0m %s\n' "$*"; }
die()   { printf '\n\033[31m✗ %s\033[0m\n' "$*" >&2; exit 1; }

as_app() { sudo -u "$APP_USER" "$@"; }

TARGET="${1:-}"
[[ -n "$TARGET" ]] || die "اذكر الإيداعَ الذي يُرجَع إليه — «commit قبل النشر» في $BACKUP_DIR/ROLLBACK.txt"
[[ "$DRY_RUN" == "1" || $EUID -eq 0 ]] || die "شغّله بـsudo: يحتاج التبديل إلى $APP_USER وإعادة تحميل php-fpm"
[[ -d "$APP_DIR" ]] || die "لا مشروع في $APP_DIR"

cd "$APP_DIR"
G=(git -c safe.directory="$APP_DIR")

"${G[@]}" cat-file -e "$TARGET^{commit}" 2>/dev/null || die "لا إيداعَ «$TARGET» في $APP_DIR — git fetch أوّلًا"
FROM="$("${G[@]}" rev-parse --short HEAD)"
TO="$("${G[@]}" rev-parse --short "$TARGET^{commit}")"
[[ "$FROM" != "$TO" ]] || die "الكودُ على $TO أصلًا — لا شيء يُرجَع"

[[ -z "$("${G[@]}" status --porcelain --untracked-files=no)" ]] \
    || die "في الشجرة تعديلٌ غير مودَع — \`reset --hard\` يمحوه. انظر: git status"

MIGRATIONS="$("${G[@]}" diff --name-only "$TO" "$FROM" -- database/migrations)"
if [[ -n "$MIGRATIONS" ]]; then
    die "بين $TO و$FROM هجرات — الكودُ القديم قد لا يعمل على الشكل الجديد، والشكلُ لا يُرجَع بأمر:
$(sed 's/^/    /' <<<"$MIGRATIONS")
توقّف هنا. ولا تستعِد نسخةً فوق القاعدة الحيّة: تمحو كلَّ ما بِيع بعدها."
fi
ok "لا هجرةَ بين $TO و$FROM — الرجوعُ بالكود وحده كامل، والقاعدةُ لا تُمسّ"

if [[ "$DRY_RUN" == "1" ]]; then
    printf '\nتجربةٌ جافّة — لن يُنفَّذ شيء. لو شُغّل لفعل بالترتيب:\n'
    printf '  ١. git reset --hard %s   (الكود: %s → %s)\n' "$TO" "$FROM" "$TO"
    printf '  ٢. composer install --no-dev\n'
    printf '  ٣. بناء الواجهة في public/build.new ثمّ تبديلها بالحيّة\n'
    printf '  ٤. config:cache · route:cache · view:cache · إعادة تحميل php-fpm · queue:restart\n'
    printf '  ٥. فحص %s\n' "$HEALTH_URL"
    printf 'والقاعدةُ لا تُمسّ: لا استعادةَ نسخة، ولا هجرة، ولا رجوعَ هجرة.\n'
    exit 0
fi

# ---------------------------------------------------------------------------
step "الكود: $FROM → $TO"
mkdir -p "$BACKUP_DIR"
printf '%s  رجوعُ الكود: %s → %s\n' "$(date -Is)" "$FROM" "$TO" >> "$BACKUP_DIR/ROLLBACK.log"
as_app git -c safe.directory="$APP_DIR" reset --hard --quiet "$TO"
ok "$("${G[@]}" log --oneline -1)"

step "اعتماديات PHP"
as_app composer install --no-dev --optimize-autoloader --no-interaction --quiet
ok "composer"

# الواجهةُ ليست في git: بلا بنائها يعمل JS الإصدارِ المتروك على PHP القديم.
# وتُبنى جانبًا ثمّ تُبدَّل كما في deploy.sh — لا فوق المجلّد الحيّ
step "بناء الواجهة"
as_app "${NPM_ENV[@]}" npm ci --no-audit --no-fund --silent
rm -rf public/build.new public/build.old
as_app "${NPM_ENV[@]}" npm run build -- --outDir public/build.new --emptyOutDir 2>&1 | tail -3
[[ -f public/build.new/manifest.json ]] || { rm -rf public/build.new; die "البناء لم يُنتج manifest — الأصولُ الحيّة لم تُمسّ، والكودُ صار $TO: أعِد المحاولة أو ارجع إلى $FROM بالأمر نفسه"; }
if [[ -d public/build ]]; then mv public/build public/build.old; fi
mv public/build.new public/build || { mv public/build.old public/build; die "فشل تبديل الأصول — أُعيد القديم"; }
rm -rf public/build.old
ok "الأصول (بُنيت جانبًا وبُدِّلت)"

# كلُّ أمرٍ وحده: `config:cache route:cache` في سطرٍ واحد يُرفض أمرًا بوسائطَ زائدة
step "الذاكرة المؤقتة"
as_app php artisan config:cache --quiet
as_app php artisan route:cache --quiet
as_app php artisan view:cache --quiet
systemctl reload php8.4-fpm
# العاملُ يُعاد كلَّ ساعة لا عند النشر — فيُطلب منه أن يُعيد نفسَه بعد مهمّته الجارية
as_app php artisan queue:restart --quiet || true
ok "config · route · view · php-fpm · queue"

step "الموقع"
CODE="$(curl -s -o /dev/null -w '%{http_code}' --max-time 15 "$HEALTH_URL" || echo 000)"
[[ "$CODE" == "200" ]] || die "الموقع يردّ $CODE بعد الرجوع إلى $TO. لا تستعِد القاعدة؛ انظر سجلَّ storage/logs، أو عُد إلى $FROM:
    sudo bash $APP_DIR/scripts/rollback-code.sh $FROM"
ok "$HEALTH_URL → 200"

printf '\n\033[1mرجع الكود: %s → %s — والقاعدة كما هي.\033[0m\n' "$FROM" "$TO"
printf '\033[33mالآن أرجِع main نفسَه (Revert PR)، وإلّا أعاد النشرُ التالي %s.\033[0m\n' "$FROM"
