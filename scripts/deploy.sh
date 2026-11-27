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

# والخطوةُ الجاريةُ تُحفظ: إن سقط النشرُ قيل أين (`on_exit`)
step()  { CURRENT_STEP="$*"; printf '\n\033[1m▸ %s\033[0m\n' "$*"; }
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
mkdir -p "$STATE_DIR"

# ---------------------------------------------------------------------------
# نشرٌ واحدٌ في كلّ مرّة — ولا رجوعٌ فوقه.
#
# «Deploy Main» يصفّ نشراته في GitHub (`concurrency: deploy-production`)، لكنّ
# الخادم لا يعرف ذلك: نشرٌ يدويٌّ أو `rollback-code.sh` أثناء نشرٍ جارٍ يسحبان
# الشجرةَ نفسها، ويكتبان ROLLBACK.txt فوق بعضهما، ويُطلق أحدُهما طابورَ الآخر
# ومهامَّه المجدولة. فيُمسك قفلٌ قبل أيّ قراءةٍ أو لمسة، والثاني يخرج بلا أن
# يلمس شيئًا. وهو قفلُ النواة (flock): يُفلت وحده إن مات السكربتُ بأيّ طريقة —
# لا ملفَّ قفلٍ يبقى بعد نشرٍ قُتل فيمنع ما بعده.
#
# والوصفُ 9 يرثه كلُّ ما يُشغَّل من هنا، فيُغلق لما قد يعيش بعد النشر: git يترك
# `gc --auto` في الخلفية، ولو ورث القفلَ لردّ النشرَ التالي وهو لا يجري.
# (و`sudo` يغلق ما فوق 2 بنفسه.)
# ---------------------------------------------------------------------------
command -v flock > /dev/null || die "flock غير موجود (util-linux) — لا يُنشر بلا قفلٍ يمنع نشرين معًا"
# ثوانٍ لا لحظة: التطبيقُ يسأل القفلَ بقفلٍ مشتركٍ يُفلت في الحال (`DeployState::deployRunning`)
# ليعرف أيجري نشر — فلا يردّ نشرًا وافق سؤالَه. ونشرٌ جارٍ حقًّا يُمسكه دقائق، فيُردّ الثاني
DEPLOY_LOCK_WAIT="${DEPLOY_LOCK_WAIT:-5}"
exec 9>> "$STATE_DIR/deploy.lock"
flock -w "$DEPLOY_LOCK_WAIT" 9 || die "نشرٌ أو رجوعٌ آخر يجري الآن على $APP_DIR — لم يُلمس شيء. انتظره ثمّ أعِد.
    $(cat "$STATE_DIR/deploy.lock.who" 2>/dev/null || true)"
git()      { command git "$@" 9>&-; }
composer() { command composer "$@" 9>&-; }
npm()      { command npm "$@" 9>&-; }

# لا safe.directory: حسابُ النشر يملك الشجرة، فـgit لا يعترض. وبلا إضافةٍ إلى
# إعداد git العامّ — كانت تتراكم سطرًا كلَّ نشر، وتجعل root يثق بشجرة غيره
FROM_COMMIT="$(git rev-parse --short HEAD)"
printf 'نشرٌ من %s · pid %s · منذ %s\n' "$FROM_COMMIT" "$$" "$(date '+%Y-%m-%dT%H:%M:%S%z')" > "$STATE_DIR/deploy.lock.who"

# ---------------------------------------------------------------------------
# لا نسخةَ للقاعدة قبل النشر — قرارُ المالك (2026-10-31).
#
# والنسخُ المستقلُّ لكلّ متجر (`backup:run`) باقٍ كما هو. فالرجوعُ بعد نشرٍ
# سيّئ رجوعُ كودٍ وحده: يُرجع الكود، ولا يُرجع ما غيّرته الهجرات ولا ما كُتب بعده.
# ---------------------------------------------------------------------------
STAMP="$(date +%Y%m%d-%H%M%S)"

