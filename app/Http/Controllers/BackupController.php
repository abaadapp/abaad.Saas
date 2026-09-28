<?php

namespace App\Http\Controllers;

use App\Models\Business;
use App\Models\User;
use App\Support\Activity;
use App\Support\Archive\Policy as ArchivePolicy;
use App\Support\BackupService;
use App\Support\BackupTooLarge;
use App\Support\Demo;
use App\Support\Permissions;
use App\Support\TenantTables;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * نسخُ بيانات المتجر واستعادتُها — محصورةً بالمتجر الحاليّ.
 *
 * والاستعادةُ تحلّ محلّ ما في المتجر: تحذف ثمّ تُدرج. فما تحذفه ولا تُدرجه
 * يضيع بلا أن يقول شيءٌ ذلك — ولذلك تُقرأ الجداولُ والترتيبُ من
 * `TenantTables` وحدها، هي نفسُها التي بُنيت بها النسخة.
 *
 * ═══ ولا بابَ هنا يأخذ مسارَ ملفّ ═══
 *
 * «تحميل آخر نسخة» و«استعادة آخر نسخة» يسألان `BackupService::latest` عن
 * متجر الحساب نفسِه، ولا يقبلان اسمَ ملفٍّ من الطلب. فلا سبيلَ إلى نسخة
 * متجرٍ آخر بتبديل رقمٍ في الرابط — لأنّ الرابط لا يحمل رقمًا أصلًا.
 */
class BackupController extends Controller
{
    private function bid(): int { return auth()->user()->business_id ?? Demo::bid(); }

    public function download()
    {
        $bid = $this->bid();

        Activity::log('backup', 'صدّر نسخة احتياطية للمتجر');

        return response(BackupService::json($bid), 200, [
            'Content-Type' => 'application/json; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.BackupService::filename($bid).'"',
        ]);
    }

    /**
     * ما تعرضه شاشةُ النسخ: التكرارُ وآخرُ نسخةٍ ناجحة.
     *
     * يُقرأ من القرص بأسماء الملفّات لا من القاعدة — فما تقوله الشاشةُ هو
     * ما يُنزَّل ويُستعاد بالضغطة التالية، لا سجلٌّ يفترق عنه.
     */
    public static function panel(int $bid): array
    {
        $latest = BackupService::latest($bid);

        return [
            'frequency' => BackupService::frequency($bid),
            'frequencies' => BackupService::FREQUENCIES,
            'offsite_enabled' => ArchivePolicy::backupRemoteEnabled(),
            /*
             * أيَعرض بابَي الاستعادة؟ — من الحارس نفسِه لا من قاعدةٍ تُكتب ثانيةً.
             *
             * زرٌّ يُعرض لمن سيُردّ يُضغط فتُصفع به صفحةُ ٤٠٣. والحارسُ في
             * الخادم (`ownerOnly`) هو ما يمنع، وهذا ما يُخفي فقط.
             */
            'can_restore' => Permissions::isOwner(auth()->user()),
            'latest' => $latest === null ? null : [
                'name' => $latest['name'],
                'created_at' => $latest['created_at']->format('Y-m-d H:i'),
                'bytes' => $latest['bytes'],
                'offsite' => $latest['offsite'],
                'compressed' => $latest['compressed'],
            ],
        ];
    }

    /** متى يُنسخ المتجرُ تلقائيًّا — يوميًّا أو أسبوعيًّا أو شهريًّا أو يدويًّا فقط */
    public function frequency(Request $request)
    {
        $data = $request->validate([
            'frequency' => ['required', 'string', Rule::in(BackupService::FREQUENCIES)],
        ]);

        BackupService::setFrequency($this->bid(), $data['frequency']);

        Activity::log('backup', 'غيّر تكرار النسخ الاحتياطي التلقائي إلى: '.$data['frequency']);

        return back()->with('toast', ['msg' => __('حُفظ تكرار النسخ الاحتياطي'), 'type' => 'success']);
    }

    /**
     * «إنشاء نسخة الآن» — مهما كان التكرار، ولو «يدويًّا فقط».
     *
     * وهي النسخةُ نفسُها التي يكتبها المجدول: مضغوطة، ومتحقَّقٌ منها،
     * ومنسوخةٌ بعيدًا إن كان القرصُ البعيد مفعَّلًا.
     */
    public function create()
    {
        try {
            $record = BackupService::store($this->bid());
        } catch (\Throwable $e) {
            report($e);

            return back()->with('toast', ['msg' => __('تعذّر إنشاء النسخة الاحتياطية — لم يُحفظ شيء.'), 'type' => 'danger']);
        }

        Activity::log('backup', 'أنشأ نسخة احتياطية على الخادم: '.$record['name']);

        return back()->with('toast', ['msg' => __('أُنشئت النسخة الاحتياطية وتُحقّق منها'), 'type' => 'success']);
    }

