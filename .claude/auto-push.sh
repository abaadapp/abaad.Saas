#!/usr/bin/env bash
# دفع تلقائي إلى GitHub بعد كل رد — يُستدعى من hook نوع Stop.
# لا يفشل أبدًا بشكل يوقف الجلسة: أي خطأ يُبلَّغ عنه كرسالة فقط.
set -uo pipefail

cd "$(git rev-parse --show-toplevel 2>/dev/null)" || exit 0

msg() { printf '{"systemMessage":%s,"suppressOutput":true}\n' "$(printf '%s' "$1" | sed 's/\\/\\\\/g; s/"/\\"/g; s/^/"/; s/$/"/')"; }

[ -z "$(git status --porcelain)" ] && exit 0

branch=$(git rev-parse --abbrev-ref HEAD)
[ "$branch" = "HEAD" ] && { msg "دفع تلقائي: متوقف — HEAD منفصل"; exit 0; }

# بوابة الجودة: لا يُدفع كود يكسر الاختبارات.
# نتخطاها فقط إذا لم يتغيّر أي ملف يؤثر على السلوك الخلفي.
if git status --porcelain | grep -qE '\.(php|json)$|^.. (app|routes|config|database|tests|lang)/'; then
  if ! test_out=$(php artisan test 2>&1); then
    failed=$(printf '%s' "$test_out" | grep -oE '"failed":[0-9]+' | head -1 | cut -d: -f2)
    culprit=$(printf '%s' "$test_out" | grep -oE '"test":"[^"]+"' | head -1 | cut -d'"' -f4 | sed 's/.*\\\\//')
    msg "دفع تلقائي: متوقف — فشل ${failed:-?} اختبار (${culprit:-غير معروف}). شغّل: php artisan test"
    exit 0
  fi
fi

files=$(git status --porcelain | wc -l | tr -d ' ')
first=$(git status --porcelain | head -1 | awk '{print $NF}')
subject="تحديث تلقائي: $first"
[ "$files" -gt 1 ] && subject="$subject (+$((files - 1)) ملف)"

git add -A || { msg "دفع تلقائي: فشل git add"; exit 0; }

if ! out=$(git commit -m "$subject" -m "Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>" 2>&1); then
  msg "دفع تلقائي: فشل الكوميت — $(printf '%s' "$out" | tail -1)"
  exit 0
fi

if ! out=$(git push origin "$branch" 2>&1); then
  msg "دفع تلقائي: الكوميت تم لكن الدفع فشل — $(printf '%s' "$out" | tail -1)"
  exit 0
fi

pushed="دُفع إلى origin/$branch — $subject"

# ---------------------------------------------------------------------------
# وسمٌ لكل دفعة.
#
# كان الترقيم يُصنع عند النشر وحده (انظر scripts/deploy.sh): الوسم يقول «هذا ما
# يعمل على الخادم». وصار يُصنع هنا أيضًا بطلب صاحب المشروع — لكل تعديلٍ رقمه،
# فيُعرف ما بين نشرتين لا ما نُشر فقط.
#
# والوسم يُدفع من هنا لا من الخادم: الخادم لا يملك اعتماداتِ كتابةٍ إلى GitHub.
# وحين يجد deploy.sh رأسًا موسومًا سلفًا يستعمل وسمه ولا يخترع رقمًا ثانيًا.
# ---------------------------------------------------------------------------
tag=""
if git describe --exact-match --tags HEAD >/dev/null 2>&1; then
  tag=$(git describe --exact-match --tags HEAD)
else
  # وسومُ الريموت أولًا: الرقم التالي يُحسب على ما عند الجميع لا على ما عندي —
  # وإلا صنع جهازان الوسم نفسه لكوميتين مختلفين ورفض الخادم سحبه
  git fetch --tags --force --quiet origin 2>/dev/null || true

  latest=$(git tag --list 'v[0-9]*' | sed 's/^v//' | sort -t. -k1,1n -k2,2n | tail -1)
  major="${latest%%.*}"; minor="${latest#*.}"; minor="${minor%%.*}"
  tag="v${major:-0}.$(( ${minor:-0} + 1 ))"

  if ! out=$(git tag -a "$tag" -m "$subject" 2>&1); then
    msg "$pushed — لكن تعذّر الوسم: $(printf '%s' "$out" | tail -1)"
    exit 0
  fi

  if ! out=$(git push origin "$tag" 2>&1); then
    # الوسم موجود محليًّا ولم يصل: يُحذف لئلّا يُحجز الرقم على هذا الجهاز وحده
    git tag -d "$tag" >/dev/null 2>&1
    msg "$pushed — لكن دفع الوسم فشل: $(printf '%s' "$out" | tail -1)"
    exit 0
  fi