printf 'commit قبل النشر: %s\nللرجوع (الكود وحده): bash %s/scripts/rollback-code.sh %s\nلا نسخةَ للقاعدة قبل هذا النشر: الرجوعُ بالكود لا يُرجع ما غيّرته الهجرات ولا ما كُتب بعد النشر.\n' \
    "$FROM_COMMIT" "$APP_DIR" "$FROM_COMMIT" > "$STATE_DIR/ROLLBACK.txt"

# ---------------------------------------------------------------------------
# الطابورُ يقف قبل أن يتبدّل الكود، ويُطلَق بعد أن يُعاد عاملُه على الكود الجديد.
#
# العاملُ عمليّةٌ طويلةُ العمر: كان يبقى بالكود القديم حتى يُعاد كلَّ ساعة
# (`--max-time=3600`)، ويسحب المهامَّ طوالَ النشر — وكودُ القرص جديدٌ والقاعدةُ
# قديمة، ثمّ ساعةً بعده بكودٍ قديمٍ على قاعدةٍ جديدة.
#
#   - `queue:pause` لا يقطع شيئًا: المهمّةُ الجارية تُكمل، والعاملُ لا يسحب
#     جديدًا. فلا تُعاد مهمّةٌ قُطعت، ولا تخرج رسالةٌ مرّتين.
#   - `queue:restart` بعد الهجرات والذاكرة وإعادة تحميل PHP: يُفحص بين المهامّ
#     لا في أثنائها — مخرجُ `--max-time` نفسُه — ويعيده systemd بالكود الجديد.
#   - ثمّ انتظارُ دورةِ نومٍ أو اثنتين يخرج فيها القديم، ثمّ `queue:resume`.
#
# وإن سقط النشرُ في أيّ خطوةٍ أُطلق الطابورُ كما كان، بلا إعادة: يبقى العاملُ
# على كوده القديم كما يبقى الويب (opcache) حتى يكتمل نشرٌ — لا موقوفًا بلا أحد.
# وإن لم تُثبَّت قاعدةُ sudo للإيقاف بعد على الخادم قيل ذلك، ومضى النشرُ كما كان.
# ---------------------------------------------------------------------------
QUEUE_RESTART_WAIT="${QUEUE_RESTART_WAIT:-10}"
# وبعد الإيقاف يُنتظر العاملُ حتى لا تبقى مهمّةٌ تجري — لا ثوانيَ معدودة — ثمّ يتبدّل
# الكود: المهمّةُ الجارية تُكمل على الكود الذي بدأت عليه، لا تحمّل ملفًّا جديدًا في
# منتصفها. يُسأل التطبيقُ (`abaad:deploy-state`، قراءةً بحساب www-data) كلَّ ثانيتين،
# حتى QUEUE_IDLE_TIMEOUT. فإن بقيت تجري وقف النشرُ قبل أن يلمس الكود، وأُطلق الطابور،
# وسُمّيت المهمّة. وإن لم تُثبَّت قاعدةُ السؤال بعد أُمهلت QUEUE_DRAIN_WAIT كما كان، وقيل ذلك.
QUEUE_DRAIN_WAIT="${QUEUE_DRAIN_WAIT:-15}"
QUEUE_IDLE_TIMEOUT="${QUEUE_IDLE_TIMEOUT:-600}"
QUEUE_IDLE_POLL="${QUEUE_IDLE_POLL:-2}"
STATE_OUT="$STATE_DIR/state-$STAMP.txt"
( umask 077; : > "$STATE_OUT" )
QUEUE_LOG="$STATE_DIR/queue-$STAMP.log"
( umask 077; : > "$QUEUE_LOG" )
PAUSED_WHATSAPP=0
PAUSED_DEFAULT=0
RELEASED=0
CURRENT_STEP="إيقاف الطابور"

# أتبيح قاعدةُ sudo هذا الأمرَ؟ — يُسأل sudo (`-l`) ولا يُجرى شيء. فلا يُوقف طابورٌ
# لا يُطلق: ركّب نصفَ القاعدة (الإيقافَ بلا الإطلاق) فيبقى موقوفًا بلا رسائل
may() { sudo -n -u "$APP_USER" -l "$PHP_BIN" "$APP_DIR/artisan" "$@" > /dev/null 2>&1; }