    /** يُنزّل آخرَ نسخةٍ ناجحة لهذا المتجر — كما حُفظت، مضغوطة */
    public function downloadLatest()
    {
        $latest = BackupService::latest($this->bid());

        if ($latest === null) {
            return back()->with('toast', ['msg' => __('لا توجد نسخة احتياطية محفوظة بعد'), 'type' => 'danger']);
        }

        Activity::log('backup', 'نزّل آخر نسخة احتياطية: '.$latest['name']);

        return BackupService::disk()->download($latest['path'], $latest['name'], [
            'Content-Type' => $latest['compressed'] ? 'application/gzip' : 'application/json; charset=UTF-8',
        ]);
    }

    /**
     * «استعادة آخر نسخة» — وقبلها نسخةُ أمانٍ لحال المتجر الآن (`guardedRestore`).
     */
    public function restoreLatest(Request $request)
    {
        $this->ownerOnly();

        $request->validate([
            'confirm' => ['accepted'],
        ], [
            'confirm.accepted' => __('الاستعادة تمحو بيانات متجرك كلَّها ولا رجعة بعدها — أكِّدها لتمضي.'),
        ]);

        $bid = $this->bid();
        $latest = BackupService::latest($bid);

        if ($latest === null) {
            return back()->with('toast', ['msg' => __('لا توجد نسخة احتياطية محفوظة بعد'), 'type' => 'danger']);
        }

        $data = $this->readOrRefuse(fn () => BackupService::readFile($latest['path']));

        return $this->guardedRestore($data, $bid, 'استعاد آخر نسخة احتياطية ('.$latest['name'].')');
    }

    /**
     * الاستعادةُ تُسأل قبل أن تمحو — والسؤالُ هنا لا في الشاشة وحدها.
     *
     * ═══ العطب ═══
     *
     * «حذف جميع التنبيهات المرسلة؟» كان يُستوقَف بنافذة تأكيد، والاستعادةُ —
     * وهي تحذف المنتجات والطلبات والعملاء والدفتر كلَّه ثمّ تكتب مكانها ما في
     * الملفّ، بلا رجعة — تمضي بضغطةٍ واحدة على زرٍّ أحمر. فالأخفُّ يُسأل عنه
     * والأثقلُ لا.
     *
     * والشرطُ في الخادم لأنّ الشاشة تُتخطّى: نافذةُ تأكيدٍ في الواجهة تحرس
     * من يضغط، ولا تحرس طلبًا يُرسَل من غيرها. فمن أراد المحوَ قاله صراحةً.
     */
    public function restore(Request $request)
    {
        $this->ownerOnly();

        $request->validate([
            'backup' => ['required', 'file', 'max:51200'],
            'confirm' => ['accepted'],
        ], [
            'confirm.accepted' => __('الاستعادة تمحو بيانات متجرك كلَّها ولا رجعة بعدها — أكِّدها لتمضي.'),
        ]);

        $stream = fopen($request->file('backup')->getRealPath(), 'rb');

        try {
            $data = $this->readOrRefuse(fn () => BackupService::read($stream));
        } finally {
            fclose($stream);
        }

        return $this->guardedRestore($data, $this->bid(), 'استعاد بيانات المتجر من نسخة احتياطية');
    }

    /**
     * الاستعادةُ لصاحب النشاط وحدَه — لا لكلّ من مُنح «الإعدادات».
     *
     * هي تمحو المتجرَ كلَّه ثمّ تكتبه من ملفّ. وقسمُ «الإعدادات» يُمنح لموظّفٍ
     * يضبط الطابعةَ أو ساعاتِ العمل، ومديرُ الفرع يملكه بـ`'*'` — فلو كفى
     * القسمُ لَمحا المتجرَ من لم يُرِد صاحبُه أن يملك ذلك. والإنشاءُ والتحميلُ
     * يبقيان على القسم: لا يمحوان شيئًا.
     *
     * ويُسأل أوّلَ شيء — قبل التحقّق وقبل قراءة الملفّ — فلا يُقرأ ملفٌّ ولا
     * تُكتب نسخةُ أمانٍ لمن سيُردّ. والشاشةُ تُخفي الزرّ، ولا يُعتمد عليها:
     * طلبٌ يُرسَل من غيرها لا يمرّ بها. (انظر `Permissions::isOwner`)
     */
    private function ownerOnly(): void
    {
        abort_unless(Permissions::isOwner(auth()->user()), 403, __('استعادة النسخ الاحتياطية لصاحب النشاط وحده.'));
    }

