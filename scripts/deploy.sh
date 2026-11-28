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
step()  {
    CURRENT_STEP="$*"
    printf '\n\033[1m▸ %s\033[0m\n' "$*"
    # وتُكتب في DEPLOY-RUNNING: إن قُتل النشرُ قتلًا لا يُمرّ بـon_exit قال التالي أين وقف
    [[ -z "${RUN_FILE:-}" ]] || printf 'نشرٌ من %s · pid %s · منذ %s · الخطوة: %s\n' "${FROM_COMMIT:-?}" "$$" "${STARTED_AT:-?}" "$*" > "$RUN_FILE" 2> /dev/null || true
}
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
# نشرٌ أو رجوعٌ قُتل قتلًا لا تُمرّ به on_exit (KILL، انقطاعُ الكهرباء) يترك DEPLOY-RUNNING —
# فيُقال هنا بصوتٍ عالٍ أين وقف: قد يكون الطابورُ موقوفًا والمهامُّ موقوفةً منذه. وهذا النشرُ
# يُكملها: يرى الطابورَ موقوفًا (`paused:`) فلا يُطلقه إلّا إن اكتمل، ويرى الإيقافَ `swapping`.
# ---------------------------------------------------------------------------
RUN_FILE="$STATE_DIR/DEPLOY-RUNNING"
STARTED_AT="$(date '+%Y-%m-%dT%H:%M:%S%z')"
if [[ -s "$RUN_FILE" ]]; then
    printf '::warning::نشرٌ أو رجوعٌ سابقٌ انقطع دون أن يُكمل: %s\n' "$(head -1 "$RUN_FILE")"
    printf '  \033[33m!\033[0m نشرٌ أو رجوعٌ سابقٌ انقطع دون أن يُكمل — %s\n    يُكمله هذا النشر: إن كان الطابورُ أو المهامُّ موقوفةً منذه أُطلقت في آخره، لا قبل.\n' "$(head -1 "$RUN_FILE")"
    cp "$RUN_FILE" "$STATE_DIR/DEPLOY-INTERRUPTED.$(date +%Y%m%d-%H%M%S).txt" 2> /dev/null || true
