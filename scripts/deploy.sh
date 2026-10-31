#!/usr/bin/env bash
#
# نشر أبعاد على خادم الإنتاج — بحساب النشر، لا بحساب root.
#
# يُستدعى عبر مفتاحٍ مقيّدٍ في authorized_keys الخاصّ بحساب abaad-deploy:
#   command="/usr/bin/env bash /var/www/abaad/scripts/deploy.sh"
#
# ═══ لماذا لا root ═══
#
# شجرةُ الكود يملكها حسابُ النشر، وphp-fpm والطوابير وcron تعمل بحساب www-data.
# فلو جرى النشر بحساب root لكان root يقرأ وينفّذ — git، وتطبيقَ Laravel — في
# شجرةٍ يملكها غيرُه، فمن يملك ملفًّا فيها يوجّه أمرَ root. فصار:
#   - النشرُ كلُّه بحساب abaad-deploy: git، composer، npm، تبديل الأصول، الوسم.
#     وهو يملك الشجرة، فلا يحتاج sudo ولا safe.directory.
#   - أوامرُ Laravel وحدها تُبدَّل إلى www-data عبر sudo، فلا يشغّل حسابُ النشر
#     التطبيقَ أبدًا، ولا يكتب في ذاكرته (bootstrap/cache يملكها www-data).
#   - إعادةُ تحميل php-fpm وحدها عبر sudo بصلاحية root — قاعدةٌ واحدة محدّدة.
# قواعدُ sudo في deploy/abaad-deploy.sudoers، وقاعدتان لا ثالثة لهما.
#
# ولا يُفترض شيء: كل خطوة تُفحص، وأول فشلٍ يوقف الباقي ويطبع كيف تُرجِع.

set -euo pipefail

# ---------------------------------------------------------------------------
# يُنفَّذ من نسخةٍ في TMPDIR لا من مكانه في المستودع.
#
# bash يقرأ السكربت على دفعاتٍ أثناء تنفيذه، وهذا السكربت يسحب من git —
# فيستبدل الملفّ الذي يقرأ منه بينما هو في منتصفه. النتيجة سطرٌ يُقرأ من
# موضعٍ خاطئ: أمرٌ مبتور، أو شرطٌ لا يُغلق، أو خطوةٌ تُقفز. ولا يقع إلا حين
# يتغيّر السكربت نفسه — أي أندر الحالات وأسوأها وقتًا. والنسخةُ تحذف نفسَها.
# ---------------------------------------------------------------------------
if [[ "${DEPLOY_DETACHED:-}" != "1" ]]; then
    SELF="$(mktemp "${TMPDIR:-/tmp}/abaad-deploy.XXXXXX.sh")"
    cat "${BASH_SOURCE[0]}" > "$SELF"
    trap 'rm -f "$SELF"' EXIT
    DEPLOY_DETACHED=1 exec bash "$SELF" "$@"
fi

APP_DIR="${APP_DIR:-/var/www/abaad}"
APP_USER="${APP_USER:-www-data}"
DEPLOY_USER="${DEPLOY_USER:-abaad-deploy}"
BRANCH="${BRANCH:-main}"
# سجلُّ النشر في مكانٍ يملكه حسابُ النشر — لا /root الذي لا يكتب فيه. والنسخُ
# القديمة في /root/pre-deploy تبقى كما هي، لا يمسّها هذا السكربت
STATE_DIR="${STATE_DIR:-/var/log/abaad-deploy}"
HEALTH_URL="${HEALTH_URL:-https://app.abaadapp.om/health}"

# مساراتٌ مطلقة لتطابق قواعدَ sudo حرفًا بحرف — لا ما يلتقطه PATH
PHP_BIN="${PHP_BIN:-/usr/bin/php}"
SYSTEMCTL="${SYSTEMCTL:-/usr/bin/systemctl}"
FPM_SERVICE="${FPM_SERVICE:-php8.4-fpm}"

# حسابُ النشر يملك بيتَه، فمخابئُ npm وcomposer فيه — لا في /tmp المشترك حيث
# يستطيع www-data أن يزرع مخبأً مسمومًا يُقرأ وقتَ البناء
export npm_config_cache="${NPM_CACHE:-$HOME/.npm-cache}"