    /**
     * يقرأ النسخة بحدّها — وما تجاوزه يُردّ ٤٢٢ قبل أن يُمسّ شيء.
     *
     * والردُّ خطأُ تحقّقٍ على حقل `backup` لا صفحةُ خطأ: يُقرأ تحت حقل الرفع
     * كما يُقرأ «الملفّ أكبر من خمسين ميجابايت» — وهو الحدُّ نفسُه.
     */
    private function readOrRefuse(\Closure $read): ?array
    {
        try {
            return $read();
        } catch (BackupTooLarge) {
            throw ValidationException::withMessages([
                'backup' => __('النسخة بعد فكّ ضغطها أكبر من 50 ميجابايت — لا تُستعاد من هنا.'),
            ]);
        }
    }

    /**
     * كلُّ استعادةٍ تمرّ من هنا: فحصُ الملفّ، ثمّ نسخةُ أمان، ثمّ المحوُ والإدراج.
     *
     * ═══ ونسخةُ الأمان شرطٌ لا محاولة ═══
     *
     * الاستعادةُ تمحو ما في المتجر. ومن رفع ملفًّا خطأً — أو استعاد نسخةً
     * أقدمَ ممّا ظنّ — لا يعود إلّا بنسخةٍ لحاله قبل الضغطة. فإن لم تُكتب
     * تلك النسخة أو لم تطابق المتجرَ جدولًا جدولًا (`BackupService::store`)
     * **لا تبدأ الاستعادة**: محوٌ بلا طريق رجوع هو ما جاءت لتمنعه.
     *
     * وبابٌ واحدٌ للنوعين — المرفوعِ وآخرِ نسخة — لا بابان: الحمايةُ التي
     * تُكتب مرّتين تُنسى في إحداهما، وهو ما كان: الأمانُ لآخر نسخةٍ وحدها.
     */
    private function guardedRestore(?array $data, int $bid, string $what)
    {
        if ($refusal = $this->refuse($data, $bid)) {
            return back()->with('toast', ['msg' => $refusal, 'type' => 'danger']);
        }

        try {
            $safety = BackupService::store($bid, BackupService::KIND_SAFETY);
        } catch (\Throwable $e) {
            report($e);

            return back()->with('toast', [
                'msg' => __('تعذّر أخذ نسخة أمان لحال متجرك الآن — فلم تبدأ الاستعادة، ولم يتغيّر شيء.'),
                'type' => 'danger',
            ]);
        }

        $this->apply($data, $bid);

        Activity::log('restore', $what.' بعد نسخة أمان: '.$safety['name']);

        return back()->with('toast', ['msg' => __('تمت استعادة البيانات بنجاح'), 'type' => 'success']);
    }

    /**
     * سببُ ردّ الملفّ قبل أن يُحذف شيء — أو `null` إن صلح.
     */
    private function refuse(?array $data, int $bid): ?string
    {
        if (! is_array($data) || (($data['meta']['app'] ?? null) !== 'AbadPOS')) {
            return __('ملف النسخة الاحتياطية غير صالح');
        }

        /*
         * وملفٌّ من صيغةٍ قديمة يُردّ قبل الحذف لا بعده.
         *
         * النسخُ حتّى الثانية تحمل سبعةَ عشرَ جدولًا من ستّين. واستعادتُها
         * تحذف مخزونَ الفروع والصناديقَ ودفترَ الأستاذ ثمّ لا تُعيد منها
         * شيئًا — وتقول «تمّت بنجاح». والردُّ هنا يمنع ذلك، ولا يُفقده شيئًا:
         * ملفُّه على جهازه كما هو، ونسخةُ الليلة تُؤخذ بالصيغة الجديدة.
         */
        if ((int) ($data['meta']['version'] ?? 0) < BackupService::VERSION) {
            return __('هذه نسخةٌ بصيغةٍ قديمة لا تحمل كلّ جداول المتجر — استعادتُها تمحو ما لا تُعيد. خُذ نسخةً جديدة واستعِد منها.');
        }

        /*
         * ونسخةُ متجرٍ آخر لا تُستعاد هنا.
         *
         * كانت تُقبل وتُكتب سطورُها بمعرّف هذا المتجر — فتحمل بياناتِ غيره
         * إليه، أو تسقط في منتصفها على مفتاحٍ أساسيّ ما زال عند صاحبه بعد
         * أن حذفت ما هنا. وكلاهما لا يُراد.
         */
        if ((int) ($data['meta']['business_id'] ?? 0) !== $bid) {
            return __('هذه نسخةُ متجرٍ آخر — لا تُستعاد في متجرك.');
        }

        return null;
    }