# وسؤالُ التطبيق عن حاله: المهامُّ الجارية والهجراتُ التي لم تجرِ — قراءةٌ لا كتابة
STATE_OK=0
may abaad:deploy-state && STATE_OK=1

# هجراتٌ لم تجرِ كما تقولها القاعدة — عددُها، أو فراغٌ إن لم يُعرف (فيُقدَّر من git)
db_pending() {
    (( STATE_OK == 1 )) || return 0
    art abaad:deploy-state > "$STATE_OUT" 2>&1 || true
    sed -n 's/^pending-migrations: \([0-9][0-9]*\)$/\1/p' "$STATE_OUT" | head -1
}

# ينتظر حتى لا تبقى مهمّةٌ تجري على الطابورين — أو يقف قبل أن يلمس الكود
wait_for_idle() {
    local waited=0 rc
    CURRENT_STEP="انتظار المهمّة الجارية قبل تبديل الكود"
    while :; do
        rc=0
        art abaad:deploy-state > "$STATE_OUT" 2>&1 || rc=$?
        case "$rc" in
            0)
                ok "لا مهمّةَ تجري على الطابور (انتُظرت ${waited} ثانية) — يتبدّل الكودُ والعاملُ لا يحمل شيئًا"
                return 0
                ;;
            3)
                if (( waited >= QUEUE_IDLE_TIMEOUT )); then
                    die "ما زالت مهمّةٌ تجري على الطابور بعد ${waited} ثانية — وقف النشرُ قبل أن يلمس الكود، ويُطلق الطابورُ كما كان:
$(grep '^job: ' "$STATE_OUT" | sed 's/^job: /  /' || true)
أعِد النشرَ بعد أن تنتهي، أو ارفع QUEUE_IDLE_TIMEOUT إن كانت المهمّةُ طويلةً بطبعها."
                fi
                if (( waited == 0 )); then
                    printf '  مهمّةٌ تجري — ينتظرها النشرُ قبل أن يتبدّل الكود (حتى %s ثانية):\n' "$QUEUE_IDLE_TIMEOUT"
                    grep '^job: ' "$STATE_OUT" | sed 's/^job: /    /' || true
                fi
                sleep "$QUEUE_IDLE_POLL"
                waited=$(( waited + QUEUE_IDLE_POLL ))
                ;;
            *)
                printf '::warning::تعذّر سؤالُ التطبيق عن المهامّ الجارية — أُمهلت %s ثانيةً كما كان\n' "$QUEUE_DRAIN_WAIT"
                printf '  \033[33m!\033[0m تعذّر سؤالُ التطبيق عن المهامّ الجارية (انظر %s) — أُمهلت المهمّةُ الجارية %s ثانيةً كما كان\n' "$STATE_OUT" "$QUEUE_DRAIN_WAIT"
                sleep "$QUEUE_DRAIN_WAIT"
                return 0
                ;;
        esac
    done
}

# ---------------------------------------------------------------------------
# والمهامُّ المجدولة تقف ما دام الكودُ يتبدّل (`App\Support\DeployHold`).
#
# cron يشغّل `schedule:run` كلَّ دقيقة بحساب www-data، وكلُّ تشغيلٍ عمليّةٌ جديدة
# تقرأ القرصَ كما هو: من السحب حتى تنتهي الهجراتُ والذاكرة تجد كودًا جديدًا على
# قاعدةٍ قديمة. فيُكتب `.deploy-hold` في جذر الشجرة (يملكها حسابُ النشر، ويقرؤها
# www-data)، فيتخطّى التطبيقُ كلَّ مهمّةٍ حان وقتها ويسجّل اسمها. بلا sudo، وبلا
# تعديلٍ لـcron. ويُحذف بعد الذاكرة وإعادة تحميل PHP.
#
# وعند السقوط يُحذف إن كان الكودُ على القرص يجري على قاعدته (السحبُ لم يتمّ، أو
# لا هجرةَ جديدة، أو تمّت الهجرات). وإلّا بقي — كودٌ جديدٌ ينتظر هجراتٍ لم تجرِ —
# حتى يكتمل نشرٌ أو يُرجَع بالكود، ويُعلَن ذلك. ولا يبقى أبدًا: ملفٌّ أقدمُ من نصف
# ساعةٍ يتجاهله التطبيقُ ويسجّله خطأً.
# ---------------------------------------------------------------------------
HOLD_FILE="$APP_DIR/.deploy-hold"
# هجراتٌ تركها نشرٌ سقط قبلها — تُذكر حتى تجري، فلا تُطلق إعادةٌ ساقطةٌ المهامَّ عليها
PENDING_FILE="$STATE_DIR/MIGRATIONS_PENDING"
HELD=0
MIGRATED_OK=0
MIGRATE_RC=0

