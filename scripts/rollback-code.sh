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
# (وبعد التجربة الجافّة يحلّ محلَّه `on_exit` أدناه — يحذفها هو ويُطلق الطابور)

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

step()  { CURRENT_STEP="$*"; printf '\n\033[1m▸ %s\033[0m\n' "$*"; }
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

# نشرٌ أو رجوعٌ واحدٌ في كلّ مرّة — القفلُ نفسُه في deploy.sh، ويُمسك قبل أيّ قراءة.
# والتجربةُ الجافّة لا تبدّل شيئًا فلا تقفل (ولا تحتاج STATE_DIR)
if [[ "$DRY_RUN" != "1" ]]; then
    mkdir -p "$STATE_DIR"
    command -v flock > /dev/null || die "flock غير موجود (util-linux) — لا يُرجَع بلا قفلٍ يمنع نشرًا فوقه"
    # ثوانٍ لا لحظة — كـdeploy.sh: سؤالُ التطبيق المشترك (`DeployState::deployRunning`) يُفلت في الحال
    DEPLOY_LOCK_WAIT="${DEPLOY_LOCK_WAIT:-5}"
    exec 9>> "$STATE_DIR/deploy.lock"
    flock -w "$DEPLOY_LOCK_WAIT" 9 || die "نشرٌ أو رجوعٌ آخر يجري الآن على $APP_DIR — لم يُلمس شيء. انتظره ثمّ أعِد.
    $(cat "$STATE_DIR/deploy.lock.who" 2>/dev/null || true)"
    git()      { command git "$@" 9>&-; }
    composer() { command composer "$@" 9>&-; }
    npm()      { command npm "$@" 9>&-; }
    printf 'رجوعٌ بالكود إلى %s · pid %s · منذ %s\n' "$TARGET" "$$" "$(date '+%Y-%m-%dT%H:%M:%S%z')" > "$STATE_DIR/deploy.lock.who"
fi

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
    printf '  ٠. قفلُ النشر، وإيقافُ المهامّ المجدولة (.deploy-hold)، وqueue:pause لطابورَي واتساب والافتراضيّ إن أباحته sudoers — لا يُسحب جديد، ويُنتظر حتى تنتهي الجارية (طابورًا ومجدولة)\n'
    printf '  ٤. config:cache · route:cache · view:cache (بحساب %s) · إعادة تحميل php-fpm · queue:restart ثمّ انتظارُ عاملٍ جديدٍ يثبت (systemd) ثمّ queue:resume\n' "$APP_USER"
    printf '  ٥. فحص %s\n' "$HEALTH_URL"
    printf 'والقاعدةُ لا تُمسّ: لا استعادةَ نسخة، ولا هجرة، ولا رجوعَ هجرة.\n'
    exit 0
fi

# ---------------------------------------------------------------------------
# الطابورُ يقف قبل أن يتبدّل الكود ويُطلق بعد أن يعمل عاملٌ جديدٌ عليه — كـdeploy.sh
# حرفًا: `queue:pause` لا يقطع مهمّةً جارية، ويُنتظر ما يجري (طابورٌ ومجدولة) حتى
# QUEUE_IDLE_TIMEOUT، و`queue:restart` يُفحص بين المهامّ، ثمّ يُسأل systemd حتى يثبت عاملٌ جديد.
# وإن سقط الرجوعُ بعد أن تبدّل الكودُ بقي الطابورُ والمهامُّ موقوفةً عمدًا حتى يكتمل رجوعٌ أو نشر.
# وقبل تركيب الصلاحيات لا يُوقف شيء، وبعد أن رُئيت كاملةً (RULES_VERIFIED) لا يمضي رجوعٌ بدونها.
# ---------------------------------------------------------------------------
QUEUE_IDLE_TIMEOUT="${QUEUE_IDLE_TIMEOUT:-600}"
QUEUE_IDLE_POLL="${QUEUE_IDLE_POLL:-2}"
QUEUE_UNIT="${QUEUE_UNIT:-abaad-queue.service}"
QUEUE_WORKER_TIMEOUT="${QUEUE_WORKER_TIMEOUT:-120}"
QUEUE_WORKER_SETTLE="${QUEUE_WORKER_SETTLE:-6}"
STAMP="$(date +%Y%m%d-%H%M%S)"
STATE_OUT="$STATE_DIR/state-rollback-$STAMP.txt"
( umask 077; : > "$STATE_OUT" )
QUEUE_LOG="$STATE_DIR/queue-rollback-$STAMP.log"
( umask 077; : > "$QUEUE_LOG" )
RULES_FILE="$STATE_DIR/RULES_VERIFIED"
RUN_FILE="$STATE_DIR/DEPLOY-RUNNING"
STARTED_AT="$(date '+%Y-%m-%dT%H:%M:%S%z')"
PAUSED_WHATSAPP=0
PAUSED_DEFAULT=0
RELEASED=0
WAS_PAUSED=0
INHERITED=0
TREE_TOUCHED=0
FULL_RULES=0
CURRENT_STEP="فحص الصلاحيات"