    /** المحوُ ثمّ الإدراج — في معاملةٍ واحدة */
    private function apply(array $data, int $bid): void
    {
        $currentUserId = auth()->id();

        DB::transaction(function () use ($data, $bid, $currentUserId) {
            $this->wipe($bid);
            $this->restoreBusiness($data, $bid);
            $this->insertAll($data, $bid, $currentUserId);
            $this->restoreUsers($data, $bid, $currentUserId);
        });
    }

    /**
     * يمحو بيانات المتجر — الأبناءَ قبل الآباء.
     *
     * وبعكس ترتيب الإدراج بالضبط: سطرٌ يُحذف قبل أبنائه يُردّ بمفتاحٍ خارجيّ
     * — و`supplier_invoices` مرتبطٌ بمورّده بـ`restrict`، فحذفُ المورّدين
     * قبله كان **يُسقط الاستعادة كلَّها** بعد أن حذفت المنتجات.
     *
     * والمحوُ نهائيّ لا ناعم: الاستعادةُ تحلّ محلّ ما كان، وصفٌّ مخفيٌّ يظهر
     * في «المحذوفات» بعدها فيستعيده التاجر — فيصير لكلّ منتجٍ نسختان.
     *
     * والموظّفون لا يُمحون: صاحبُ النشاط يفقد حسابه في منتصف الاستعادة.
     */
    private function wipe(int $bid): void
    {
        foreach (array_reverse(TenantTables::all()) as $table) {
            if ($table === 'users' || ! Schema::hasTable($table)) {
                continue;
            }

            TenantTables::scope($table, $bid)->delete();
        }
    }

    /** حقولُ ملفّ المتجر الآمنة — لا الباقةُ ولا الاشتراك */
    private function restoreBusiness(array $data, int $bid): void
    {
        if (empty($data['business']) || ! is_array($data['business'])) {
            return;
        }

        Business::where('id', $bid)->update(
            collect($data['business'])->only(Business::BACKUP_FIELDS)->all()
        );
    }

    /**
     * يُدرج الجداول بترتيبها — والمؤجَّلُ يُكتب في جولةٍ ثانية.
     *
     * `websites.published_version_id` يشير إلى نسخةٍ لم تُدرج بعد، ونسخُها
     * تشير إليه: حلقةٌ لا يحلّها ترتيب. فيُدرَج بلا مؤشّرٍ ثمّ يُعاد إليه.
     */
    private function insertAll(array $data, int $bid, ?int $currentUserId): void
    {
        $deferred = [];

        foreach (TenantTables::all() as $table) {
            if ($table === 'users' || ! Schema::hasTable($table)) {
                continue;
            }

            $rows = $data[$table] ?? [];

            if (! is_array($rows) || $rows === []) {
                continue;
            }

            $columns = array_flip(array_column(Schema::getColumns($table), 'name'));
            $hold = TenantTables::DEFERRED[$table] ?? [];
            $clean = [];

            foreach ($rows as $row) {
                if (! is_array($row)) {
                    continue;
                }

                // عمودٌ في الملفّ لا وجود له في القاعدة اليوم يُسقط الإدراج
                $row = array_intersect_key($row, $columns);

                if (isset($columns['business_id'])) {
                    $row['business_id'] = $bid;
                }

                foreach ($hold as $column) {
                    if (($row[$column] ?? null) !== null) {
                        $deferred[$table][$row['id']][$column] = $row[$column];
                        $row[$column] = null;
                    }
                }

                $clean[] = $row;
            }

            foreach (array_chunk($clean, 500) as $chunk) {
                DB::table($table)->insert($chunk);
            }
        }

        foreach ($deferred as $table => $rows) {
            foreach ($rows as $id => $values) {
                DB::table($table)->where('id', $id)->update($values);
            }
        }
    }

    /**
     * الموظّفون: تحديثٌ وإضافةٌ بلا حذف.
     *
     * ولا تُستورد كلمات المرور — ليست في الملفّ أصلًا. فالحسابُ القائم يبقى
     * بكلمته، والجديدُ يُنشأ بكلمةٍ عشوائية تُلزم صاحبَها بإعادة تعيينها.
     * وحسابُ من ينفّذ الاستعادة لا يُمسّ: لا يُطرد أحدٌ في منتصف عمله.
     */
    private function restoreUsers(array $data, int $bid, ?int $currentUserId): void
    {
        foreach ($data['users'] ?? [] as $row) {
            if (! is_array($row) || empty($row['email'])) {
                continue;
            }

            unset($row['password'], $row['remember_token'], $row['id']);

            $existing = User::where('email', $row['email'])->first();

            if (! $existing) {
                $row['business_id'] = $bid;
                $row['password'] = bcrypt(Str::random(40));
                User::create($row);

                continue;
            }

            if ($existing->id === $currentUserId) {
                continue;
            }

            $existing->update(collect($row)->except(['business_id'])->all());
        }
    }
}