fi
printf 'نشرٌ من %s · pid %s · منذ %s · الخطوة: البدء\n' "$FROM_COMMIT" "$$" "$STARTED_AT" > "$RUN_FILE"

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
# الطابورُ يقف قبل أن يتبدّل الكود، ويُطلَق بعد أن يعمل عاملٌ جديدٌ على الكود الجديد.
#
# العاملُ عمليّةٌ طويلةُ العمر: كان يبقى بالكود القديم حتى يُعاد كلَّ ساعة
# (`--max-time=3600`)، ويسحب المهامَّ طوالَ النشر — وكودُ القرص جديدٌ والقاعدةُ
# قديمة، ثمّ ساعةً بعده بكودٍ قديمٍ على قاعدةٍ جديدة.
#
#   - `queue:pause` لا يقطع شيئًا: المهمّةُ الجارية تُكمل، والعاملُ لا يسحب
#     جديدًا. فلا تُعاد مهمّةٌ قُطعت، ولا تخرج رسالةٌ مرّتين.
#   - ثمّ يُنتظر حتى لا تجري مهمّةُ طابورٍ ولا مهمّةٌ مجدولة (حتى QUEUE_IDLE_TIMEOUT،
#     عشرُ دقائق). فإن بقيت وقف النشرُ قبل أن يلمس الكود — لا تُقطع ولا يتبدّل تحتها.
#   - `queue:restart` بعد الهجرات والذاكرة وإعادة تحميل PHP: يُفحص بين المهامّ
#     لا في أثنائها، ويعيده systemd. ثمّ يُسأل systemd (قراءةً بلا sudo) حتى يخرج
#     العاملُ القديم ويثبت عاملٌ جديدٌ بدأ بعد أن اكتمل الكود — لا انتظارٌ ثابت.
#   - ثمّ `queue:resume`.
#
# ═══ وعند السقوط ═══
# يُطلق الطابورُ كما كان إن لم يتغيّر الكودُ على القرص (لم يُسحب شيء، ولم تجرِ هجرة).
# وإلّا بقي موقوفًا عمدًا: العاملُ على كودٍ قديمٍ والقرصُ والقاعدةُ تغيّرا، وإطلاقُه يُجري
# مهامَّ واتساب والمدفوعات على خليطٍ لم يُختبر. يُطلقه نشرٌ يكتمل أو رجوعٌ يكتمل، ويُقال ذلك.
#
# ═══ والصلاحيات ═══
# قبل تركيب deploy/abaad-deploy.sudoers الجديد لا يُوقف الطابور (لا قاعدةَ تُطلقه)، ولا تُنشر
# هجرة، ويُقال ذلك. وبعد أن يُرى التركيبُ كاملًا مرّةً (RULES_VERIFIED) لا يمضي نشرٌ بدونه:
# قاعدةٌ نقصت، أو تطبيقٌ لا يجيب عن المهامّ الجارية، يوقف النشرَ قبل أن يلمس شيئًا — لا
# انتظارٌ ثابتٌ ثمّ مُضيّ.
# ---------------------------------------------------------------------------
QUEUE_IDLE_TIMEOUT="${QUEUE_IDLE_TIMEOUT:-600}"
QUEUE_IDLE_POLL="${QUEUE_IDLE_POLL:-2}"
# وحدةُ العامل في systemd، ومهلةُ أن يعود عليها عاملٌ جديد، وكم يثبت قبل أن يُصدَّق
QUEUE_UNIT="${QUEUE_UNIT:-abaad-queue.service}"
QUEUE_WORKER_TIMEOUT="${QUEUE_WORKER_TIMEOUT:-120}"
QUEUE_WORKER_SETTLE="${QUEUE_WORKER_SETTLE:-6}"
STATE_OUT="$STATE_DIR/state-$STAMP.txt"
( umask 077; : > "$STATE_OUT" )
QUEUE_LOG="$STATE_DIR/queue-$STAMP.log"
( umask 077; : > "$QUEUE_LOG" )
RULES_FILE="$STATE_DIR/RULES_VERIFIED"
PAUSED_WHATSAPP=0
PAUSED_DEFAULT=0
RELEASED=0
WAS_PAUSED=0
TREE_TOUCHED=0
FULL_RULES=0
CURRENT_STEP="فحص الصلاحيات"

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

# ما جرى من هجراتٍ في هذا النشر، باسمها
migrations_done() { grep -E ' DONE[[:space:]]*$' "${MIGRATE_LOG:-/dev/null}" 2>/dev/null | awk '{print $1}' || true; }

# أالكودُ على القرص كما كان قبل هذا النشر؟ — لم يُسحب شيء (أو سُحب فلم يتحرّك HEAD ولا
# ملفّ)، ولم تجرِ هجرة. وحده هذا يُطلق ما أُوقف عند السقوط
code_untouched() {
    (( TREE_TOUCHED == 1 )) || return 0
    [[ "$(git rev-parse --short HEAD 2>/dev/null)" == "$FROM_COMMIT" ]] || return 1
    [[ -z "$(git status --porcelain --untracked-files=no 2>/dev/null)" ]] || return 1
    [[ -z "$(migrations_done)" ]]
}

# أيجري الكودُ على القرص على قاعدته الآن، كما كان قبل هذا النشر؟ — وحده هذا يُطلق ما أُوقف
# عند السقوط: لم يتغيّر الكود، ولم يرث هذا النشرُ إيقافًا تركه نشرٌ لم يكتمل (طابورًا موقوفًا،
# أو مهامَّ موقوفةً على `swapping`)، ولا تقول القاعدةُ إنّ هجرةً على القرص لم تجرِ
INHERITED=0
compatible_now() {
    code_untouched || return 1
    (( INHERITED == 0 )) || return 1
    local n
    n="$(db_pending)"
    [[ -z "$n" || "$n" == 0 ]]
}

# حالُ وحدة العامل من systemd — قراءةٌ بلا sudo: «MainPID SubState LoadState»
worker_unit() {
    local out
    out="$("$SYSTEMCTL" show "$QUEUE_UNIT" -p MainPID -p SubState -p LoadState 2> /dev/null)" || { echo "0 ? ?"; return 0; }
    printf '%s %s %s\n' "$(sed -n 's/^MainPID=//p' <<<"$out" | head -1)" "$(sed -n 's/^SubState=//p' <<<"$out" | head -1)" "$(sed -n 's/^LoadState=//p' <<<"$out" | head -1)" \
        | awk '{print ($1==""?0:$1), ($2==""?"?":$2), ($3==""?"?":$3)}'
}