hold_schedule() {
    printf '%s · نشرٌ من %s · pid %s · %s\n' "$1" "$FROM_COMMIT" "$$" "$(date '+%Y-%m-%dT%H:%M:%S%z')" > "$HOLD_FILE" 2> /dev/null
}

# أكودُ القرص الآن ينتظر هجراتٍ لم تجرِ؟
migrations_pending() {
    (( MIGRATED_OK == 0 )) || return 1
    # القاعدةُ تقول: أيُّ هجرةٍ على القرص لم تجرِ — وإن لم تُسأل قُدِّر من git
    local n
    n="$(db_pending)"
    if [[ -n "$n" ]]; then
        (( n > 0 ))
        return
    fi
    [[ ! -e "$PENDING_FILE" ]] || return 0
    (( MIGRATE_RC == 0 )) || return 0
    local added
    added="$(git diff --name-only --diff-filter=A "$FROM_COMMIT" HEAD -- database/migrations)" || return 0
    [[ -n "$added" ]]
}

release_queue() {
    (( RELEASED == 0 )) || return 0
    RELEASED=1
    local failed=0
    if (( PAUSED_WHATSAPP == 1 )); then
        art queue:resume whatsapp >> "$QUEUE_LOG" 2>&1 || failed=1
    fi
    if (( PAUSED_DEFAULT == 1 )); then
        art queue:resume default >> "$QUEUE_LOG" 2>&1 || failed=1
    fi
    return "$failed"
}

