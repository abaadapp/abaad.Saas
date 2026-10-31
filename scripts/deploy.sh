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

# ---------------------------------------------------------------------------
# يُنفَّذ من نسخةٍ في /tmp لا من مكانه في المستودع.
#
# bash يقرأ السكربت على دفعاتٍ أثناء تنفيذه، وهذا السكربت يسحب من git —
# فيستبدل الملفّ الذي يقرأ منه بينما هو في منتصفه. النتيجة سطرٌ يُقرأ من
# موضعٍ خاطئ: أمرٌ مبتور، أو شرطٌ لا يُغلق، أو خطوةٌ تُقفز. ولا يقع إلا حين
# يتغيّر السكربت نفسه — أي أندر الحالات وأسوأها وقتًا.
# ---------------------------------------------------------------------------
if [[ "${DEPLOY_DETACHED:-}" != "1" ]]; then
    SELF="$(mktemp /tmp/abaad-deploy.XXXXXX.sh)"
    cat "${BASH_SOURCE[0]}" > "$SELF"
    trap 'rm -f "$SELF"' EXIT
    DEPLOY_DETACHED=1 exec bash "$SELF" "$@"
fi

APP_DIR="${APP_DIR:-/var/www/abaad}"
APP_USER="${APP_USER:-www-data}"
BRANCH="${BRANCH:-main}"
BACKUP_DIR="${BACKUP_DIR:-/root/pre-deploy}"
HEALTH_URL="${HEALTH_URL:-https://app.abaadapp.om/health}"
# إعدادُ النشر على الخادم — خارج المستودع، فلا تغيّره دفعةٌ إلى GitHub. انظر «النسخة» أدناه.
# ومسارُه ثابتٌ لا متغيّرُ بيئة: ما يُطفئ النسخةَ لا يُمرَّر مع الأمر
DEPLOY_CONF=/etc/abaad/deploy.conf

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
# النسخة أولًا — قبل أي لمسة. إلّا أن يكتب المالكُ: لا نسخة.
#
# الهجرات تُغيّر الشكل ولا تُرجَع بأمر: `migrate:rollback` يعتمد على down()
# وقد لا تكون صحيحة، وقد تكون البيانات ضاعت قبلها. النسخة هي طريق الرجوع
# الوحيد المضمون.
#
# ═══ وإطفاؤها سطرٌ مكتوبٌ على الخادم، لا متغيّرٌ يُمرَّر ═══
#
# `PRE_DEPLOY_BACKUP=off` في $DEPLOY_CONF. والملفّ خارج المستودع: دفعةٌ إلى
# main لا تُطفئ النسخة، ومفتاحُ النشر المقيّد لا يُمرّر متغيّرًا أصلًا.
#
#   - لا ملفّ، أو لا سطر، أو `on`: النسخة كما كانت.
#   - `off`: لا نسخة — ويُقال ذلك هنا، وفي ROLLBACK.txt، وفي آخر السجلّ.
#     والخطواتُ الأخرى كلُّها كما هي. إلّا هجرةً لم تجرِ: توقفه (انظر أدناه).
#   - غيرُهما، أو السطرُ مرّتين، أو ملفٌّ لا يملكه من ينشر أو يكتبه غيرُ
#     مالكه، أو مجلّدُه كذلك، أو رابطٌ رمزيّ: يتوقّف النشر هنا، قبل أي لمسة.
#     لا يُخمَّن: «of» ليست «off».
# ---------------------------------------------------------------------------
step "نسخة القاعدة قبل النشر"
BACKUP_MODE=on
CONF_DIR="$(dirname "$DEPLOY_CONF")"
# المالكُ والصلاحيّةُ بالثمانيّ: GNU stat على الخادم، وBSD في المختبر
owner_of() { stat -c %u "$1" 2>/dev/null || stat -f %u "$1"; }
mode_of()  { stat -c %a "$1" 2>/dev/null || stat -f %Lp "$1"; }