# ينتظر حتى يخرج العاملُ القديم (رقمُه $1) ويثبت عاملٌ جديد QUEUE_WORKER_SETTLE ثانية —
# عاملٌ يسقط في إقلاعه يعيده systemd برقمٍ آخر كلَّ مرّة فلا يثبت. 0 ثبت، 1 المهلة، 2 لا وحدة
NEW_WORKER=""
wait_for_new_worker() {
    local old="$1" waited=0 pid sub load seen="" since=0
    while :; do
        read -r pid sub load < <(worker_unit)
        [[ "$load" == loaded ]] || return 2
        if [[ "$pid" != 0 && "$pid" != "$old" && "$sub" == running ]]; then
            if [[ "$pid" == "$seen" ]]; then
                if (( waited - since >= QUEUE_WORKER_SETTLE )); then
                    NEW_WORKER="$pid"
                    return 0
                fi
            else
                seen="$pid"
                since=$waited
            fi
        else
            seen=""
        fi
        (( waited < QUEUE_WORKER_TIMEOUT )) || return 1
        sleep "$QUEUE_IDLE_POLL"
        waited=$(( waited + QUEUE_IDLE_POLL ))
    done
}

# يسأل التطبيقَ عن حاله: 0 لا شيءَ يجري، 3 شيءٌ يجري — وغيرُهما لا يُعرف فيقف النشر
ask_state() {
    local rc=0
    art abaad:deploy-state > "$STATE_OUT" 2>&1 || rc=$?
    return "$rc"
}

# ينتظر حتى لا تجري مهمّةُ طابورٍ ولا مهمّةٌ مجدولة — أو يقف قبل أن يلمس الكود
wait_for_idle() {
    local waited=0 rc
    CURRENT_STEP="انتظار المهامّ الجارية قبل تبديل الكود"
    while :; do
        rc=0
        ask_state || rc=$?
        case "$rc" in
            0)
                ok "لا مهمّةَ تجري على الطابور ولا مهمّةَ مجدولة (انتُظرت ${waited} ثانية) — يتبدّل الكودُ ولا شيءَ يحمله"
                return 0
                ;;
            3)
                if (( waited >= QUEUE_IDLE_TIMEOUT )); then
                    die "ما زال شيءٌ يجري بعد ${waited} ثانية — وقف النشرُ قبل أن يلمس الكود، ولم تُقطع المهمّة:
$(grep -E '^(job|task): ' "$STATE_OUT" | sed -E 's/^(job|task): /  /' || true)
أعِد النشرَ بعد أن تنتهي، أو ارفع QUEUE_IDLE_TIMEOUT إن كانت طويلةً بطبعها."
                fi
                if (( waited == 0 )); then
                    printf '  شيءٌ يجري — ينتظره النشرُ قبل أن يتبدّل الكود (حتى %s ثانية):\n' "$QUEUE_IDLE_TIMEOUT"
                    grep '^job: ' "$STATE_OUT" | sed 's/^job: /    /' || true
                    grep '^task: ' "$STATE_OUT" | sed 's/^task: /    مجدولة: /' || true
                fi
                sleep "$QUEUE_IDLE_POLL"
                waited=$(( waited + QUEUE_IDLE_POLL ))
                ;;
            *)
                die "تعذّر سؤالُ التطبيق عن المهامّ الجارية (الخروج $rc، والخرجُ في $STATE_OUT) — وقف النشرُ قبل أن يلمس الكود. لا يُفترض أنّ شيئًا لا يجري."
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
# وقبل السحب يُكتب فيه `phase: swapping`: من هنا لا يُطلقه وقتٌ ولا سؤال — كودٌ نصفُ
# مبدَّل، ذاكرتُه قديمة، ولو جرت هجراتُه. وعند السقوط يُحذف إن لم يتغيّر الكودُ على القرص،
# وإلّا بقي حتى يكتمل نشرٌ أو رجوع، ويُعلَن ذلك.
# ---------------------------------------------------------------------------
HOLD_FILE="$APP_DIR/.deploy-hold"
HELD=0
MIGRATE_RC=0