# ---------------------------------------------------------------------------
# حالُ الخادم بعد سقوط النشر — تُطبع في سجلّ Deploy Main (وتُنسخ إلى ملخّص التشغيل)،
# وتُكتب في STATE_DIR/DEPLOY-FAILED.txt فتبقى بعد السجلّ. تقول ما الحالُ لا ما يُظنّ:
# الكودُ على القرص، والويب، والهجراتُ (ما جرى وما لم يجرِ)، والطابور، والمهامُّ المجدولة،
# ثمّ الخطواتُ الآمنة. ونجاحُ نشرٍ بعدها ينقله إلى DEPLOY-FAILED.resolved-*.
# ---------------------------------------------------------------------------
FAILED_FILE="$STATE_DIR/DEPLOY-FAILED.txt"
FPM_RELOADED=0
QUEUE_STATE="لم يُوقف (قاعدةُ الإيقاف غيرُ مثبّتة أو لم يُبلَغ الإيقاف)"
recovery_block() {
    local head pending done_list n
    head="$(git -C "$APP_DIR" rev-parse --short HEAD 2>/dev/null || echo '?')"
    done_list="$(grep -E ' DONE[[:space:]]*$' "${MIGRATE_LOG:-/dev/null}" 2>/dev/null | awk '{print $1}' || true)"
    n="$(db_pending)"
    if [[ -n "$n" ]]; then
        pending="$n (من القاعدة)$(awk '/^migration: /{printf "\n      %s", $2}' "$STATE_OUT" 2>/dev/null || true)"
    else
        pending="$(git -C "$APP_DIR" diff --name-only --diff-filter=A "$FROM_COMMIT" HEAD -- database/migrations 2>/dev/null | xargs -n1 basename 2>/dev/null | sed 's/\.php$//' | grep -vxF -f <(printf '%s\n' "$done_list") || true)"
        pending="${pending:-لا شيء ظاهر} (تقديرٌ من git — قاعدةُ السؤال غيرُ مثبّتة)"
    fi
    printf '=== حال الخادم بعد سقوط النشر ===\n'
    printf 'سقط عند: %s\n' "$CURRENT_STEP"
    printf 'الكود على القرص: %s (كان %s)\n' "$head" "$FROM_COMMIT"
    if (( FPM_RELOADED == 1 )); then
        printf 'الويب: على الكود الجديد (أُعيد تحميل php-fpm)\n'
    else
        printf 'الويب: على ما حُمّل قبل النشر (لم يُعَد تحميل php-fpm)\n'
    fi
    printf 'هجراتٌ جرت في هذا النشر: %s\n' "${done_list:-لا شيء}"
    printf 'هجراتٌ لم تجرِ: %s\n' "$pending"
    printf 'الطابور: %s\n' "$QUEUE_STATE"
    if (( HELD == 1 )); then
        printf 'المهامّ المجدولة: موقوفة (%s) — لا يُطلقها الوقتُ ما دامت هجراتٌ لم تجرِ\n' "$HOLD_FILE"
    else
        printf 'المهامّ المجدولة: تجري\n'
    fi
    printf 'الخطوات الآمنة (بالترتيب):\n'
    printf '  1. اقرأ السببَ أعلاه في سجلّ Deploy Main، وخرجَ الهجرات على الخادم: %s\n' "${MIGRATE_LOG:-—}"
    printf '  2. إن كان السببُ عابرًا (قفل، شبكة): أعِد تشغيلَ Deploy Main — يُكمل الهجراتِ من حيث وقفت، ويُطلق المهامّ في آخره.\n'
    printf '  3. وإلّا أرجِع الكودَ وحده: bash %s/scripts/rollback-code.sh %s — يرفض إن جرت في هذا النشر هجرة؛ والقاعدةُ لا تُستعاد.\n' "$APP_DIR" "$FROM_COMMIT"
    printf '  4. ثمّ أرجِع main نفسَه (Revert PR)، وإلّا أعاد النشرُ التالي الكودَ نفسه.\n'
    if [[ "$QUEUE_STATE" == موقوف* ]]; then
        printf '  5. الطابورُ موقوف — أطلقه بحساب %s: %s %s/artisan queue:resume whatsapp ثمّ default (القاعدتان في sudoers)\n' "$APP_USER" "$PHP_BIN" "$APP_DIR"
    fi
    printf '=== نهاية حال الخادم ===\n'
}