# الرابطُ يُرفض ولا يُتبَع: يُشير إلى ملفٍّ يكتبه غيرُ root، أو إلى لا شيء
if [[ -L "$DEPLOY_CONF" || -L "$CONF_DIR" ]]; then
    die "$DEPLOY_CONF أو مجلّدُه رابطٌ رمزيّ — يُقرأ ملفٌّ حقيقيّ وحده"
fi
if [[ -e "$DEPLOY_CONF" ]]; then
    [[ -f "$DEPLOY_CONF" ]] || die "$DEPLOY_CONF ليس ملفًّا عاديًّا"
    # والمجلّدُ كالملفّ: من يكتب في المجلّد يستبدل الملفَّ بملفِّه
    for CONF_PATH in "$CONF_DIR" "$DEPLOY_CONF"; do
        [[ "$(owner_of "$CONF_PATH")" == "$EUID" ]] \
            || die "$CONF_PATH لا يملكه من ينشر (المالك $(owner_of "$CONF_PATH")) — لا يُقرأ إعدادُ نسخةٍ كتبه غيرُه"
        (( (8#$(mode_of "$CONF_PATH") & 8#022) == 0 )) \
            || die "$CONF_PATH يكتبه غيرُ مالكه ($(mode_of "$CONF_PATH")) — chmod go-w ثمّ أعد النشر"
    done

    CONF_LINES="$(grep -cE '^[[:space:]]*PRE_DEPLOY_BACKUP[[:space:]]*=' "$DEPLOY_CONF" || true)"
    (( CONF_LINES <= 1 )) || die "PRE_DEPLOY_BACKUP مكتوبٌ $CONF_LINES مرّات في $DEPLOY_CONF — سطرٌ واحدٌ يُقرأ"
    if (( CONF_LINES == 1 )); then
        CONF_VALUE="$(grep -E '^[[:space:]]*PRE_DEPLOY_BACKUP[[:space:]]*=' "$DEPLOY_CONF" | cut -d= -f2- | tr -d '[:space:]')"
        case "$CONF_VALUE" in
            on)  BACKUP_MODE=on ;;
            off) BACKUP_MODE=off ;;
            *)   die "PRE_DEPLOY_BACKUP=«$CONF_VALUE» في $DEPLOY_CONF — on أو off وحدهما" ;;
        esac
    fi
fi

# ---------------------------------------------------------------------------
# وبلا نسخةٍ لا تجري هجرة — والسؤالُ للقاعدة لا لـgit.
#
# `git diff` يرى ما تغيّر في هذا النشر وحده. وهجرةٌ في الشجرة لم تجرِ قطّ —
# نشرٌ سقط في منتصف هجراته، أو ملفٌّ دخل ولم يُهاجَر — لا تظهر فيه، ثمّ
# يُجريها `migrate` بلا نسخة. فالمعلَّقُ هو ملفّاتُ database/migrations في
# الشجرة ناقصًا جدولَ migrations في القاعدة نفسها.
#
# ويُسأل مرّتين: هنا عن رأس main قبل السحب، فلا يتحرّك شيء؛ وبعد composer
# عمّا سُحب فعلًا — دمجٌ وصل بينهما، أو حزمةٌ تسجّل هجراتٍ من خارج
# database/migrations فلا يُحكم عليها هنا. وما لا يُقرأ من القاعدة يوقف
# النشر، ولا يُطبع خطؤه: سجلُّ Deploy Main عامّ.
# ---------------------------------------------------------------------------
PENDING=""
pending_in() {  # $1 = commit؛ يملأ PENDING
    local out ran
    out="$(as_app php artisan tinker --execute='$m = app("migrator"); echo "<<ran", PHP_EOL; foreach ($m->paths() as $p) { echo "path:", $p, PHP_EOL; } foreach ($m->getRepository()->getRan() as $n) { echo $n, PHP_EOL; } echo "ran>>", PHP_EOL;' 2>&1 || true)"
    grep -qx 'ran>>' <<<"$out" \
        || die "تعذّر أن تُقرأ من القاعدة الهجراتُ التي جرت — والنسخةُ مطفأة، فلا يُنشر على تخمين. على الخادم: php artisan migrate:status"
    ran="$(sed -n '/^<<ran$/,/^ran>>$/p' <<<"$out" | sed '1d;$d')"
    if grep -q '^path:' <<<"$ran"; then
        die "حزمةٌ تسجّل هجراتٍ من خارج database/migrations — لا يُحكم عليها والنسخةُ مطفأة. PRE_DEPLOY_BACKUP=on لهذا النشر"
    fi
    PENDING="$(LC_ALL=C comm -23 \
        <(git -c safe.directory="$APP_DIR" ls-tree --name-only "$1" database/migrations/ \
            | sed -n 's|^database/migrations/\(.*_.*\)\.php$|\1|p' | LC_ALL=C sort -u) \
        <(grep -v '^$' <<<"$ran" | LC_ALL=C sort -u))"
}

