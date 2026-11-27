#!/usr/bin/env bash
#
# فحصٌ قرائيٌّ يُثبت فصلَ صلاحيّات النشر — لا يكتب شيئًا.
#
#   sudo bash /var/www/abaad/scripts/audit-deploy-permissions.sh
#
# يُشغَّل بعد الانتقال (DEPLOYMENT.md). يتحقّق أنّ www-data لا يستطيع تعديل
# كودِ التطبيق ولا سكربتات النشر ولا ملفّات git — لا في الجذر ولا في أعماق
# الشجرة ولا عبر رابطٍ رمزيّ — وأنّه يكتب في storage وحدها، وأنّ قواعد sudo
# قاعدتان لا ثالثةَ لهما. «أستطيع الكتابة؟» يُسأل بـ`test -w` للملفّات الحرجة
# (يقرأ البتَّ وACL ولا يكتب)، وبمسحِ `find` سريعٍ للشجرة كلِّها. يخرج بصفرٍ إن نجح.
#
#   AUDIT_ONLY=tree   يشغّل مسحَ الشجرة والروابط وحده (بلا root ولا sudo) — للاختبار.

set -uo pipefail
export LC_ALL=C

APP_DIR="${APP_DIR:-/var/www/abaad}"
APP_USER="${APP_USER:-www-data}"
DEPLOY_USER="${DEPLOY_USER:-abaad-deploy}"
# بصمةُ مفتاح GitHub (مفتاحٌ عامّ، لا سرّ). مرّرها إن تغيّرت
DEPLOY_KEY_FP="${DEPLOY_KEY_FP:-SHA256:hHZmhZdHovZkP+YBmdpIoUULGS/Xlmjja72syvxCKnE}"

PASS=0 FAIL=0
pass() { printf '  \033[32m✓\033[0m %s\n' "$*"; PASS=$((PASS+1)); }
fail() { printf '  \033[31m✗ %s\033[0m\n' "$*"; FAIL=$((FAIL+1)); }
note() { printf '  \033[33m!\033[0m %s\n' "$*"; }
sec()  { printf '\n\033[1m▸ %s\033[0m\n' "$*"; }

# هويّاتُ www-data بالأرقام: uid ومجموعاتُه كلُّها. لا بالاسم — فاسمُ المجموعة قد لا يساوي
# اسمَ المستخدم (nobody مجموعتُه nogroup على لينكس)، و`find -group <اسمٍ غائب>` يخرج بخطأٍ
# بلا نتيجة، فيبدو الفحصُ نظيفًا وهو لم يفحص شيئًا
APP_UID="" APP_GIDS=""
app_ids() {
    APP_UID="$(id -u "$APP_USER" 2>/dev/null)" && APP_GIDS="$(id -G "$APP_USER" 2>/dev/null)" && [[ -n "$APP_GIDS" ]]
}