if [[ -s "$RUN_FILE" ]]; then
    printf '  \033[33m!\033[0m نشرٌ أو رجوعٌ سابقٌ انقطع دون أن يُكمل — %s\n    يُكمله هذا الرجوع: ما أُوقف منذه يُطلق في آخره، لا قبل.\n' "$(head -1 "$RUN_FILE")"
    cp "$RUN_FILE" "$STATE_DIR/DEPLOY-INTERRUPTED.$STAMP.txt" 2> /dev/null || true
fi
printf 'رجوعٌ إلى %s · pid %s · منذ %s · الخطوة: البدء\n' "$TO" "$$" "$STARTED_AT" > "$RUN_FILE"
step() {
    CURRENT_STEP="$*"
    printf '\n\033[1m▸ %s\033[0m\n' "$*"
    printf 'رجوعٌ إلى %s · pid %s · منذ %s · الخطوة: %s\n' "$TO" "$$" "$STARTED_AT" "$*" > "$RUN_FILE" 2> /dev/null || true
}

# أتبيح قاعدةُ sudo هذا الأمرَ؟ — كـdeploy.sh: يُسأل ولا يُجرى، فلا يُوقف طابورٌ لا يُطلق
may() { sudo -n -u "$APP_USER" -l "$PHP_BIN" "$APP_DIR/artisan" "$@" > /dev/null 2>&1; }
STATE_OK=0
may abaad:deploy-state && STATE_OK=1

ask_state() {
    local rc=0
    art abaad:deploy-state > "$STATE_OUT" 2>&1 || rc=$?
    return "$rc"
}

db_pending() {
    (( STATE_OK == 1 )) || return 0
    art abaad:deploy-state > "$STATE_OUT" 2>&1 || true
    sed -n 's/^pending-migrations: \([0-9][0-9]*\)$/\1/p' "$STATE_OUT" | head -1
}

# أالكودُ على القرص كما كان قبل هذا الرجوع؟ — الرجوعُ لا يُجري هجرة
code_untouched() {
    (( TREE_TOUCHED == 1 )) || return 0
    [[ "$(git rev-parse --short HEAD 2>/dev/null)" == "$FROM" ]] || return 1
    [[ -z "$(git status --porcelain --untracked-files=no 2>/dev/null)" ]]
}

# وحده هذا يُطلق ما أُوقف عند السقوط — كـdeploy.sh
compatible_now() {
    code_untouched || return 1
    (( INHERITED == 0 )) || return 1
    local n
    n="$(db_pending)"
    [[ -z "$n" || "$n" == 0 ]]
}

worker_unit() {
    local out
    out="$("$SYSTEMCTL" show "$QUEUE_UNIT" -p MainPID -p SubState -p LoadState 2> /dev/null)" || { echo "0 ? ?"; return 0; }
    printf '%s %s %s\n' "$(sed -n 's/^MainPID=//p' <<<"$out" | head -1)" "$(sed -n 's/^SubState=//p' <<<"$out" | head -1)" "$(sed -n 's/^LoadState=//p' <<<"$out" | head -1)" \
        | awk '{print ($1==""?0:$1), ($2==""?"?":$2), ($3==""?"?":$3)}'
}

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

# ينتظر حتى لا يجري شيء — كـdeploy.sh حرفًا
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
                    die "ما زال شيءٌ يجري بعد ${waited} ثانية — وقف الرجوعُ قبل أن يلمس الكود، ولم تُقطع المهمّة:
$(grep -E '^(job|task): ' "$STATE_OUT" | sed -E 's/^(job|task): /  /' || true)
أعِد الرجوعَ بعد أن تنتهي، أو ارفع QUEUE_IDLE_TIMEOUT."
                fi
                if (( waited == 0 )); then
                    printf '  شيءٌ يجري — ينتظره الرجوعُ قبل أن يتبدّل الكود (حتى %s ثانية):\n' "$QUEUE_IDLE_TIMEOUT"
                    grep '^job: ' "$STATE_OUT" | sed 's/^job: /    /' || true
                    grep '^task: ' "$STATE_OUT" | sed 's/^task: /    مجدولة: /' || true
                fi
                sleep "$QUEUE_IDLE_POLL"
                waited=$(( waited + QUEUE_IDLE_POLL ))
                ;;
            *)
                die "تعذّر سؤالُ التطبيق عن المهامّ الجارية (الخروج $rc، والخرجُ في $STATE_OUT) — وقف الرجوعُ قبل أن يلمس الكود."
                ;;
        esac
    done
}

HOLD_FILE="$APP_DIR/.deploy-hold"
HELD=0
hold_schedule() {
    printf '%s · pid %s · %s\nphase: %s\n' "$1" "$$" "$(date '+%Y-%m-%dT%H:%M:%S%z')" "${2:-before-pull}" > "$HOLD_FILE" 2> /dev/null
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

on_exit() {
    local rc=$? ok_now=0
    trap - HUP INT TERM
    if (( rc != 0 )); then
        printf '\n\033[31m✗ سقط الرجوعُ عند «%s»\033[0m — الكودُ على القرص %s (كان %s).\n' \
            "$CURRENT_STEP" "$(git -C "$APP_DIR" rev-parse --short HEAD 2>/dev/null || echo '?')" "$FROM" >&2
    fi
    compatible_now && ok_now=1
    if (( RELEASED == 0 && PAUSED_WHATSAPP + PAUSED_DEFAULT > 0 )); then
        if (( ok_now == 1 )); then
            if release_queue; then
                printf '  أُطلق الطابور كما كان — الكودُ على القرص لم يتغيّر، والعاملُ عليه\n' >&2
            else
                printf '\033[31m  ✗ تعذّر إطلاقُ الطابور — هو موقوف، والخرجُ في %s\033[0m\n' "$QUEUE_LOG" >&2
            fi
        else
            printf '::warning::يبقى الطابورُ موقوفًا عمدًا — الكودُ على القرص تغيّر أو تركه نشرٌ لم يكتمل\n'
            printf '\033[33m  ! يبقى الطابورُ موقوفًا عمدًا: العاملُ الحيّ على كودٍ غيرِ الذي على القرص. أعِد الرجوعَ أو Deploy Main — كلاهما يُطلقه بعد أن يتحقّق من عاملٍ جديد. ولا تُطلقه بيدك قبل ذلك.\033[0m\n' >&2
        fi
    fi
    if (( HELD == 1 )); then
        if (( ok_now == 1 )); then
            if rm -f "$HOLD_FILE"; then
                printf '  أُطلقت المهامّ المجدولة — الكودُ على القرص لم يتغيّر\n' >&2
            else
                printf '\033[31m  ✗ تعذّر حذفُ %s — المهامّ المجدولة موقوفة حتى يُحذف\033[0m\n' "$HOLD_FILE" >&2
            fi
        else
            hold_schedule "أُبقي بعد سقوط الرجوع إلى $TO عند «$CURRENT_STEP»" swapping || true
            printf '\033[33m  ! تبقى المهامّ المجدولة موقوفة — الكودُ على القرص في منتصف تبديله. يُطلقها رجوعٌ يكتمل أو نشرٌ يكتمل، لا الوقت.\033[0m\n' >&2
        fi
    fi
    rm -f "$RUN_FILE"
    (( rc == 0 )) || printf '::error title=سقط الرجوع::عند «%s» — الكود على القرص %s\n' "$CURRENT_STEP" "$(git -C "$APP_DIR" rev-parse --short HEAD 2>/dev/null || echo '?')"
    # والنسخةُ التي يجري منها السكربت تحذف نفسَها — كما كان فخُّها يفعل
    [[ "$(basename "$ME")" != abaad-rollback.* ]] || rm -f "$ME"
}
trap on_exit EXIT
trap 'CURRENT_STEP="$CURRENT_STEP (قُطع الرجوعُ بإشارة)"; exit 130' HUP INT TERM

step "فحص صلاحيات النشر"
if may queue:pause whatsapp && may queue:pause default && may queue:resume whatsapp && may queue:resume default \
    && (( STATE_OK == 1 )); then
    FULL_RULES=1
fi
if (( FULL_RULES == 1 )); then
    read -r _ _ UNIT_LOAD < <(worker_unit)
    [[ "$UNIT_LOAD" == loaded ]] || die "وحدةُ عامل الطابور $QUEUE_UNIT غيرُ موجودة في systemd ($UNIT_LOAD) — لم يُلمس شيء"
    ask_state || { rc=$?; [[ "$rc" == 3 ]] || die "تعذّر سؤالُ التطبيق عن حاله (الخروج $rc، والخرجُ في $STATE_OUT) — لم يُلمس شيء."; }
    PAUSED_NOW="$(sed -n 's/^paused: //p' "$STATE_OUT" | head -1)"
    [[ -n "$PAUSED_NOW" && "$PAUSED_NOW" != "?" ]] || die "تعذّر أن يُعرف أموقوفٌ الطابورُ الآن — لم يُلمس شيء"
    if [[ "$PAUSED_NOW" != "-" ]]; then
        WAS_PAUSED=1
        INHERITED=1
        printf '  \033[33m!\033[0m الطابورُ موقوفٌ قبل هذا الرجوع (%s) — يُطلقه الرجوعُ إن اكتمل، ويبقى موقوفًا إن سقط\n' "$PAUSED_NOW"
    fi
    [[ -e "$RULES_FILE" ]] || printf 'رأى الرجوعُ صلاحياتِ النشر كاملةً أوّلَ مرّة: %s · %s\n' "$(date '+%Y-%m-%dT%H:%M:%S%z')" "$FROM" > "$RULES_FILE"
    ok "صلاحياتُ النشر مثبّتة، ووحدةُ العامل $QUEUE_UNIT"
elif [[ -e "$RULES_FILE" ]]; then
    die "صلاحياتُ النشر كانت مثبّتةً ($(cat "$RULES_FILE")) وهي الآن ناقصة — لا يمضي رجوعٌ بدونها، ولم يُلمس شيء."
else
    printf '  \033[33m!\033[0m صلاحياتُ النشر الجديدة غيرُ مثبّتة في sudoers (deploy/abaad-deploy.sudoers) — لا يُوقف الطابور، ويمضي الرجوعُ كما كان\n'
fi

step "إيقاف المهامّ المجدولة والطابور قبل تبديل الكود"
HOLD_PHASE=before-pull
if grep -qx 'phase: swapping' "$HOLD_FILE" 2> /dev/null; then
    INHERITED=1
    HOLD_PHASE=swapping
    printf '  \033[33m!\033[0m المهامّ المجدولة موقوفةٌ قبل هذا الرجوع — تركها نشرٌ أو رجوعٌ لم يكتمل: %s\n' "$(head -1 "$HOLD_FILE")"
fi
if hold_schedule "رجوعٌ إلى $TO" "$HOLD_PHASE"; then
    HELD=1
    ok "المهامّ المجدولة: يتخطّاها التطبيقُ حتى يُبنى الكودُ وذاكرتُه"
else
    printf '  \033[33m!\033[0m تعذّرت كتابةُ %s — تجري المهامّ المجدولة أثناء الرجوع\n' "$HOLD_FILE"
fi
if (( FULL_RULES == 1 )); then
    art queue:pause whatsapp >> "$QUEUE_LOG" 2>&1 && PAUSED_WHATSAPP=1 || true
    art queue:pause default >> "$QUEUE_LOG" 2>&1 && PAUSED_DEFAULT=1 || true
    (( PAUSED_WHATSAPP == 1 && PAUSED_DEFAULT == 1 )) || die "تعذّر إيقافُ الطابور (انظر $QUEUE_LOG) — وقف الرجوعُ قبل أن يلمس الكود"
    ok "واتساب والافتراضيّ: لا يُسحب منهما جديدٌ حتى يعمل عاملٌ جديد"
    wait_for_idle
fi

# ---------------------------------------------------------------------------
step "الكود: $FROM → $TO"
printf '%s  رجوعُ الكود: %s → %s\n' "$(date '+%Y-%m-%dT%H:%M:%S%z')" "$FROM" "$TO" >> "$STATE_DIR/ROLLBACK.log"
(( HELD == 0 )) || hold_schedule "رجوعٌ إلى $TO" swapping || true
TREE_TOUCHED=1
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
ok "config · route · view · php-fpm"

if (( HELD == 1 )); then
    rm -f "$HOLD_FILE"
    HELD=0
    # والكودُ على القرص يجري على القاعدة كما هي: لا هجراتٍ معلّقةً يُنتظر لها
    rm -f "$STATE_DIR/MIGRATIONS_PENDING"
    ok "أُطلقت المهامّ المجدولة"
fi

# العاملُ يُعاد على الكود الذي رجع إليه، ثمّ يُسأل systemd حتى يثبت عاملٌ جديد — ثمّ يُطلق الطابور
step "عامل الطابور"
read -r OLD_WORKER _ _ < <(worker_unit)
RESTART_RC=0
art queue:restart --quiet || RESTART_RC=$?
if (( PAUSED_WHATSAPP + PAUSED_DEFAULT > 0 )); then
    (( RESTART_RC == 0 )) || die "تعذّر queue:restart — العاملُ على كودٍ غيرِ الذي على القرص، فيبقى الطابورُ موقوفًا"
    WORKER_RC=0
    wait_for_new_worker "$OLD_WORKER" || WORKER_RC=$?
    case "$WORKER_RC" in
        0) ok "خرج العاملُ القديم (${OLD_WORKER}) وثبت عاملٌ جديد (${NEW_WORKER}) على الكود الذي رجع إليه" ;;
        2) die "وحدةُ العامل $QUEUE_UNIT اختفت من systemd — يبقى الطابورُ موقوفًا" ;;
        *) die "لم يثبت عاملٌ جديدٌ على $QUEUE_UNIT خلال ${QUEUE_WORKER_TIMEOUT} ثانية (كان ${OLD_WORKER}) — يبقى الطابورُ موقوفًا. انظر: journalctl -u $QUEUE_UNIT" ;;
    esac
    release_queue || die "تعذّر إطلاقُ الطابور — هو موقوف. أطلقه بحساب $APP_USER: $PHP_BIN $APP_DIR/artisan queue:resume whatsapp ثمّ default"
    ok "أُطلق الطابور — يسحب منه العاملُ الجديد"