# أيُّ سقوطٍ — `die` أو أمرٌ يُسقطه `set -e` — يمرّ من هنا: يُقال أين، ويُطلق الطابور
on_exit() {
    local rc=$?
    if (( rc != 0 )); then
        printf '\n\033[31m✗ سقط النشرُ عند «%s»\033[0m — الكودُ على القرص %s (كان %s). الويبُ على ما حُمّل قبل إعادة تحميل PHP، وما جرى من هجراتٍ قبل السقوط باقٍ.\n  للمتابعة: أعِد النشر (يُكمل الهجرات من حيث وقفت). وللرجوع بالكود: %s/ROLLBACK.txt\n' \
            "$CURRENT_STEP" "$(git -C "$APP_DIR" rev-parse --short HEAD 2>/dev/null || echo '?')" "$FROM_COMMIT" "$STATE_DIR" >&2
    fi
    if (( RELEASED == 0 && PAUSED_WHATSAPP + PAUSED_DEFAULT > 0 )); then
        if release_queue; then
            QUEUE_STATE="أُطلق كما كان — العاملُ على كوده القديم ولم يُعَد"
            printf '  أُطلق الطابور كما كان — العاملُ على كوده القديم ولم يُعَد\n' >&2
        else
            QUEUE_STATE="موقوف — تعذّر إطلاقُه (الخرجُ في $QUEUE_LOG)"
            printf '\033[31m  ✗ تعذّر إطلاقُ الطابور — هو موقوف. أطلقه بحساب %s: %s %s/artisan queue:resume whatsapp (ثمّ default)، والخرجُ في %s\033[0m\n' \
                "$APP_USER" "$PHP_BIN" "$APP_DIR" "$QUEUE_LOG" >&2
        fi
    fi
    if (( HELD == 1 )); then
        if migrations_pending; then
            git rev-parse --short HEAD > "$PENDING_FILE" 2> /dev/null || true
            hold_schedule "أُبقي بعد سقوط النشر عند «$CURRENT_STEP»" || true
            printf '::warning::تبقى المهامّ المجدولة موقوفة: الكودُ الجديد على القرص وهجراتُه لم تجرِ\n'
            printf '\033[33m  ! تبقى المهامّ المجدولة موقوفة — الكودُ الجديد على القرص ينتظر هجراتٍ لم تجرِ، وتشغيلُها الآن يجري الجديدَ على القاعدة القديمة.\n    تُطلق حين يكتمل نشر، أو بالرجوع بالكود (ROLLBACK.txt). ولا يُطلقها الوقتُ وحده: التطبيقُ يُبقيها ما دامت هجراتٌ لم تجرِ. الملفّ: %s\033[0m\n' "$HOLD_FILE" >&2
        elif rm -f "$HOLD_FILE"; then
            HELD=0
            printf '  أُطلقت المهامّ المجدولة — الكودُ على القرص يجري على قاعدته\n' >&2
        else
            printf '\033[31m  ✗ تعذّر حذفُ %s — المهامّ المجدولة موقوفة حتى يُحذف (أو حتى يجد التطبيقُ بعد نصف ساعة الكودَ على قاعدته)\033[0m\n' "$HOLD_FILE" >&2
        fi
    fi
    if (( rc != 0 )); then
        printf '::error title=سقط النشر::عند «%s» — حالُ الخادم والخطواتُ الآمنة في ملخّص التشغيل وفي %s على الخادم\n' "$CURRENT_STEP" "$FAILED_FILE"
        ( umask 077; recovery_block > "$FAILED_FILE" 2> /dev/null ) || true
        if [[ -s "$FAILED_FILE" ]]; then cat "$FAILED_FILE"; else recovery_block; fi
    fi
    # والنسخةُ التي يجري منها السكربت (أعلاه) تحذف نفسَها هنا: `exec` أسقط فخَّها هناك
    [[ "$(basename "${BASH_SOURCE[0]}")" != abaad-deploy.*.sh ]] || rm -f "${BASH_SOURCE[0]}"
}
trap on_exit EXIT

step "إيقاف المهامّ المجدولة والطابور قبل تبديل الكود"
if hold_schedule "نشر"; then
    HELD=1
    ok "المهامّ المجدولة: يتخطّاها التطبيقُ حتى تنتهي الهجراتُ والذاكرة ($HOLD_FILE)"
else
    printf '::warning::لم يُكتب ملفُّ إيقاف المهامّ المجدولة — تجري أثناء النشر كما كانت\n'
    printf '  \033[33m!\033[0m تعذّرت كتابةُ %s — تجري المهامّ المجدولة أثناء النشر كما كانت\n' "$HOLD_FILE"
fi
if may queue:pause whatsapp && may queue:pause default && may queue:resume whatsapp && may queue:resume default; then
    art queue:pause whatsapp >> "$QUEUE_LOG" 2>&1 && PAUSED_WHATSAPP=1 || true
    art queue:pause default >> "$QUEUE_LOG" 2>&1 && PAUSED_DEFAULT=1 || true
    if (( PAUSED_WHATSAPP == 1 && PAUSED_DEFAULT == 1 )); then
        QUEUE_STATE="موقوف — يُطلق في آخر النشر"
        ok "واتساب والافتراضيّ: لا يُسحب منهما جديدٌ حتى يُعاد العامل"
        if (( STATE_OK == 1 )); then
            wait_for_idle
        else
            printf '::warning::قاعدةُ سؤال التطبيق عن المهامّ الجارية غيرُ مثبّتة — أُمهلت المهمّةُ الجارية %s ثانيةً فقط\n' "$QUEUE_DRAIN_WAIT"
            printf '  \033[33m!\033[0m قاعدةُ abaad:deploy-state غيرُ مثبّتة في sudoers — لا يُعرف أانتهت المهمّةُ الجارية: أُمهلت %s ثانيةً كما كان\n' "$QUEUE_DRAIN_WAIT"
            sleep "$QUEUE_DRAIN_WAIT"
            ok "أُمهلت المهمّةُ الجارية ${QUEUE_DRAIN_WAIT} ثانيةً تُكمل فيها على الكود الذي بدأت عليه"
        fi
    else
        # نصفُ إيقافٍ لا يُترك: يُطلق ما أُوقف، ويمضي النشرُ كما كان
        release_queue || true
        PAUSED_WHATSAPP=0
        PAUSED_DEFAULT=0
        printf '  \033[33m!\033[0m لم يُوقف الطابور (انظر %s) — يمضي النشرُ كما كان: يعمل العاملُ أثناءه\n' "$QUEUE_LOG"
    fi