# $1 السبب، و$2 المرحلة: before-pull (لم يُلمس الكود) أو swapping (يتبدّل)
hold_schedule() {
    printf '%s · نشرٌ من %s · pid %s · %s\nphase: %s\n' "$1" "$FROM_COMMIT" "$$" "$(date '+%Y-%m-%dT%H:%M:%S%z')" "${2:-before-pull}" > "$HOLD_FILE" 2> /dev/null
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
QUEUE_STATE="لم يُوقف (صلاحياتُ الإيقاف غيرُ مثبّتة، أو سقط النشرُ قبل الإيقاف)"
recovery_block() {
    local head pending done_list n
    head="$(git -C "$APP_DIR" rev-parse --short HEAD 2>/dev/null || echo '?')"
    done_list="$(migrations_done)"
    n="$(db_pending)"
    if [[ -n "$n" ]]; then
        pending="$n (من القاعدة)$(awk '/^migration: /{printf "\n      %s", $2}' "$STATE_OUT" 2>/dev/null || true)"
    else
        pending="$(git -C "$APP_DIR" diff --name-only --diff-filter=A "$FROM_COMMIT" HEAD -- database/migrations 2>/dev/null | xargs -n1 basename 2>/dev/null | sed 's/\.php$//' | grep -vxF -f <(printf '%s\n' "$done_list") || true)"
        pending="${pending:-لا شيء ظاهر} (تقديرٌ من git — قاعدةُ السؤال غيرُ مثبّتة)"
    fi
    printf '=== حال الخادم بعد سقوط النشر ===\n'
    printf 'سقط عند: %s\n' "$CURRENT_STEP"
    printf 'الكود على القرص: %s (كان %s)%s\n' "$head" "$FROM_COMMIT" "$(code_untouched && printf ' — لم يتغيّر' || printf ' — تغيّر')"
    if (( FPM_RELOADED == 1 )); then
        printf 'الويب: على الكود الجديد (أُعيد تحميل php-fpm)\n'
    else
        printf 'الويب: على ما حُمّل قبل النشر (لم يُعَد تحميل php-fpm)\n'
    fi
    printf 'هجراتٌ جرت في هذا النشر: %s\n' "${done_list:-لا شيء}"
    printf 'هجراتٌ لم تجرِ: %s\n' "$pending"
    printf 'الطابور: %s\n' "$QUEUE_STATE"
    if (( HELD == 1 )); then
        printf 'المهامّ المجدولة: موقوفة (%s) — لا يُطلقها الوقت: يُطلقها نشرٌ يكتمل أو رجوعٌ يكتمل\n' "$HOLD_FILE"
    else
        printf 'المهامّ المجدولة: تجري\n'
    fi
    printf 'الخطوات الآمنة (بالترتيب):\n'
    printf '  1. اقرأ السببَ أعلاه في سجلّ Deploy Main، وخرجَ الهجرات على الخادم: %s\n' "${MIGRATE_LOG:-—}"
    printf '  2. إن كان السببُ عابرًا (قفل، شبكة، مهمّةٌ طويلة): أعِد تشغيلَ Deploy Main — يُكمل الهجراتِ من حيث وقفت، ويعيد العامل ويتحقّق منه، ثمّ يُطلق الطابورَ والمهامّ.\n'
    printf '  3. وإلّا أرجِع الكودَ وحده: bash %s/scripts/rollback-code.sh %s — يرفض إن جرت في هذا النشر هجرة؛ والقاعدةُ لا تُستعاد. ويُطلق الطابورَ بعد أن يتحقّق من العامل.\n' "$APP_DIR" "$FROM_COMMIT"
    printf '  4. ثمّ أرجِع main نفسَه (Revert PR)، وإلّا أعاد النشرُ التالي الكودَ نفسه.\n'
    if [[ "$QUEUE_STATE" == موقوف* ]]; then
        printf '  5. لا تُطلق الطابورَ بيدك قبل 2 أو 3: العاملُ الحيّ على كودٍ غيرِ الذي على القرص. وإن تعذّرا معًا: بحساب root أعِد العاملَ (systemctl restart %s) على كودٍ يجري على قاعدته، ثمّ أطلقه بحساب %s: %s %s/artisan queue:resume whatsapp ثمّ default\n' "$QUEUE_UNIT" "$APP_USER" "$PHP_BIN" "$APP_DIR"
    fi
    printf '=== نهاية حال الخادم ===\n'
}

# أيُّ سقوطٍ — `die`، أو أمرٌ يُسقطه `set -e`، أو إشارةٌ قطعت النشر — يمرّ من هنا
on_exit() {
    local rc=$? untouched=0
    trap - HUP INT TERM
    if (( rc != 0 )); then
        printf '\n\033[31m✗ سقط النشرُ عند «%s»\033[0m — الكودُ على القرص %s (كان %s). الويبُ على ما حُمّل قبل إعادة تحميل PHP، وما جرى من هجراتٍ قبل السقوط باقٍ.\n  للمتابعة: أعِد النشر (يُكمل الهجرات من حيث وقفت). وللرجوع بالكود: %s/ROLLBACK.txt\n' \
            "$CURRENT_STEP" "$(git -C "$APP_DIR" rev-parse --short HEAD 2>/dev/null || echo '?')" "$FROM_COMMIT" "$STATE_DIR" >&2
    fi
    compatible_now && untouched=1
    if (( RELEASED == 0 && PAUSED_WHATSAPP + PAUSED_DEFAULT > 0 )); then
        if (( untouched == 1 )); then
            if release_queue; then
                QUEUE_STATE="أُطلق كما كان — الكودُ على القرص لم يتغيّر، والعاملُ عليه"
                printf '  أُطلق الطابور كما كان — الكودُ على القرص لم يتغيّر، والعاملُ عليه\n' >&2
            else
                QUEUE_STATE="موقوف — تعذّر إطلاقُه (الخرجُ في $QUEUE_LOG)"
                printf '\033[31m  ✗ تعذّر إطلاقُ الطابور — هو موقوف، والخرجُ في %s\033[0m\n' "$QUEUE_LOG" >&2
            fi
        elif (( WAS_PAUSED == 1 )) && code_untouched; then
            QUEUE_STATE="موقوف كما كان قبل هذا النشر — تركه نشرٌ سابقٌ لم يكتمل، ويُطلقه نشرٌ يكتمل أو رجوعٌ يكتمل"
            printf '::warning::يبقى الطابورُ موقوفًا كما كان قبل هذا النشر — تركه نشرٌ سابقٌ لم يكتمل\n'
        else
            QUEUE_STATE="موقوف عمدًا — العاملُ على كودٍ غيرِ الذي على القرص، أو القاعدةُ تنتظر هجرات؛ يُطلقه نشرٌ يكتمل أو رجوعٌ يكتمل بعد أن يتحقّق من عاملٍ جديد"
            printf '::warning::يبقى الطابورُ موقوفًا عمدًا — الكودُ على القرص تغيّر والعاملُ على القديم، فلا تخرج رسائلُ واتساب حتى يكتمل نشرٌ أو رجوع\n'
            printf '\033[33m  ! يبقى الطابورُ موقوفًا عمدًا: العاملُ الحيّ على كودٍ قديم، والقرصُ تغيّر. إطلاقُه الآن يُجري المهامَّ على خليطٍ لم يُختبر. أعِد Deploy Main أو أرجِع الكود — كلاهما يُطلقه بعد أن يتحقّق من عاملٍ جديد.\033[0m\n' >&2
        fi
    fi
    if (( HELD == 1 )); then
        if (( untouched == 1 )); then
            if rm -f "$HOLD_FILE"; then
                HELD=0
                printf '  أُطلقت المهامّ المجدولة — الكودُ على القرص لم يتغيّر\n' >&2
            else
                printf '\033[31m  ✗ تعذّر حذفُ %s — المهامّ المجدولة موقوفة حتى يُحذف\033[0m\n' "$HOLD_FILE" >&2
            fi
        else
            hold_schedule "أُبقي بعد سقوط النشر عند «$CURRENT_STEP»" swapping || true
            printf '::warning::تبقى المهامّ المجدولة موقوفة: الكودُ على القرص تغيّر ولم يكتمل النشر\n'
            printf '\033[33m  ! تبقى المهامّ المجدولة موقوفة — الكودُ على القرص تغيّر ولم تُبنَ ذاكرتُه أو لم تكتمل هجراتُه. لا يُطلقها الوقت: يُطلقها نشرٌ يكتمل أو رجوعٌ يكتمل. الملفّ: %s\033[0m\n' "$HOLD_FILE" >&2
        fi
    fi
    rm -f "$RUN_FILE"
    if (( rc != 0 )); then
        printf '::error title=سقط النشر::عند «%s» — حالُ الخادم والخطواتُ الآمنة في ملخّص التشغيل وفي %s على الخادم\n' "$CURRENT_STEP" "$FAILED_FILE"
        ( umask 077; recovery_block > "$FAILED_FILE" 2> /dev/null ) || true
        if [[ -s "$FAILED_FILE" ]]; then cat "$FAILED_FILE"; else recovery_block; fi
    fi
    # والنسخةُ التي يجري منها السكربت (أعلاه) تحذف نفسَها هنا: `exec` أسقط فخَّها هناك
    [[ "$(basename "${BASH_SOURCE[0]}")" != abaad-deploy.*.sh ]] || rm -f "${BASH_SOURCE[0]}"
}
trap on_exit EXIT
# وإشارةٌ تقطع النشر — إلغاءُ Deploy Main أو انقطاعُ SSH (HUP) أو قتلٌ لطيف — تمرّ بـon_exit
# كأيّ سقوط: يُقال أين، ويُطلق ما أُوقف أو يُبقى عمدًا بالقاعدة نفسها. وقتلٌ عنيف (KILL)
# لا يُمرّ به شيء: يبقى الطابورُ موقوفًا والمهامُّ موقوفة، ويقوله النشرُ التالي (DEPLOY-RUNNING)
trap 'CURRENT_STEP="$CURRENT_STEP (قُطع النشرُ بإشارة)"; exit 130' HUP INT TERM

# ---------------------------------------------------------------------------
step "فحص صلاحيات النشر"
if may queue:pause whatsapp && may queue:pause default && may queue:resume whatsapp && may queue:resume default \
    && (( STATE_OK == 1 )); then
    FULL_RULES=1
fi
if (( FULL_RULES == 1 )); then
    read -r _ _ UNIT_LOAD < <(worker_unit)
    [[ "$UNIT_LOAD" == loaded ]] || die "وحدةُ عامل الطابور $QUEUE_UNIT غيرُ موجودة في systemd ($UNIT_LOAD) — لا يُتحقَّق من العامل الجديد، فلم يُلمس شيء"
    ask_state || { rc=$?; [[ "$rc" == 3 ]] || die "تعذّر سؤالُ التطبيق عن حاله (الخروج $rc، والخرجُ في $STATE_OUT) — لم يُلمس شيء. لا يمضي نشرٌ لا يعرف ما يجري."; }
    PAUSED_NOW="$(sed -n 's/^paused: //p' "$STATE_OUT" | head -1)"
    [[ -n "$PAUSED_NOW" && "$PAUSED_NOW" != "?" ]] || die "تعذّر أن يُعرف أموقوفٌ الطابورُ الآن — لم يُلمس شيء"
    if [[ "$PAUSED_NOW" != "-" ]]; then
        WAS_PAUSED=1
        printf '  \033[33m!\033[0m الطابورُ موقوفٌ قبل هذا النشر (%s) — تركه نشرٌ أو رجوعٌ لم يكتمل. يُطلقه هذا النشرُ إن اكتمل، ويبقى موقوفًا إن سقط\n' "$PAUSED_NOW"
    fi
    if [[ ! -e "$RULES_FILE" ]]; then
        printf 'رأى النشرُ صلاحياتِه كاملةً أوّلَ مرّة: %s · %s\n' "$(date '+%Y-%m-%dT%H:%M:%S%z')" "$FROM_COMMIT" > "$RULES_FILE"
    fi
    ok "صلاحياتُ النشر مثبّتة: الإيقافُ والإطلاقُ وسؤالُ التطبيق، ووحدةُ العامل $QUEUE_UNIT"
elif [[ -e "$RULES_FILE" ]]; then
    die "صلاحياتُ النشر كانت مثبّتةً ($(cat "$RULES_FILE")) وهي الآن ناقصة — لا يمضي نشرٌ بدونها، ولم يُلمس شيء. أعِد تركيب deploy/abaad-deploy.sudoers (DEPLOYMENT.md)."
else
    printf '::warning::صلاحياتُ النشر الجديدة غيرُ مثبّتة على الخادم — لا يُوقف الطابور، ولا تُنشر هجرة\n'
    printf '  \033[33m!\033[0m صلاحياتُ النشر الجديدة غيرُ مثبّتة في sudoers (deploy/abaad-deploy.sudoers) — لا يُوقف الطابور أثناء هذا النشر (يعمل العاملُ أثناءه ويُعاد في آخره)، ويُرفض أيُّ نشرٍ فيه هجرة حتى تُركَّب\n'
fi

step "إيقاف المهامّ المجدولة والطابور قبل تبديل الكود"
# إيقافٌ تركه نشرٌ أو رجوعٌ لم يكتمل وهو يبدّل الكود: يُورث كما هو — من أوّل هذا النشر
# لا يُطلقه إلّا اكتمالُه، فسقوطُه قبل السحب لا يُطلق مهامَّ على كودٍ نصفِ مبدَّل
HOLD_PHASE=before-pull
if grep -qx 'phase: swapping' "$HOLD_FILE" 2> /dev/null; then
    INHERITED=1
    HOLD_PHASE=swapping
    printf '  \033[33m!\033[0m المهامّ المجدولة موقوفةٌ قبل هذا النشر — تركها نشرٌ أو رجوعٌ لم يكتمل وهو يبدّل الكود: %s\n' "$(head -1 "$HOLD_FILE")"
fi
(( WAS_PAUSED == 0 )) || INHERITED=1
if hold_schedule "نشر" "$HOLD_PHASE"; then
    HELD=1
    ok "المهامّ المجدولة: يتخطّاها التطبيقُ حتى تنتهي الهجراتُ والذاكرة ($HOLD_FILE)"
else
    printf '::warning::لم يُكتب ملفُّ إيقاف المهامّ المجدولة — تجري أثناء النشر كما كانت\n'
    printf '  \033[33m!\033[0m تعذّرت كتابةُ %s — تجري المهامّ المجدولة أثناء النشر كما كانت\n' "$HOLD_FILE"
fi
if (( FULL_RULES == 1 )); then
    art queue:pause whatsapp >> "$QUEUE_LOG" 2>&1 && PAUSED_WHATSAPP=1 || true
    art queue:pause default >> "$QUEUE_LOG" 2>&1 && PAUSED_DEFAULT=1 || true
    (( PAUSED_WHATSAPP == 1 && PAUSED_DEFAULT == 1 )) \
        || die "تعذّر إيقافُ الطابور (انظر $QUEUE_LOG) — وقف النشرُ قبل أن يلمس الكود"
    QUEUE_STATE="موقوف — يُطلق في آخر النشر"
    ok "واتساب والافتراضيّ: لا يُسحب منهما جديدٌ حتى يعمل عاملٌ جديد"
    wait_for_idle
fi

# ---------------------------------------------------------------------------
step "سحب $BRANCH"
# --force على الوسوم: الخادم يصنع وسمه محليًّا حين لا يجد رمز دفعٍ لـGitHub،
# فإذا دُفع الوسم نفسه من جهاز المطوّر اختلف الكائنان ورفض git السحب
# «would clobber existing tag». وبـset -e يموت النشر عند أوّل سطرٍ بعد
# الترويسة بلا رسالة — نشرٌ يتوقّف بصمت، وكلّ نشرةٍ بعده كذلك.
git fetch --tags --force --quiet origin

# ولا هجرةَ قبل تركيب صلاحيات النشر: بلا إيقاف الطابور وسؤال التطبيق يجري العاملُ القديم
# على القاعدة المهاجَرة، ولا يُعرف عند السقوط ما جرى — قرارُ المالك (الجولة 16)
INCOMING_MIGRATIONS="$(git diff --name-only --diff-filter=A HEAD "origin/$BRANCH" -- database/migrations)"
if (( FULL_RULES == 0 )) && [[ -n "$INCOMING_MIGRATIONS" ]]; then
    die "في هذا النشر هجرة، وصلاحياتُ النشر الجديدة غيرُ مثبّتة — لم يُلمس الكود:
$(sed 's/^/    /' <<<"$INCOMING_MIGRATIONS")
ركّب deploy/abaad-deploy.sudoers وتحقّق منه (DEPLOYMENT.md)، ثمّ أعِد النشر."
fi

# من هنا يتبدّل الكود: لا يُطلق الإيقافَ وقتٌ ولا سؤال، ولا يُطلق السقوطُ الطابور
(( HELD == 0 )) || hold_schedule "نشر" swapping || true
TREE_TOUCHED=1
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

# والمهامُّ المجدولة تُطلق: الكودُ الجديد على القاعدة المهاجَرة بذاكرته الجديدة —
# وكلُّ تشغيلٍ لـschedule:run عمليّةٌ جديدة تقرأ هذا كلَّه
if (( HELD == 1 )); then
    rm -f "$HOLD_FILE"
    HELD=0
    ok "أُطلقت المهامّ المجدولة — تجري بالكود الجديد على القاعدة المهاجَرة"
fi

# ---------------------------------------------------------------------------
# العاملُ يُعاد على ما يبدأ عليه الويبُ الآن: الكودُ الجديد والقاعدةُ المهاجَرة
# والذاكرةُ الجديدة. `queue:restart` لا يقطع مهمّة — يُفحص بين المهامّ — ثمّ يُسأل
# systemd حتى يخرج القديمُ ويثبت جديدٌ بدأ بعد هذا كلِّه. ولا يُطلق الطابورُ قبله.
# ---------------------------------------------------------------------------
step "عامل الطابور"
read -r OLD_WORKER _ _ < <(worker_unit)
RESTART_RC=0
art queue:restart --quiet || RESTART_RC=$?
if (( PAUSED_WHATSAPP + PAUSED_DEFAULT > 0 )); then
    (( RESTART_RC == 0 )) || die "تعذّر queue:restart — العاملُ على الكود القديم، فيبقى الطابورُ موقوفًا (لا يسحب العاملُ القديم مهمّةً على الكود الجديد)"
    QUEUE_STATE="موقوف — ينتظر عاملًا جديدًا"
    WORKER_RC=0
    wait_for_new_worker "$OLD_WORKER" || WORKER_RC=$?
    case "$WORKER_RC" in
        0) ok "خرج العاملُ القديم (${OLD_WORKER}) وثبت عاملٌ جديد (${NEW_WORKER}) بدأ على الكود الجديد" ;;
        2) die "وحدةُ العامل $QUEUE_UNIT اختفت من systemd — يبقى الطابورُ موقوفًا" ;;
        *) die "لم يثبت عاملٌ جديدٌ على $QUEUE_UNIT خلال ${QUEUE_WORKER_TIMEOUT} ثانية (كان ${OLD_WORKER}) — يبقى الطابورُ موقوفًا. انظر: journalctl -u $QUEUE_UNIT" ;;
    esac
    release_queue || { QUEUE_STATE="موقوف — تعذّر إطلاقُه بعد النشر (الخرجُ في $QUEUE_LOG)"; die "تعذّر إطلاقُ الطابور بعد النشر — هو موقوف، ولا تخرج رسائلُ واتساب حتى يُطلق:
    أطلقه بحساب $APP_USER: $PHP_BIN $APP_DIR/artisan queue:resume whatsapp ثمّ default — والخرجُ في $QUEUE_LOG"; }
    QUEUE_STATE="أُطلق على العامل الجديد"
    ok "أُطلق الطابور — يسحب منه العاملُ الجديد"
else
    # قبل تركيب الصلاحيات: لا إيقاف — يُطلب من العامل أن يُعاد، ويُقال أعاد أم لا
    if (( RESTART_RC == 0 )) && wait_for_new_worker "$OLD_WORKER"; then
        ok "أُعيد العامل (${OLD_WORKER} → ${NEW_WORKER}) — والطابورُ لم يُوقف (الصلاحياتُ غيرُ مثبّتة)"
    else
        printf '::warning::لم يُتحقَّق من عودة العامل على الكود الجديد\n'
        printf '  \033[33m!\033[0m لم يُتحقَّق من عودة العامل على الكود الجديد — يبقى بكوده حتى يُعاد بنفسه (--max-time)\n'
    fi
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