step()  { printf '\n\033[1m▸ %s\033[0m\n' "$*"; }
ok()    { printf '  \033[32m✓\033[0m %s\n' "$*"; }
die()   { printf '\n\033[31m✗ %s\033[0m\n' "$*" >&2; exit 1; }

# أوامرُ Laravel بحساب www-data — لا بحساب النشر. والمسارُ مطلقٌ ليطابق sudoers
art() { sudo -n -u "$APP_USER" "$PHP_BIN" "$APP_DIR/artisan" "$@"; }
# إعادةُ تحميل php-fpm وحدها بصلاحية root
reload_fpm() { sudo -n "$SYSTEMCTL" reload "$FPM_SERVICE"; }

# ═══ لا root ═══ النشرُ بحساب يملك الشجرة، لا بحساب يملك الخادم
[[ "$(id -u)" != 0 ]] \
    || die "لا تشغّله بحساب root — النشرُ يجري بحساب $DEPLOY_USER الذي يملك $APP_DIR. راجع DEPLOYMENT.md"
[[ -d "$APP_DIR" ]] || die "لا مشروع في $APP_DIR"
[[ -O "$APP_DIR/.git" ]] \
    || die "حساب النشر ($(id -un)) لا يملك $APP_DIR/.git — لا يُنشر في شجرةٍ يملكها غيرُه. راجع DEPLOYMENT.md"

cd "$APP_DIR"
# لا safe.directory: حسابُ النشر يملك الشجرة، فـgit لا يعترض. وبلا إضافةٍ إلى
# إعداد git العامّ — كانت تتراكم سطرًا كلَّ نشر، وتجعل root يثق بشجرة غيره
FROM_COMMIT="$(git rev-parse --short HEAD)"

# ---------------------------------------------------------------------------
# لا نسخةَ للقاعدة قبل النشر — قرارُ المالك (2026-10-31).
#
# والنسخُ المستقلُّ لكلّ متجر (`backup:run`) باقٍ كما هو. فالرجوعُ بعد نشرٍ
# سيّئ رجوعُ كودٍ وحده: يُرجع الكود، ولا يُرجع ما غيّرته الهجرات ولا ما كُتب بعده.
# ---------------------------------------------------------------------------
mkdir -p "$STATE_DIR"
STAMP="$(date +%Y%m%d-%H%M%S)"

printf 'commit قبل النشر: %s\nللرجوع (الكود وحده): bash %s/scripts/rollback-code.sh %s\nلا نسخةَ للقاعدة قبل هذا النشر: الرجوعُ بالكود لا يُرجع ما غيّرته الهجرات ولا ما كُتب بعد النشر.\n' \
    "$FROM_COMMIT" "$APP_DIR" "$FROM_COMMIT" > "$STATE_DIR/ROLLBACK.txt"

# ---------------------------------------------------------------------------
step "سحب $BRANCH"
# --force على الوسوم: الخادم يصنع وسمه محليًّا حين لا يجد رمز دفعٍ لـGitHub،
# فإذا دُفع الوسم نفسه من جهاز المطوّر اختلف الكائنان ورفض git السحب
# «would clobber existing tag». وبـset -e يموت النشر عند أوّل سطرٍ بعد
# الترويسة بلا رسالة — نشرٌ يتوقّف بصمت، وكلّ نشرةٍ بعده كذلك.
git fetch --tags --force --quiet origin
git pull --ff-only --quiet origin "$BRANCH"
TO_COMMIT="$(git rev-parse --short HEAD)"

if [[ "$FROM_COMMIT" == "$TO_COMMIT" ]]; then
    ok "لا جديد ($TO_COMMIT) — يُعاد البناء والذاكرة على كل حال"
else
    ok "$FROM_COMMIT → $TO_COMMIT"
    git --no-pager log --oneline "$FROM_COMMIT..$TO_COMMIT" | sed 's/^/    /'
fi

# ---------------------------------------------------------------------------
step "اعتماديات PHP"
# ‏--no-scripts: الأوامرُ الملحقة بـcomposer (post-autoload-dump) تشغّل
# `php artisan package:discover` — أي التطبيق. ولو جرت هنا لجرت بحساب النشر
# وكتبت bootstrap/cache بحسابه. فتُمنع، ويُعاد توليدُها بحساب www-data أدناه
composer install --no-dev --optimize-autoloader --no-interaction --no-scripts --quiet
# ما يفعله post-autoload-dump عادةً، لكن بحساب www-data: يكتب
# bootstrap/cache/{packages,services}.php بملكيّة www-data لا ملكيّة النشر
art package:discover --quiet
ok "composer (بلا سكربتات) · package:discover بحساب $APP_USER"