else
    # قاعدةُ main القديمة (قبل تركيب deploy/abaad-deploy.sudoers الجديد) لا تبيح الإيقافَ
    # ولا الإطلاق: لا يُجرَّب ما سيُردّ، ولا يسقط النشر — يمضي كما كان، والإعادةُ وحدها تجري
    printf '::warning::قاعدةُ إيقاف الطابور غيرُ مثبّتة على الخادم — لم يُوقف الطابور أثناء النشر\n'
    printf '  \033[33m!\033[0m قاعدةُ إيقاف الطابور غيرُ مثبّتة في sudoers (deploy/abaad-deploy.sudoers) — لم يُوقف الطابور، ويمضي النشرُ كما كان: يعمل العاملُ أثناءه، ويُعاد في آخره\n'
fi

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
#
# وكلُّ هجرةٍ تنتظر قفلَها ثلاثَ ثوانٍ ثمّ تسقط (`App\Support\MigrationLockTimeout`)
# بالخطأ 55P03، وما قبلها باقٍ. فتُعاد `migrate` مرّاتٍ محدودة لهذا الخطأ وحده،
# فتُكمل الهجراتُ من حيث وقفت — ولا تقف بيعةٌ خلف هجرةٍ تنتظر تقريرًا طويلًا.
# وأيُّ خطأٍ آخر يوقف النشرَ من أوّل مرّة. والخرجُ كلُّه في الملفّ نفسِه.
MIGRATE_TRIES="${MIGRATE_TRIES:-5}"
MIGRATE_RETRY_AFTER="${MIGRATE_RETRY_AFTER:-15}"
MIGRATE_LOCKED=0
for (( try = 1; ; try++ )); do
    MIGRATE_RC=0
    SEEN="$(wc -c < "$MIGRATE_LOG")"
    art migrate --force >> "$MIGRATE_LOG" 2>&1 || MIGRATE_RC=$?
    (( MIGRATE_RC != 0 )) || break
    MIGRATE_LOCKED=0
    tail -c +"$(( SEEN + 1 ))" "$MIGRATE_LOG" | grep -q 'SQLSTATE\[55P03\]' && MIGRATE_LOCKED=1
    (( MIGRATE_LOCKED == 1 && try < MIGRATE_TRIES )) || break
    printf '  \033[33m!\033[0m لم يُتَح القفلُ لهجرةٍ في مهلتها (محاولة %d من %d) — ما جرى قبلها باقٍ، وتُعاد بعد %s ثانية\n' \
        "$try" "$MIGRATE_TRIES" "$MIGRATE_RETRY_AFTER"
    sleep "$MIGRATE_RETRY_AFTER"
done
MIGRATED="$(grep -E '^[[:space:]]*[0-9]{4}_[0-9]{2}_[0-9]{2}_[0-9]{6}_[^ ]+ .*(DONE|FAIL|SKIPPED)[[:space:]]*$' "$MIGRATE_LOG" \
    | sed -E 's/^[[:space:]]*([^ ]+) .*(DONE|FAIL|SKIPPED)[[:space:]]*$/\1  \2/' || true)"
[[ -z "$MIGRATED" ]] || sed 's/^/    /' <<<"$MIGRATED"