# كلُّ مسارٍ في الشجرة يستطيع www-data كتابته: يملكه، أو إحدى مجموعاته مجموعتُه وهو g+w،
# أو o+w. مسحٌ واحدٌ سريع، يشمل الأعماقَ كلَّها، ويستثني storage وbootstrap/cache.
# (الروابطُ تُفحص وحدها؛ صلاحيّةُ الرابط نفسِه lrwxrwxrwx لا تعني شيئًا)
# ويخرج بخطأ find نفسِه — فالخطأُ فشلٌ لا نظافة
scan_tree_writable() {
    local g gexpr=()
    for g in $APP_GIDS; do
        [[ ${#gexpr[@]} -eq 0 ]] || gexpr+=(-o)
        gexpr+=(-gid "$g")
    done
    find "$APP_DIR" -xdev \
        \( -path "$APP_DIR/storage" -o -path "$APP_DIR/bootstrap/cache" \) -prune -o \
        ! -type l \( -uid "$APP_UID" -o \( \( "${gexpr[@]}" \) -perm -g+w \) -o -perm -o+w \) -print
}

# روابطُ رمزيّة تهرب خارج الشجرة — فكتابةٌ عبرها كتابةٌ خارجها. ويخرج بغير صفرٍ إن تعذّر المسح
scan_symlinks_escaping() {
    local base links l t d
    base="$(cd "$APP_DIR" 2>/dev/null && pwd -P)" || return 2
    links="$(find "$APP_DIR" -xdev \( -path "$APP_DIR/vendor" -o -path "$APP_DIR/node_modules" \) -prune -o -type l -print)" || return 2
    while IFS= read -r l; do
        [[ -n "$l" ]] || continue
        t="$(readlink "$l" 2>/dev/null || true)"
        # مجلّدُ الهدف محلولًا إلى مسارٍ حقيقيّ (يعالج /var↔/private والنسبيّ)، ثمّ يُقارن بجذرٍ محلولٍ مثله
        case "$t" in
            /*) d="$(cd "$(dirname "$t")" 2>/dev/null && pwd -P || true)" ;;
            *)  d="$(cd "$(dirname "$l")" 2>/dev/null && cd "$(dirname "$t")" 2>/dev/null && pwd -P || true)" ;;
        esac
        case "$d/" in "$base"/*|"$base"/) ;; *) echo "$l -> $t" ;; esac
    done <<<"$links"
}

tree_section() {
    sec "لا شيء في عمق الشجرة يكتبه www-data (عدا storage وbootstrap/cache)"
    local hits esc rc
    if ! app_ids; then
        fail "لا حسابَ $APP_USER هنا — لا يُحكم على الشجرة بلا هويّته"
    else
        hits="$(scan_tree_writable 2>/dev/null)"; rc=$?
        if (( rc != 0 )); then
            fail "تعذّر مسحُ الشجرة (find خرج بـ$rc) — لا يُعدّ نظيفًا ما لم يُفحص"
        elif [[ -z "$hits" ]]; then
            pass "لا مسارَ في الشجرة يكتبه $APP_USER (uid $APP_UID، المجموعات: $APP_GIDS)"
        else
            fail "$APP_USER يكتب في الشجرة:"; sed 's/^/      /' <<<"$hits" | head -10
        fi
    fi

    sec "لا رابطَ رمزيّ يهرب خارج الشجرة"
    esc="$(scan_symlinks_escaping 2>/dev/null)"; rc=$?
    if (( rc != 0 )); then
        fail "تعذّر مسحُ الروابط — لا يُعدّ نظيفًا ما لم يُفحص"
    elif [[ -z "$esc" ]]; then
        pass "كلُّ رابطٍ داخل الشجرة"
    else
        fail "روابطُ تهرب خارجها:"; sed 's/^/      /' <<<"$esc" | head -10
    fi
}

# منفذُ الاختبار: الشجرةُ والروابطُ وحدها، بلا root ولا sudo
if [[ "${AUDIT_ONLY:-}" == tree ]]; then
    [[ -d "$APP_DIR" ]] || { echo "لا مشروع في $APP_DIR"; exit 2; }
    tree_section
    printf '\n\033[1mالخلاصة: نجح %d · فشل %d\033[0m\n' "$PASS" "$FAIL"
    (( FAIL == 0 )) || exit 1
    exit 0
fi

[[ "$(id -u)" == 0 ]] || { echo "شغّله بـsudo — يحتاج sudo -u $APP_USER test (قراءةٌ لا كتابة)"; exit 2; }
[[ -d "$APP_DIR" ]] || { echo "لا مشروع في $APP_DIR"; exit 2; }

# www-data أيستطيع الكتابة؟ test -w يقرأ البتَّ (الملكيّة والصلاحيّة وACL) ولا يكتب
www_can_write() { sudo -u "$APP_USER" test -w "$1"; }
owner_of() { stat -c %U "$1" 2>/dev/null; }

sec "ملكيّة الشجرة"
for p in "$APP_DIR" "$APP_DIR/.git" "$APP_DIR/scripts/deploy.sh" "$APP_DIR/scripts/rollback-code.sh" "$APP_DIR/artisan" "$APP_DIR/public/index.php"; do
    o="$(owner_of "$p")"
    [[ "$o" == "$DEPLOY_USER" ]] && pass "$p يملكه $DEPLOY_USER" || fail "$p يملكه $o — المنتظَر $DEPLOY_USER"
done
for p in "$APP_DIR/storage" "$APP_DIR/bootstrap/cache"; do
    o="$(owner_of "$p")"
    [[ "$o" == "$APP_USER" ]] && pass "$p يملكه $APP_USER" || fail "$p يملكه $o — المنتظَر $APP_USER"
done

sec "www-data لا يكتب الكود ولا سكربتات النشر ولا ملفّات git (فحصٌ دقيقٌ لملفّاتٍ حرجة بعينها)"
MUST_NOT=(
    "$APP_DIR" "$APP_DIR/.env" "$APP_DIR/composer.json" "$APP_DIR/composer.lock"
    "$APP_DIR/artisan" "$APP_DIR/public/index.php" "$APP_DIR/vendor/autoload.php"
    "$APP_DIR/scripts/deploy.sh" "$APP_DIR/scripts/rollback-code.sh" "$APP_DIR/scripts"
    "$APP_DIR/.git" "$APP_DIR/.git/config" "$APP_DIR/.git/hooks" "$APP_DIR/.git/HEAD"
    "$APP_DIR/app" "$APP_DIR/app/Http" "$APP_DIR/routes" "$APP_DIR/routes/web.php"
    "$APP_DIR/config" "$APP_DIR/config/app.php" "$APP_DIR/bootstrap/app.php"
)
for p in "${MUST_NOT[@]}"; do
    [[ -e "$p" ]] || { note "غير موجود (يُتخطّى): $p"; continue; }
    www_can_write "$p" && fail "$APP_USER يكتب $p — لا ينبغي" || pass "$APP_USER لا يكتب $p"
done

# والمسحُ العميق: لا شيء آخر في الشجرة يكتبه www-data، ولا رابطَ يهرب
tree_section

sec "www-data يكتب حيث يلزمه وحده"
for p in "$APP_DIR/storage" "$APP_DIR/storage/logs" "$APP_DIR/storage/framework/views" "$APP_DIR/bootstrap/cache"; do
    [[ -e "$p" ]] || { fail "غير موجود: $p"; continue; }
    www_can_write "$p" && pass "$APP_USER يكتب $p" || fail "$APP_USER لا يكتب $p — يلزمه"
done

sec "قواعد sudo: قاعدتان لا ثالثة"
WWW_SUDO="$(sudo -n -l -U "$APP_USER" 2>&1)"
grep -qiE 'not allowed|unknown user|لا يُسمح' <<<"$WWW_SUDO" && pass "$APP_USER لا sudo له" \
    || { grep -qE 'NOPASSWD|\(root\)|ALL' <<<"$WWW_SUDO" && fail "$APP_USER له قواعد sudo — لا ينبغي" || pass "$APP_USER لا sudo له"; }

DEP_SUDO="$(sudo -n -l -U "$DEPLOY_USER" 2>/dev/null | grep -E 'NOPASSWD|artisan|systemctl' || true)"
grep -q 'artisan' <<<"$DEP_SUDO" && grep -q 'systemctl reload php8.4-fpm' <<<"$DEP_SUDO" \
    && pass "$DEPLOY_USER: php artisan بحساب $APP_USER، وsystemctl reload php8.4-fpm" \
    || fail "$DEPLOY_USER: القاعدتان المنتظَرتان غير مضبوطتَين — $DEP_SUDO"
# لا systemctl عامّ، ولا shell بصلاحية root، ولا artisan عامّ (نجمة)
grep -qE '\(root\).*(/bin/(ba)?sh|ALL *: *ALL|systemctl (start|stop|mask|disable|enable)|systemctl *$)' <<<"$(sudo -n -l -U "$DEPLOY_USER" 2>/dev/null)" \
    && fail "$DEPLOY_USER له صلاحيةٌ أوسع من قاعدتَيه" || pass "$DEPLOY_USER بلا صلاحيةٍ أوسع"
sudo -n -l -U "$DEPLOY_USER" 2>/dev/null | grep -qE 'artisan \*|tinker|db:wipe|migrate:fresh' \
    && fail "$DEPLOY_USER يملك artisan عامًّا أو أمرًا خطرًا" || pass "$DEPLOY_USER: أوامرُ artisan محصورةٌ بأعيانها"

sec "مفتاح GitHub على حساب النشر لا على root"
DEP_AK="$(getent passwd "$DEPLOY_USER" | cut -d: -f6)/.ssh/authorized_keys"
if [[ -f "$DEP_AK" ]]; then
    if ssh-keygen -lf "$DEP_AK" 2>/dev/null | grep -qF "$DEPLOY_KEY_FP"; then
        grep -q 'command="/usr/bin/env bash /var/www/abaad/scripts/deploy.sh"' "$DEP_AK" \
            && pass "مفتاح النشر على $DEPLOY_USER بأمرٍ مقيّد" \
            || fail "مفتاح النشر على $DEPLOY_USER بلا الأمر المقيّد المنتظَر"
    else
        fail "بصمةُ مفتاح النشر غائبةٌ عن $DEP_AK"
    fi
else
    fail "لا $DEP_AK"
fi
if ssh-keygen -lf /root/.ssh/authorized_keys 2>/dev/null | grep -qF "$DEPLOY_KEY_FP"; then
    fail "مفتاح النشر ما زال على root — انقله ثمّ احذفه من root"
else
    pass "مفتاح النشر غائبٌ عن root"
fi

sec "إعداد git لا يثق بشجرة غيره"
SD="$(git config --global --get-all safe.directory 2>/dev/null | grep -c "$APP_DIR" || true)"
(( SD == 0 )) && pass "لا safe.directory=$APP_DIR في إعداد git لمن يُشغّل" \
    || note "في إعداد git لمن يُشغّل $SD إدخالًا safe.directory=$APP_DIR — نظّفه إن كان إعدادَ root"

printf '\n\033[1mالخلاصة: نجح %d · فشل %d\033[0m\n' "$PASS" "$FAIL"
(( FAIL == 0 )) || exit 1