# ---------------------------------------------------------------------------
# البناء قبل الهجرة عمدًا: البناء أطول خطوة وأكثرها عرضةً للفشل (الذاكرة
# ٩٦١MB على هذا الخادم). فشلُه قبل الهجرة يترك القاعدة كما هي، وفشلُه بعدها
# يترك شكلًا جديدًا بواجهة قديمة.
#
# والبناء جانبًا ثمّ يُبدَّل — لا فوق المجلّد الحيّ: `vite build` يفرغ
# `public/build` قبل أن يملأه، فطوال البناء لا manifest وكلُّ طلبٍ يقع على
# «Vite manifest not found». فيُبنى في build.new ويُنقل في نقلتين سريعتين،
# ولو فشل بقي الحيُّ كما هو. والتبديلُ بحساب النشر — يملك public/
# ---------------------------------------------------------------------------
step "بناء الواجهة"
npm ci --no-audit --no-fund --silent
rm -rf public/build.new public/build.old
npm run build -- --outDir public/build.new --emptyOutDir 2>&1 | tail -3
[[ -f public/build.new/manifest.json ]] || { rm -rf public/build.new; die "البناء لم يُنتج manifest — لا تُكمل، والأصول الحيّة لم تُمسّ"; }

if [[ -d public/build ]]; then mv public/build public/build.old; fi
mv public/build.new public/build || { mv public/build.old public/build; die "فشل تبديل الأصول — أُعيد القديم"; }
rm -rf public/build.old
ok "الأصول (بُنيت جانبًا وبُدِّلت)"

# ---------------------------------------------------------------------------
step "الهجرات"
# تجري بحساب www-data، بلا نسخةٍ قبلها. ويُسمّى في السجلّ ما جرى منها، اسمًا
# وحالًا. والخرجُ كاملًا في ملفٍّ لا يقرؤه غيرُ مالكه: سجلُّ Deploy Main عامّ،
# وخطأُ قاعدةٍ في هجرةٍ سقطت قد يحمل بياناتِ زبون
MIGRATE_LOG="$STATE_DIR/migrate-$STAMP.log"
( umask 077; : > "$MIGRATE_LOG" )
MIGRATE_RC=0
art migrate --force > "$MIGRATE_LOG" 2>&1 || MIGRATE_RC=$?
MIGRATED="$(grep -E '^[[:space:]]*[0-9]{4}_[0-9]{2}_[0-9]{2}_[0-9]{6}_[^ ]+ .*(DONE|FAIL|SKIPPED)[[:space:]]*$' "$MIGRATE_LOG" \
    | sed -E 's/^[[:space:]]*([^ ]+) .*(DONE|FAIL|SKIPPED)[[:space:]]*$/\1  \2/' || true)"
[[ -z "$MIGRATED" ]] || sed 's/^/    /' <<<"$MIGRATED"

(( MIGRATE_RC == 0 )) || die "سقطت الهجرات — توقّف النشر قبل الذاكرة وإعادة تحميل PHP، فالموقعُ على كوده القديم.
ما جرى قبل السقوط يبقى في القاعدة، ولا نسخةَ تُرجعه. الخطأُ كاملًا على الخادم وحده: $MIGRATE_LOG"

if [[ -n "$MIGRATED" ]]; then
    printf '  \033[33m!\033[0m جرت بلا نسخةٍ قبلها: ما حذفته هجرةٌ أو أعادت تسميتَه أو حوّلته لا يُرجعه الرجوعُ بالكود، ونجاحُ CI لا يضمن سلامتها على بيانات الإنتاج\n'
elif grep -q 'Nothing to migrate' "$MIGRATE_LOG"; then
    ok "لا هجرةَ جديدة"
else
    printf '  \033[33m!\033[0m لم يُقرأ من خرج migrate ما جرى — انظر %s على الخادم\n' "$MIGRATE_LOG"
fi

# ---------------------------------------------------------------------------
step "الذاكرة المؤقتة"
art config:cache --quiet
art route:cache --quiet
art view:cache --quiet
reload_fpm
ok "config · route · view · php-fpm"