mkdir -p "$BACKUP_DIR"
STAMP="$(date +%Y%m%d-%H%M%S)"

if [[ "$BACKUP_MODE" == "off" ]]; then
    DUMP=""
    printf '  \033[33m!\033[0m لا نسخة لهذا النشر: PRE_DEPLOY_BACKUP=off في %s (عُدّل %s) — والرجوعُ رجوعُ كودٍ وحده\n' \
        "$DEPLOY_CONF" "$(date -r "$DEPLOY_CONF" '+%Y-%m-%d %H:%M' 2>/dev/null || echo '?')"
else
    DUMP="$BACKUP_DIR/abaad-$STAMP.dump"

    if [[ "$(as_app php artisan tinker --execute='echo config("database.default");' 2>/dev/null | tail -1)" == "pgsql" ]] \
       || grep -q '^DB_CONNECTION=pgsql' .env; then
        sudo -u postgres pg_dump -Fc "$(grep '^DB_DATABASE=' .env | cut -d= -f2)" > "$DUMP"
    else
        cp "$(grep '^DB_DATABASE=' .env | cut -d= -f2)" "$DUMP" 2>/dev/null || die "تعذّرت نسخة القاعدة"
    fi
fi

# الرجوعُ رجوعُ كود: النسخةُ لا تُستعاد فوق القاعدة الحيّة — تمحو كلَّ ما بِيع
# بعدها، وتُعيد عدّادات الفواتير. تُفتح في قاعدةٍ منفصلة للمقارنة إن احتيج
printf 'commit قبل النشر: %s\nالنسخة: %s\nللرجوع (الكود وحده): sudo bash %s/scripts/rollback-code.sh %s\nلا تستعِد النسخة فوق القاعدة الحيّة — تمحو كلَّ ما بِيع بعدها. تُفتح في قاعدةٍ منفصلة للمقارنة فقط.\n' \
    "$FROM_COMMIT" "${DUMP:-لا نسخة لهذا النشر (PRE_DEPLOY_BACKUP=off في $DEPLOY_CONF)}" "$APP_DIR" "$FROM_COMMIT" > "$BACKUP_DIR/ROLLBACK.txt"
[[ -z "$DUMP" ]] || ok "$(du -h "$DUMP" | cut -f1) → $DUMP"

if [[ "$BACKUP_MODE" == "off" ]]; then
    step "هجراتٌ لم تجرِ؟ — والنسخةُ مطفأة"
    as_app git fetch --quiet origin "$BRANCH"
    TARGET="$(git -c safe.directory="$APP_DIR" rev-parse --short 'FETCH_HEAD^{commit}')"
    pending_in "$TARGET"
    [[ -z "$PENDING" ]] || die "هجراتٌ لم تجرِ على القاعدة، والنسخةُ مطفأة (PRE_DEPLOY_BACKUP=off في $DEPLOY_CONF):