(( MIGRATE_RC == 0 )) || die "سقطت الهجرات — توقّف النشر قبل الذاكرة وإعادة تحميل PHP، فالموقعُ على كوده القديم.
ما جرى قبل السقوط يبقى في القاعدة، ولا نسخةَ تُرجعه. الخطأُ كاملًا على الخادم وحده: $MIGRATE_LOG$( (( MIGRATE_LOCKED == 1 )) && printf '\nلم يُتَح قفلُ جدولٍ بعد %s محاولات — شيءٌ طويلٌ يمسكه. أعِد النشرَ في وقتٍ أهدأ.' "$MIGRATE_TRIES")"

MIGRATED_OK=1
rm -f "$PENDING_FILE"
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
FPM_RELOADED=1
ok "config · route · view · php-fpm"

# والمهامُّ المجدولة تُطلق: الكودُ الجديد على القاعدة المهاجَرة بذاكرته الجديدة
if (( HELD == 1 )); then
    rm -f "$HOLD_FILE"
    HELD=0
    ok "أُطلقت المهامّ المجدولة — تجري بالكود الجديد على القاعدة المهاجَرة"
fi

# ---------------------------------------------------------------------------
# العاملُ يُعاد على ما يبدأ عليه الويبُ الآن: الكودُ الجديد والقاعدةُ المهاجَرة
# والذاكرةُ الجديدة. `queue:restart` لا يقطع مهمّة — يُفحص بين المهامّ — ثمّ
# يُنتظر خروجُ القديم (لا يسحب شيئًا: الطابورُ موقوف) قبل أن يُطلق الطابور.
# ---------------------------------------------------------------------------
step "عامل الطابور"
RESTART_RC=0
art queue:restart --quiet || RESTART_RC=$?
if (( RESTART_RC == 0 )); then
    ok "طُلب من العامل أن يُعاد بعد مهمّته الجارية — فيبدأ بالكود الجديد"
else
    printf '  \033[33m!\033[0m تعذّر queue:restart — يبقى العاملُ بالكود القديم حتى يُعاد بنفسه (--max-time)\n'
fi
if (( RELEASED == 0 && PAUSED_WHATSAPP + PAUSED_DEFAULT > 0 )); then
    (( RESTART_RC != 0 )) || sleep "$QUEUE_RESTART_WAIT"
    release_queue || { QUEUE_STATE="موقوف — تعذّر إطلاقُه بعد النشر (الخرجُ في $QUEUE_LOG)"; die "تعذّر إطلاقُ الطابور بعد النشر — هو موقوف، ولا تخرج رسائلُ واتساب حتى يُطلق:
    أطلقه بحساب $APP_USER: $PHP_BIN $APP_DIR/artisan queue:resume whatsapp ثمّ default — والخرجُ في $QUEUE_LOG"; }
    QUEUE_STATE="أُطلق على العامل الجديد"
    ok "أُطلق الطابور — يسحب منه العاملُ الجديد"
fi

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
# وسقوطٌ سابقٌ حلّه هذا النشر: يُنقل ولا يُحذف — يبقى أثرُه
if [[ -e "$FAILED_FILE" ]]; then
    mv "$FAILED_FILE" "$STATE_DIR/DEPLOY-FAILED.resolved-$STAMP.txt"
    ok "نشرٌ سابقٌ سقط وأكمله هذا النشر (DEPLOY-FAILED.resolved-$STAMP.txt)"
fi

printf '\n\033[1mتمّ: %s → %s (%s)\033[0m\n' "$FROM_COMMIT" "$TO_COMMIT" "$VERSION"
printf 'لنشر الوسم من جهازك: \033[1mgit tag -a %s %s -m "نُشر على الإنتاج" && git push origin %s\033[0m\n' \
    "$VERSION" "$TO_COMMIT" "$VERSION"
[[ $PREFLIGHT -eq 0 ]] || printf '\033[33m! preflight يبلّغ عن موانع أعلاه — النشر تمّ، والموانع تبقى قرارك\033[0m\n'
printf 'للرجوع: %s\n' "$STATE_DIR/ROLLBACK.txt"