# ---------------------------------------------------------------------------
# الفحص بعد النشر لا قبله: preflight يقرأ الحالة الفعلية، والذاكرة المؤقتة
# لم تكن قد بُنيت بعد قبل قليل.
# ---------------------------------------------------------------------------
step "فحص ما بعد النشر"
set +e
art abaad:preflight
PREFLIGHT=$?
set -e

step "الموقع"
CODE="$(curl -s -o /dev/null -w '%{http_code}' --max-time 15 "$HEALTH_URL" || echo 000)"
if [[ "$CODE" == "200" ]]; then
    ok "$HEALTH_URL → 200"
else
    # كانت هنا `pg_restore -c` فوق القاعدة الحيّة: تمحو كلَّ طلبٍ ودفعةٍ وقيدٍ
    # بعد النسخة. والرجوعُ رجوعُ كود — ويرفض وحده إن جرت في هذا النشر هجرة
    die "الموقع يردّ $CODE — أرجِع الكود وحده، والقاعدةُ لا تُمسّ:
    bash $APP_DIR/scripts/rollback-code.sh $FROM_COMMIT
ثمّ أرجِع main نفسَه (Revert PR)، وإلّا أعاد النشرُ التالي الكودَ نفسه.
ولا نسخةَ للقاعدة قبل هذا النشر: ما غيّرته هجراتُه يبقى، ويُصلَح بهجرةٍ جديدة."
fi

# ---------------------------------------------------------------------------
# الترقيم عند النشر لا عند الدفع.
#
# الوسم يقول «هذا ما يعمل على الخادم»، وهذا لا يُعرف وقت الدفع إلى GitHub —
# قد يُدفع شيء ولا يُنشر شهرًا. فيُوسَم ما نُشر، حين يُنشر. ويُنشأ محلّيًّا على
# الخادم بحساب النشر (يملك .git)، ويُدفع من جهازٍ يملك اعتماد GitHub.
# ---------------------------------------------------------------------------
step "الوسم"
if EXISTING="$(git describe --exact-match --tags HEAD 2>/dev/null)"; then
    ok "موسوم أصلًا: $EXISTING"
    VERSION="$EXISTING"
else
    if [[ -z "${VERSION:-}" ]]; then
        # أعلى vX.Y موجود، ثم Y+1. ولترقيمٍ آخر: VERSION=v4.0 bash deploy.sh
        LATEST="$(git tag --list 'v[0-9]*' | sed 's/^v//' | sort -t. -k1,1n -k2,2n | tail -1)"
        MAJOR="${LATEST%%.*}"; MINOR="${LATEST#*.}"; MINOR="${MINOR%%.*}"
        VERSION="v${MAJOR:-0}.$(( ${MINOR:-0} + 1 ))"
    fi

    if git -c user.name='abaad-deploy' -c user.email='deploy@abaadapp.om' \
         tag -a "$VERSION" -m "نُشر على الإنتاج $(date +%Y-%m-%d\ %H:%M)" 2>/dev/null; then
        ok "$VERSION → $TO_COMMIT (محليًّا على الخادم)"
    else
        # النشر تمّ ونجح؛ تعذّر الوسم لا يُبطله ولا يُخفيه
        printf '  \033[33m!\033[0m تعذّر إنشاء الوسم %s — النشر تمّ على كل حال\n' "$VERSION"
    fi
fi

printf '%s\n%s\n%s\n' "$VERSION" "$TO_COMMIT" "$(date -Is)" > "$STATE_DIR/DEPLOYED.txt"

printf '\n\033[1mتمّ: %s → %s (%s)\033[0m\n' "$FROM_COMMIT" "$TO_COMMIT" "$VERSION"
printf 'لنشر الوسم من جهازك: \033[1mgit tag -a %s %s -m "نُشر على الإنتاج" && git push origin %s\033[0m\n' \
    "$VERSION" "$TO_COMMIT" "$VERSION"
[[ $PREFLIGHT -eq 0 ]] || printf '\033[33m! preflight يبلّغ عن موانع أعلاه — النشر تمّ، والموانع تبقى قرارك\033[0m\n'
printf 'للرجوع: %s\n' "$STATE_DIR/ROLLBACK.txt"