$(sed 's/^/    /' <<<"$PENDING")
لم يتحرّك شيء: الكودُ على $FROM_COMMIT، ولا بناءَ ولا هجرة.
راجِع هذه الهجرات، ثمّ اكتب PRE_DEPLOY_BACKUP=on وأعِد النشر — تُؤخذ نسخةٌ ثمّ تجري. وبعده أعِد off."
    ok "لا هجرةَ معلّقة: القاعدةُ على كلّ هجرات $TARGET"
fi

# ---------------------------------------------------------------------------
step "سحب $BRANCH"
git config --global --add safe.directory "$APP_DIR" 2>/dev/null || true
# --force على الوسوم: الخادم يصنع وسمه محليًّا حين لا يجد رمز دفعٍ لـGitHub،
# فإذا دُفع الوسم نفسه من جهاز المطوّر اختلف الكائنان ورفض git السحب
# «would clobber existing tag». وبـset -e يموت النشر عند أوّل سطرٍ بعد
# الترويسة بلا رسالة — نشرٌ يتوقّف بصمت، وكلّ نشرةٍ بعده كذلك.
as_app git fetch --tags --force --quiet origin
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

# والسؤالُ ثانيةً عمّا سُحب فعلًا، بكوده واعتمادياته — قبل البناء والهجرة
if [[ "$BACKUP_MODE" == "off" ]]; then
    pending_in HEAD
    [[ -z "$PENDING" ]] || die "سُحب ما لم يُسأل عنه — هجراتٌ لم تجرِ والنسخةُ مطفأة:
$(sed 's/^/    /' <<<"$PENDING")
لم تجرِ هجرةٌ ولم يُبنَ شيء، ولم يُعَد تحميلُ PHP. اكتب PRE_DEPLOY_BACKUP=on وأعِد النشر، أو أرجِع الكود بما في $BACKUP_DIR/ROLLBACK.txt."
    ok "لا هجرةَ معلّقة فيما سُحب ($TO_COMMIT)"
fi

# ---------------------------------------------------------------------------
# البناء قبل الهجرة عمدًا: البناء أطول خطوة وأكثرها عرضةً للفشل (الذاكرة
# ٩٦١MB على هذا الخادم). فشلُه قبل الهجرة يترك القاعدة كما هي، وفشلُه بعدها
# يترك شكلًا جديدًا بواجهة قديمة.
# ---------------------------------------------------------------------------
# ---------------------------------------------------------------------------
# البناء جانبًا ثمّ يُبدَّل — لا فوق المجلّد الحيّ.
#
# `vite build` يفرغ `public/build` قبل أن يملأه. فطوال البناء — دقيقةً على
# هذا الخادم — لا manifest، وكل طلبٍ يقع على استثناء «Vite manifest not
# found» فيرى الزبون صفحة ٥٠٠. وهي أخطارُ ٢١ أغسطس في السجلّ حرفيًّا.
#
# فيُبنى في `build.new` ويُنقل في نقلتين تستغرقان أجزاءً من الثانية. ولو
# فشل البناء بقي المجلّد الحيّ كما هو ولم يُمسّ.
# ---------------------------------------------------------------------------
step "بناء الواجهة"
as_app "${NPM_ENV[@]}" npm ci --no-audit --no-fund --silent
rm -rf public/build.new public/build.old
as_app "${NPM_ENV[@]}" npm run build -- --outDir public/build.new --emptyOutDir 2>&1 | tail -3
[[ -f public/build.new/manifest.json ]] || { rm -rf public/build.new; die "البناء لم يُنتج manifest — لا تُكمل، والأصول الحيّة لم تُمسّ"; }

