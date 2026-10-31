#!/usr/bin/env bash
#
# فحصٌ قرائيٌّ يُثبت فصلَ صلاحيّات النشر — لا يكتب شيئًا.
#
#   sudo bash /var/www/abaad/scripts/audit-deploy-permissions.sh
#
# يُشغَّل بعد الانتقال (DEPLOYMENT.md). يتحقّق أنّ www-data لا يستطيع تعديل
# كودِ التطبيق ولا سكربتات النشر ولا ملفّات git، وأنّه يكتب في storage وحدها،
# وأنّ قواعد sudo قاعدتان لا ثالثةَ لهما. «أستطيع الكتابة؟» يُسأل بـ`test -w`
# الذي يقرأ البتَّ ولا يكتب حرفًا. يخرج بصفرٍ إن نجح كلُّ شيء.

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
head() { printf '\n\033[1m▸ %s\033[0m\n' "$*"; }

[[ "$(id -u)" == 0 ]] || { echo "شغّله بـsudo — يحتاج sudo -u $APP_USER test (قراءةٌ لا كتابة)"; exit 2; }
[[ -d "$APP_DIR" ]] || { echo "لا مشروع في $APP_DIR"; exit 2; }

# www-data أيستطيع الكتابة؟ test -w يقرأ البتَّ (الملكيّة والصلاحيّة وACL) ولا يكتب
www_can_write() { sudo -u "$APP_USER" test -w "$1"; }
owner_of() { stat -c %U "$1" 2>/dev/null; }

head "ملكيّة الشجرة"
for p in "$APP_DIR" "$APP_DIR/.git" "$APP_DIR/scripts/deploy.sh" "$APP_DIR/scripts/rollback-code.sh" "$APP_DIR/artisan" "$APP_DIR/public/index.php"; do
    o="$(owner_of "$p")"
    [[ "$o" == "$DEPLOY_USER" ]] && pass "$p يملكه $DEPLOY_USER" || fail "$p يملكه $o — المنتظَر $DEPLOY_USER"
done
for p in "$APP_DIR/storage" "$APP_DIR/bootstrap/cache"; do
    o="$(owner_of "$p")"
    [[ "$o" == "$APP_USER" ]] && pass "$p يملكه $APP_USER" || fail "$p يملكه $o — المنتظَر $APP_USER"
done

head "www-data لا يكتب الكود ولا سكربتات النشر ولا ملفّات git"
MUST_NOT=(
    "$APP_DIR" "$APP_DIR/.env" "$APP_DIR/composer.json" "$APP_DIR/composer.lock"
    "$APP_DIR/artisan" "$APP_DIR/public/index.php" "$APP_DIR/vendor/autoload.php"
    "$APP_DIR/scripts/deploy.sh" "$APP_DIR/scripts/rollback-code.sh"
    "$APP_DIR/.git" "$APP_DIR/.git/config" "$APP_DIR/.git/hooks"
    "$APP_DIR/app" "$APP_DIR/routes" "$APP_DIR/config" "$APP_DIR/bootstrap/app.php"
)
for p in "${MUST_NOT[@]}"; do
    [[ -e "$p" ]] || { note "غير موجود (يُتخطّى): $p"; continue; }
    www_can_write "$p" && fail "$APP_USER يكتب $p — لا ينبغي" || pass "$APP_USER لا يكتب $p"
done

head "www-data يكتب حيث يلزمه وحده"
for p in "$APP_DIR/storage" "$APP_DIR/storage/logs" "$APP_DIR/storage/framework/views" "$APP_DIR/bootstrap/cache"; do
    [[ -e "$p" ]] || { fail "غير موجود: $p"; continue; }
    www_can_write "$p" && pass "$APP_USER يكتب $p" || fail "$APP_USER لا يكتب $p — يلزمه"
done

head "قواعد sudo: قاعدتان لا ثالثة"
WWW_SUDO="$(sudo -n -l -U "$APP_USER" 2>&1)"
grep -qiE 'not allowed|unknown user|لا يُسمح' <<<"$WWW_SUDO" && pass "$APP_USER لا sudo له" \
    || { grep -qE 'NOPASSWD|\(root\)|ALL' <<<"$WWW_SUDO" && fail "$APP_USER له قواعد sudo — لا ينبغي" || pass "$APP_USER لا sudo له"; }

DEP_SUDO="$(sudo -n -l -U "$DEPLOY_USER" 2>/dev/null | grep -E 'NOPASSWD|artisan|systemctl' || true)"
grep -q 'artisan' <<<"$DEP_SUDO" && grep -q 'systemctl reload php8.4-fpm' <<<"$DEP_SUDO" \
    && pass "$DEPLOY_USER: php artisan بحساب $APP_USER، وsystemctl reload php8.4-fpm" \
    || fail "$DEPLOY_USER: القاعدتان المنتظَرتان غير مضبوطتَين — $DEP_SUDO"
# لا systemctl عامّ، ولا shell بصلاحية root
grep -qE '\(root\).*(/bin/(ba)?sh|ALL *: *ALL|systemctl (start|stop|mask|disable|enable)|systemctl *$)' <<<"$(sudo -n -l -U "$DEPLOY_USER" 2>/dev/null)" \
    && fail "$DEPLOY_USER له صلاحيةٌ أوسع من قاعدتَيه" || pass "$DEPLOY_USER بلا صلاحيةٍ أوسع"

head "مفتاح GitHub على حساب النشر لا على root"
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

head "إعداد git لا يثق بشجرة غيره"
SD="$(git config --global --get-all safe.directory 2>/dev/null | grep -c "$APP_DIR" || true)"
(( SD == 0 )) && pass "لا safe.directory=$APP_DIR في إعداد git لمن يُشغّل" \
    || note "في إعداد git لمن يُشغّل $SD إدخالًا safe.directory=$APP_DIR — نظّفه إن كان إعدادَ root"

printf '\n\033[1mالخلاصة: نجح %d · فشل %d\033[0m\n' "$PASS" "$FAIL"
(( FAIL == 0 )) || exit 1