elif (( RESTART_RC == 0 )) && wait_for_new_worker "$OLD_WORKER"; then
    ok "أُعيد العامل (${OLD_WORKER} → ${NEW_WORKER}) — والطابورُ لم يُوقف (الصلاحياتُ غيرُ مثبّتة)"
else
    printf '  \033[33m!\033[0m لم يُتحقَّق من عودة العامل على الكود الذي رجع إليه — يبقى بكوده حتى يُعاد بنفسه (--max-time)\n'
fi

step "الموقع"
CODE="$(curl -s -o /dev/null -w '%{http_code}' --max-time 15 "$HEALTH_URL" || echo 000)"
[[ "$CODE" == "200" ]] || die "الموقع يردّ $CODE بعد الرجوع إلى $TO. لا تستعِد القاعدة؛ انظر سجلَّ storage/logs، أو عُد إلى $FROM:
    bash $APP_DIR/scripts/rollback-code.sh $FROM"
ok "$HEALTH_URL → 200"

# وسقوطُ نشرٍ قبلَه حلّه هذا الرجوع: يُنقل ولا يُحذف — يبقى أثرُه
if [[ -e "$STATE_DIR/DEPLOY-FAILED.txt" ]]; then
    mv "$STATE_DIR/DEPLOY-FAILED.txt" "$STATE_DIR/DEPLOY-FAILED.resolved-by-rollback-$STAMP.txt"
    ok "نشرٌ سابقٌ سقط وحلّه هذا الرجوع (DEPLOY-FAILED.resolved-by-rollback-$STAMP.txt)"
fi

printf '\n\033[1mرجع الكود: %s → %s — والقاعدة كما هي.\033[0m\n' "$FROM" "$TO"
printf '\033[33mالآن أرجِع main نفسَه (Revert PR)، وإلّا أعاد النشرُ التالي %s.\033[0m\n' "$FROM"