# التبديل: القديم يُزاح ثمّ يحلّ الجديد. والقديم يبقى حتى ينجح النقل الثاني
if [[ -d public/build ]]; then mv public/build public/build.old; fi
mv public/build.new public/build || { mv public/build.old public/build; die "فشل تبديل الأصول — أُعيد القديم"; }
rm -rf public/build.old
ok "الأصول (بُنيت جانبًا وبُدِّلت)"

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
    # كانت هنا `pg_restore -c` فوق القاعدة الحيّة: تمحو كلَّ طلبٍ ودفعةٍ وقيدٍ
    # بعد النسخة. والرجوعُ رجوعُ كود — ويرفض وحده إن جرت في هذا النشر هجرة
    if [[ -n "$DUMP" ]]; then
        NO_RESTORE="ولا تستعِد $DUMP فوق القاعدة الحيّة — تمحو كلَّ ما بِيع بعدها."
    else
        NO_RESTORE="ولا نسخةَ لهذا النشر (PRE_DEPLOY_BACKUP=off): ما غيّرته هجراتُه يبقى، ويُصلَح بهجرةٍ لا بنسخة."
    fi
    die "الموقع يردّ $CODE — أرجِع الكود وحده، والقاعدةُ لا تُمسّ:
    sudo bash $APP_DIR/scripts/rollback-code.sh $FROM_COMMIT
ثمّ أرجِع main نفسَه (Revert PR)، وإلّا أعاد النشرُ التالي الكودَ نفسه.
$NO_RESTORE"
fi

# ---------------------------------------------------------------------------
# الترقيم عند النشر لا عند الدفع.
#
# الوسم يقول «هذا ما يعمل على الخادم»، وهذا لا يُعرف وقت الدفع إلى GitHub —
# قد يُدفع شيء ولا يُنشر شهرًا. فيُوسَم ما نُشر، حين يُنشر.
#
# ويُنشأ محلّيًّا فقط: الخادم لا يملك اعتماداتِ دفعٍ إلى GitHub (والسحب يعمل
# لأن المستودع عام). ووضعُ رمزٍ بصلاحية كتابة على خادمٍ يواجه الإنترنت ثمنٌ
# أكبر من راحة سطرٍ واحد — فيُطبع الأمر ليُنشر من جهازٍ يملك الصلاحية.
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

    # الهوية في الأمر نفسه لا على الخادم: الوسم الموثَّق يحتاج اسمًا وبريدًا،
    # ولا هوية لـwww-data — فكان يفشل بـ«Committer identity unknown»
    if as_app git -c user.name='abaad-deploy' -c user.email='deploy@abaadapp.om' \
         tag -a "$VERSION" -m "نُشر على الإنتاج $(date +%Y-%m-%d\ %H:%M)" 2>/dev/null; then
        ok "$VERSION → $TO_COMMIT (محليًّا على الخادم)"
    else
        # النشر تمّ ونجح؛ تعذّر الوسم لا يُبطله ولا يُخفيه
        printf '  \033[33m!\033[0m تعذّر إنشاء الوسم %s — النشر تمّ على كل حال\n' "$VERSION"
    fi
fi

printf '%s\n%s\n%s\n' "$VERSION" "$TO_COMMIT" "$(date -Is)" > "$BACKUP_DIR/DEPLOYED.txt"

printf '\n\033[1mتمّ: %s → %s (%s)\033[0m\n' "$FROM_COMMIT" "$TO_COMMIT" "$VERSION"
printf 'لنشر الوسم من جهازك: \033[1mgit tag -a %s %s -m "نُشر على الإنتاج" && git push origin %s\033[0m\n' \
    "$VERSION" "$TO_COMMIT" "$VERSION"
[[ $PREFLIGHT -eq 0 ]] || printf '\033[33m! preflight يبلّغ عن موانع أعلاه — النشر تمّ، والموانع تبقى قرارك\033[0m\n'
[[ -n "$DUMP" ]] || printf '\033[33m! نُشر بلا نسخة: PRE_DEPLOY_BACKUP=off في %s\033[0m\n' "$DEPLOY_CONF"
printf 'للرجوع: %s\n' "$BACKUP_DIR/ROLLBACK.txt"