fi

# ---------------------------------------------------------------------------
# النشر عقب الدفع — منفصلًا لا داخل مهلة الخطّاف.
#
# البناء على خادمٍ بذاكرة ٩٦١MB يأخذ دقائق، ومهلة الخطّاف ١٢٠ ثانية: انتظارُه
# هنا يعني قطعَه في منتصف `npm ci` أو في منتصف الهجرة. فيُطلق على الخادم
# بـsetsid ويمضي، ويُكتب مصيرُه في LAST_DEPLOY لتقرأه الدفعة التالية.
#
# فلا يبقى النشر بلا جواب: ما نُشر قبل قليل يُقال في رسالة ما بعده.
# ---------------------------------------------------------------------------
SERVER="${ABAAD_SERVER:-root@165.227.145.219}"
SSH=(ssh -o BatchMode=yes -o ConnectTimeout=10 "$SERVER")

# نتيجة النشرة السابقة أولًا — قبل أن تُطلق التالية وتكتب فوقها
prev=$("${SSH[@]}" 'tail -1 /root/pre-deploy/LAST_DEPLOY 2>/dev/null' 2>/dev/null || true)
[ -n "$prev" ] && prev=" · سابقتها: $prev"

if ! out=$("${SSH[@]}" 'bash -s' <<'REMOTE' 2>&1
set -u
mkdir -p /root/pre-deploy
stamp=$(date +%Y%m%d-%H%M%S)
log="/root/pre-deploy/deploy-$stamp.log"

# الأمر يُكتب ملفًّا ثمّ يُطلق: setsid على سطرٍ طويلٍ مقتبسٍ ثلاث مرّات
# يُقرأ خطأً، والملفّ يُقرأ كما كُتب
runner=$(mktemp /tmp/abaad-deploy.XXXXXX.sh)
cat > "$runner" <<RUN
#!/usr/bin/env bash
# قفلٌ بلا انتظار: نشرتان معًا تتنازعان البناء والهجرة على قاعدةٍ واحدة
exec 9>/run/abaad-deploy.lock
flock -n 9 || { printf 'SKIP %s — نشرةٌ أخرى تعمل\n' "$stamp" >> /root/pre-deploy/LAST_DEPLOY; rm -f "$runner"; exit 0; }

if bash /var/www/abaad/scripts/deploy.sh > "$log" 2>&1; then
    code=\$(curl -s -o /dev/null -w '%{http_code}' --max-time 15 https://app.abaadapp.om/health || echo 000)
    printf 'OK %s %s health=%s\n' "$stamp" "\$(git -C /var/www/abaad rev-parse --short HEAD)" "\$code" >> /root/pre-deploy/LAST_DEPLOY
else
    # آخر سطرٍ ذي معنى من السجلّ: السبب يُقال هنا لا يُبحث عنه في ملفّ
    why=\$(grep -v '^\\s*$' "$log" | tail -1 | tr -d '\\r' | cut -c1-120)
    printf 'FAIL %s — %s — %s\n' "$stamp" "\$why" "$log" >> /root/pre-deploy/LAST_DEPLOY
fi
rm -f "$runner"
RUN

chmod +x "$runner"
setsid nohup "$runner" >/dev/null 2>&1 &
echo "$log"
REMOTE
); then
  msg "$pushed · الوسم $tag — لكن تعذّر إطلاق النشر: $(printf '%s' "$out" | tail -1)$prev"
  exit 0
fi

msg "$pushed · الوسم $tag · انطلق النشر ($(printf '%s' "$out" | tail -1))$prev"
