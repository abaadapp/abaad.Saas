<?php

namespace App\Support;

use App\Models\CustomerInvoice;
use App\Models\CustomerInvoicePreparation;
use App\Models\PosDevice;
use App\Models\PosPeripheral;
use App\Models\PrintJob;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * طابورُ الطباعة — ورقةٌ في الخادم تنتظر صندوقَها.
 *
 * ═══ لمَ طابور ═══
 *
 * إشعارُ Paymob يصل الخادم، والطابعةُ على شبكة المحلّ لا يبلغها الخادمُ
 * أبدًا (`WebsiteConfirmPrint` يقولها). ولا يستقبل تطبيقُ أبعاد شيئًا وهو
 * مغلقٌ أو في الخلفيّة: يطبع حين تطلب صفحتُه المفتوحة. فالورقةُ تُكتب هنا
 * **مهمّةً** لا تضيع، والصندوقُ المعيَّن يسألها حين تكون صفحتُه أمامه.
 *
 * ═══ ما يُضمن ═══
 *
 * - **مهمّةٌ منطقيّةٌ واحدة** لكلّ ورقة: `dedupe_key` فريدٌ في القاعدة.
 * - **صندوقٌ واحدٌ يحجزها**: الحجزُ شرطٌ في UPDATE واحد (`pending` ← `claimed`)،
 *   فصندوقان يسألان في اللحظة نفسِها يأخذها أحدُهما.
 * - **ولا تُطبع مرّتين في صمت**: حجزٌ لم يُردّ عليه في مهلته قد يكون خرج،
 *   فلا يُعاد وحده — يصير «فشل» ويُعاد بيدٍ بعد أن يُنظر في الورق.
 *   وفشلٌ يقول الصندوقُ إنّه لم يُطبع منه شيء (`may_have_printed = false`)
 *   يعود إلى الطابور، حتى `MAX_ATTEMPTS`.
 * - **وفتحُ حوار الطباعة ليس ورقًا**: «طُبعت» لطابعة الشبكة وحدها، أو لإنسانٍ
 *   يقولها. وما فُتح حوارُه في متصفّحٍ ينتظر «خرجت» أو «لم تخرج» (`requested`).
 * - **ولا تعلق على صندوقٍ ذهب**: صندوقٌ أُلغي أو نُقل أو رُفعت عنه طابعتُه —
 *   ورقُه المنتظِر يُوجَّه إلى صندوقٍ معيَّنٍ في **فرعها نفسِه** (`release`)،
 *   ويحجزه أيُّ صندوقٍ معيَّنٍ في فرعها إن بقي موجَّهًا إلى من لا يصلح.
 * - **ولا تضيع**: صندوقٌ مغلق، أو لا صندوقَ معيَّنًا بعد — تبقى «بانتظار
 *   الطباعة» وتُرى في لوحة التجهيز ومعها زرُّ الطباعة اليدويّة.
 * - **وكلُّ انتقالٍ يُكتب** في سجلّ النشاط على مهمّتها — يُقرأ في سجلّها في اللوحة.
 */
final class PrintJobs
{
    /** مهلةُ الحجز — أطولُ من مهلة الطابعة في التطبيق (١٢٠ ث صمت) */
    public const CLAIM_SECONDS = 180;

    /**
     * ورقةٌ لم يستلمها صندوقٌ في هذه الساعات تُقال «فشل» — لا تنتظر أبدًا.
     *
     * فرعٌ بلا صندوقٍ معيَّن، أو صندوقٌ مطفأٌ أيّامًا: كانت الورقةُ تبقى «تنتظر
     * الصندوق» بلا نهاية، ثمّ تخرج وحدَها صباحَ يفتح بعد عطلة — ورقُ أمسِ مع
     * طلبات اليوم. ولم يُطبع منها شيء، فإعادتُها من اللوحة آمنة.
     */
    public const PENDING_HOURS = 24;

    /** محاولاتٌ لم يُطبع منها شيء — بعدها يُنتظر إنسان */
    public const MAX_ATTEMPTS = 3;

    /** طرقُ الطباعة التي يُقبل ذكرُها في الردّ (`PrintVia` في الواجهة) */
    public const VIAS = ['native', 'system', 'page', 'share', 'browser'];

    /**
     * الطريقُ الوحيد الذي يُقال عنه «طُبعت» وحده: تطبيقُ أبعاد أرسل الورقةَ
     * كلَّها إلى طابعة الشبكة. وما سواه حوارٌ أو ملفّ — لا يُعرف ما جرى بعده.
     */
    public const CONFIRMED_VIA = 'native';

    /** ما يُعاد إلى الطابور بيد — لا ما يُطبع الآن، ولا ما ثبت خروجُه */
    public const REQUEUEABLE = [PrintJob::FAILED, PrintJob::REQUESTED, PrintJob::CANCELLED];

    /** ما يقول عنه إنسانٌ «خرجت» من اللوحة — لا ما يحجزه صندوقٌ الآن */
    public const CONFIRMABLE = [PrintJob::PENDING, PrintJob::REQUESTED, PrintJob::FAILED, PrintJob::CANCELLED];

    /**
     * ورقةُ «فاتورة عميل — نسخة داخلية» لمهمّةٍ ظهرت — مرّةً واحدة.
     *
     * والصندوقُ المعيَّن: طابعةٌ فعّالةٌ عليها «فواتير العملاء المسدَّدة»، في
     * صندوقٍ فعّالٍ من فرع المهمّة. وإن لم يكن بعدُ فالورقةُ لفرعها كلّه:
     * أيُّ صندوقٍ يُعيَّن فيه لاحقًا يستلمها.
     */
    public static function queueInvoiceInternal(CustomerInvoicePreparation $prep): PrintJob
    {
        $key = self::invoiceKey($prep);

        $existing = PrintJob::where('dedupe_key', $key)->first();
        if ($existing) {
            return $existing;
        }

        $device = $prep->branch_id ? self::designatedDevices((int) $prep->business_id, (int) $prep->branch_id)->first() : null;

        $job = PrintJob::create([
            'business_id' => (int) $prep->business_id,
            'branch_id' => $prep->branch_id,
            'pos_device_id' => $device?->id,
            'kind' => PrintJob::KIND_INVOICE_INTERNAL,
            'subject_type' => 'customer_invoice_preparation',
            'subject_id' => $prep->id,
            'dedupe_key' => $key,
            'status' => PrintJob::PENDING,
        ]);

        Activity::log('created', 'كُتبت ورقة «فاتورة عميل — نسخة داخلية» لطابعة الصندوق', [
            'subject_id' => $prep->id, 'subject_type' => 'customer_invoice_preparation', 'business_id' => (int) $prep->business_id,
        ]);

        return $job;
    }

    public static function invoiceKey(CustomerInvoicePreparation $prep): string
    {
        return 'customer-invoice-internal:'.$prep->id;
    }

    /**
     * ورقةٌ داخليّةٌ جديدة بعد تعديل بيانات التسليم — فالتي خرجت تحمل القديم.
     *
     * تُنادى تحت قفل صفّ المهمّة (`CustomerInvoicePreparations::save`) فلا تُكتب
     * ورقتان لتعديلٍ واحد. ومفتاحُها رقمُ نسختها بعد الأولى — والأولى باقيةٌ
     * بحالها في السجلّ، لا تُمحى ولا تُعاد. وتُقرأ اللوحةُ من أحدثها
     * (`forPreparation`)، والصندوقُ يطبعها ببيانات ساعتها.
     */
    public static function reissueInvoiceInternal(CustomerInvoicePreparation $prep): PrintJob
    {
        $edition = PrintJob::where('business_id', $prep->business_id)
            ->where('subject_type', 'customer_invoice_preparation')
            ->where('subject_id', $prep->id)
            ->count() + 1;

        $device = $prep->branch_id ? self::designatedDevices((int) $prep->business_id, (int) $prep->branch_id)->first() : null;

        $job = PrintJob::create([
            'business_id' => (int) $prep->business_id,
            'branch_id' => $prep->branch_id,
            'pos_device_id' => $device?->id,
            'kind' => PrintJob::KIND_INVOICE_INTERNAL,
            'subject_type' => 'customer_invoice_preparation',
            'subject_id' => $prep->id,
            'dedupe_key' => self::invoiceKey($prep).':'.$edition,
            'status' => PrintJob::PENDING,
        ]);

        self::audit($job, 'عُدّلت بيانات التسليم بعد طباعتها — كُتبت ورقةٌ جديدة لطابور الصندوق');

        return $job;
    }

    /** ما يتبع مهمّتَه إلى فرعها الجديد — لم يخرج ولا يحجزه صندوقٌ الآن */
    public const FOLLOWS = [PrintJob::PENDING, PrintJob::FAILED, PrintJob::CANCELLED];

    /**
     * ورقةٌ لم تخرج وقد نُقلت مهمّتُها إلى فرعٍ آخر — تتبعها إلى صندوقه.
     *
     * المنتظِرةُ تُطبع هناك. والتي فشلت أو أُلغيت تبقى بحالها، وإن أُعيدت بيد
     * (`requeue`) ذهبت إلى صندوق الفرع الجديد لا القديم: `target` يقرأ فرعَ
     * الورقة. وما يحجزه صندوقٌ الآن قد يخرج في هذه اللحظة، وما خرج تُكتب له
     * ورقةٌ جديدة. والشرطُ في الكتابة نفسِها، على الحال كما قُرئت — حجزٌ سبق
     * لا يُنتزع.
     */
    public static function followBranch(PrintJob $job, int $branchId): bool
    {
        if (! in_array($job->status, self::FOLLOWS, true)) {
            return false;
        }

        $device = self::designatedDevices((int) $job->business_id, $branchId)->first();

        $done = PrintJob::whereKey($job->id)
            ->where('status', $job->status)
            ->update(['branch_id' => $branchId, 'pos_device_id' => $device?->id, 'updated_at' => now()]);

        if ($done === 1) {
            self::audit($job, $device
                ? 'نُقلت المهمّة إلى فرعٍ آخر — الورقة لطابور صندوق «'.$device->name.'»'
                : 'نُقلت المهمّة إلى فرعٍ آخر — الورقة تنتظر صندوقًا يُعيَّن فيه');
        }

        return $done === 1;
    }

    /** آخرُ ورقةٍ لمهمّة — لتُقرأ حالُها في اللوحة */
    public static function forPreparation(CustomerInvoicePreparation $prep): ?PrintJob
    {
        // حجزٌ انقضت مهلتُه يصير «فشل» قبل أن يُسأل عنه — فيُعاد أو يُؤكَّد من اللوحة
        self::expireClaims((int) $prep->business_id);

        return PrintJob::where('business_id', $prep->business_id)
            ->where('subject_type', 'customer_invoice_preparation')
            ->where('subject_id', $prep->id)
            ->latest('id')
            ->first();
    }

    /* ═══════════════════════════ من يستلم ═══════════════════════════ */

    /**
     * صناديقُ الفرع التي تستلم فواتيرَ العملاء — فعّالةً، بطابعةٍ فعّالة.
     *
     * @return Collection<int, PosDevice>
     */
    public static function designatedDevices(int $businessId, int $branchId)
    {
        return PosDevice::where('pos_devices.business_id', $businessId)
            ->where('pos_devices.status', PosDevice::ACTIVE)
            ->where('pos_devices.branch_id', $branchId)
            ->whereHas('peripherals', fn ($p) => self::designatedPrinter($p))
            ->orderBy('pos_devices.id')
            ->get();
    }

    /** طابعةُ هذا الصندوق التي تستلم فواتيرَ العملاء — إن كانت */
    public static function printerOf(PosDevice $device): ?PosPeripheral
    {
        return self::designatedPrinter($device->peripherals()->getQuery())->orderBy('id')->first();
    }

    private static function designatedPrinter($q)
    {
        return $q->where('active', true)
            ->where('type', PosPeripheral::PRINTER)
            ->where('auto_print_customer_invoices', true);
    }

    /**
     * أيستلم هذا الصندوقُ الورقَ الآن؟
     *
     * فعّالٌ، وبطابعةٍ معيَّنة، والتجهيزُ بعد السداد مفتوحٌ لمتجره. والمفتاحُ
     * يُسأل هنا لا في الواجهة وحدها: صفحةٌ قديمةٌ مفتوحةٌ منذ الصباح على
     * الصندوق ما زالت تسأل — والخادمُ يجيب «لا شيء».
     */
    public static function serves(PosDevice $device): bool
    {
        return $device->isActive()
            && CustomerInvoicePreparations::on((int) $device->business_id)
            && self::printerOf($device) !== null;
    }

    /**
     * الصناديقُ التي تصلح لهذه الورقة الآن — متجرُها وفرعُها.
     *
     * وورقةٌ بلا فرع (حُذف فرعُها) لا يصلح لها صندوق: لا تُطبع في فرعٍ لم
     * تُطلب فيه — تُطبع بيدٍ من اللوحة.
     *
     * @return Collection<int, PosDevice>
     */
    public static function eligibleFor(PrintJob $job)
    {
        return $job->branch_id ? self::designatedDevices((int) $job->business_id, (int) $job->branch_id) : collect();
    }

    /**
     * إلى أيّ صندوقٍ تُوجَّه الآن: صندوقُها إن بقي يصلح، وإلّا أوّلُ صندوقٍ
     * معيَّنٍ في فرعها، وإلّا لا أحد — فيستلمها أوّلُ صندوقٍ يُعيَّن فيه.
     */
    public static function target(PrintJob $job): ?int
    {
        $eligible = self::eligibleFor($job);

        if ($job->pos_device_id !== null && $eligible->contains('id', (int) $job->pos_device_id)) {
            return (int) $job->pos_device_id;
        }

        return $eligible->first()?->id;
    }

    /**
     * صندوقٌ لم يعد يستلم — أُلغي، أو نُقل إلى فرعٍ آخر، أو رُفعت عنه طابعتُه،
     * أو عُطّلت: ورقُه المنتظِر يُوجَّه إلى صندوقٍ معيَّنٍ في فرعها نفسِه، أو
     * يبقى لفرعها كلِّه حتى يُعيَّن فيه صندوق.
     *
     * المنتظِرُ وحده (`pending`): ما يحجزه الصندوقُ الآن قد يكون يخرج —
     * فينقضي حجزُه «فشلًا» ويُعاد بيد. وما فشل أو فُتح حوارُه لا يُمسّ هنا:
     * لا يُعاد شيءٌ منه وحده.
     *
     * والكتابةُ مشروطةٌ بالحال كما قُرئت: ورقةٌ حجزها صندوقٌ بين القراءة
     * والكتابة لا تُوجَّه من تحته.
     *
     * @return int كم ورقةً وُجّهت
     */
    public static function release(PosDevice $device): int
    {
        $moved = 0;

        $jobs = PrintJob::where('business_id', $device->business_id)
            ->where('status', PrintJob::PENDING)
            ->where('pos_device_id', $device->id)
            ->orderBy('id')
            ->get();

        foreach ($jobs as $job) {
            $to = self::target($job);
            if ($to === (int) $device->id) {
                continue;
            }

            $done = PrintJob::whereKey($job->id)
                ->where('status', PrintJob::PENDING)
                ->where('pos_device_id', $device->id)
                ->update(['pos_device_id' => $to, 'updated_at' => now()]);

            if ($done !== 1) {
                continue;
            }

            $moved++;
            $name = $to ? PosDevice::whereKey($to)->value('name') : null;
            self::audit($job, $name
                ? 'لم يعد صندوق «'.$device->name.'» يستلمها — وُجّهت إلى «'.$name.'» في فرعها'
                : 'لم يعد صندوق «'.$device->name.'» يستلمها — تنتظر صندوقًا يُعيَّن في فرعها');
        }

        return $moved;
    }

    /* ═══════════════════════════ الصندوق ═══════════════════════════ */

    /** ما ينتظر هذا الصندوق — لا يحجز شيئًا */
    public static function waitingFor(PosDevice $device): int
    {
        return self::serves($device) ? self::candidates($device)->count() : 0;
    }

    /**
     * ما فتح هذا الصندوقُ حوارَه ولم يُقل أخرج — يُسأل عنه الكاشير.
     *
     * @return Collection<int, PrintJob>
     */
    public static function awaitingConfirmation(PosDevice $device)
    {
        if (! self::serves($device)) {
            return collect();
        }

        return PrintJob::where('business_id', $device->business_id)
            ->where('status', PrintJob::REQUESTED)
            ->where('claimed_by_device_id', $device->id)
            ->orderBy('id')
            ->limit(10)
            ->get();
    }

    /**
     * ما يحجزه هذا الصندوق: ورقُ فرعه المنتظِر — الموجَّهُ إليه، أو إلى لا
     * أحد، أو إلى صندوقٍ لم يعد يصلح لها (أُلغي، نُقل، رُفعت طابعتُه).
     *
     * والفرعُ شرطٌ دائمًا، ولو كانت موجَّهةً إليه: صندوقٌ نُقل إلى فرعٍ آخر
     * وأُعيد تفعيلُه هناك لا يحمل معه ورقَ فرعه القديم.
     */
    private static function candidates(PosDevice $device)
    {
        $here = self::designatedDevices((int) $device->business_id, (int) $device->branch_id)->pluck('id')->all();

        return PrintJob::where('business_id', $device->business_id)
            ->where('status', PrintJob::PENDING)
            ->where('branch_id', $device->branch_id)
            ->where(fn ($t) => $t->whereNull('pos_device_id')
                ->orWhere('pos_device_id', $device->id)
                ->orWhereNotIn('pos_device_id', $here));
    }

    /**
     * يحجز الصندوقُ ورقتَه التالية — أو لا شيء.
     *
     * والحجزُ شرطٌ في UPDATE واحد: `status = pending` وصندوقُها كما قُرئ.
     * فصندوقان يسألان معًا عن الورقة نفسِها: يكتب أحدُهما، ويجد الآخرُ الحالَ
     * تغيّرت فيمضي إلى غيرها. ولا قفلَ يُنتظر.
     *
     * وورقةٌ كانت لصندوقٍ لم يعد يصلح تُوجَّه إلى من حجزها — ويُكتب ذلك.
     */
    public static function claim(PosDevice $device): ?PrintJob
    {
        self::expireClaims((int) $device->business_id);

        if (! self::serves($device)) {
            return null;
        }

        foreach (self::candidates($device)->orderBy('id')->limit(10)->get(['id', 'pos_device_id']) as $row) {
            $from = $row->pos_device_id !== null ? (int) $row->pos_device_id : null;

            $won = PrintJob::whereKey($row->id)
                ->where('status', PrintJob::PENDING)
                ->where(fn ($w) => $from === null ? $w->whereNull('pos_device_id') : $w->where('pos_device_id', $from))
                ->update([
                    'status' => PrintJob::CLAIMED,
                    'pos_device_id' => $device->id,
                    'claimed_by_device_id' => $device->id,
                    'claimed_at' => now(),
                    'claim_expires_at' => now()->addSeconds(self::CLAIM_SECONDS),
                    'attempts' => DB::raw('attempts + 1'),
                    'updated_at' => now(),
                ]);

            if ($won === 1) {
                $job = PrintJob::find($row->id);
                self::audit($job, $from !== null && $from !== (int) $device->id
                    ? 'صندوقُها لم يعد يستلمها — حجزها صندوق «'.$device->name.'» في فرعها ليطبعها'
                    : 'حجزها صندوق «'.$device->name.'» ليطبعها');

                return $job;
            }
        }

        return null;
    }

    /**
     * يقول الصندوقُ ما جرى للورقة التي حجزها.
     *
     * - أرسلها تطبيقُ أبعاد كلَّها إلى طابعة الشبكة ← `printed`.
     * - فُتح لها حوارُ طباعةٍ أو ملفّ (متصفّح، أو حوارُ النظام) ← `requested`:
     *   فتحُه ليس ورقًا. ينتظر «خرجت» أو «لم تخرج»، ولا يُعاد وحده.
     * - لم تخرج، ويقول إنّه لم يُرسل منها شيء ← تعود إلى الطابور، حتى
     *   `MAX_ATTEMPTS`؛ ثمّ `failed`. وإن أُغلق التجهيزُ في أثنائها ← `cancelled`.
     * - لم تخرج، وقد يكون خرج بعضُها ← `failed`: لا تُعاد وحدها.
     *
     * ولا يقول إلّا صندوقُها: حجزُ غيره لا يُكتب عليه. ويُقبل الردُّ والمفتاحُ
     * مغلق: ورقةٌ حُجزت قبل الإغلاق يُكتب ما جرى لها.
     */
    public static function acknowledge(PrintJob $job, PosDevice $device, bool $ok, ?string $via, ?string $error, bool $mayHavePrinted): bool
    {
        return DB::transaction(function () use ($job, $device, $ok, $via, $error, $mayHavePrinted) {
            $locked = PrintJob::whereKey($job->id)->lockForUpdate()->first();

            if (! $locked || $locked->status !== PrintJob::CLAIMED || (int) $locked->claimed_by_device_id !== (int) $device->id) {
                return false;
            }

            $via = in_array($via, self::VIAS, true) ? $via : null;

            if ($ok && $via === self::CONFIRMED_VIA) {
                $locked->forceFill([
                    'status' => PrintJob::PRINTED,
                    'printed_at' => now(),
                    'via' => $via,
                    'last_error' => null,
                    'claim_expires_at' => null,
                ])->save();
                self::audit($locked, 'طبعها صندوق «'.$device->name.'» على طابعته');

                return true;
            }

            if ($ok) {
                $locked->forceFill([
                    'status' => PrintJob::REQUESTED,
                    'requested_at' => now(),
                    'via' => $via,
                    'last_error' => null,
                    'claim_expires_at' => null,
                ])->save();
                self::audit($locked, 'فُتح حوار طباعتها على صندوق «'.$device->name.'» — بانتظار تأكيد خروج الورقة');

                return true;
            }

            $retry = ! $mayHavePrinted && $locked->attempts < self::MAX_ATTEMPTS;
            $status = match (true) {
                ! $retry => PrintJob::FAILED,
                ! CustomerInvoicePreparations::on((int) $locked->business_id) => PrintJob::CANCELLED,
                default => PrintJob::PENDING,
            };

            $locked->forceFill([
                'status' => $status,
                'failed_at' => $status === PrintJob::FAILED ? now() : null,
                'cancelled_at' => $status === PrintJob::CANCELLED ? now() : null,
                'via' => $via,
                'last_error' => mb_substr((string) ($error ?: __('لم تُطبع الورقة')), 0, 255),
                'claimed_by_device_id' => $status === PrintJob::PENDING ? null : $locked->claimed_by_device_id,
                'claim_expires_at' => null,
            ])->save();

            self::audit($locked, match ($status) {
                PrintJob::PENDING => 'لم تخرج على صندوق «'.$device->name.'» ولم يُرسل منها شيء — عادت إلى طابوره',
                PrintJob::CANCELLED => 'لم تخرج على صندوق «'.$device->name.'» — وأُغلق التجهيز بعد السداد فلم تعد إلى الطابور',
                default => $mayHavePrinted
                    ? 'لم يكتمل إرسالها على صندوق «'.$device->name.'» وقد يكون خرج بعضها — لا تُعاد وحدها'
                    : 'لم تخرج على صندوق «'.$device->name.'» بعد '.$locked->attempts.' محاولات — لا تُعاد وحدها',
            });

            return true;
        });
    }

    /**
     * حجوزٌ لم يُردّ عليها في مهلتها — «فشل» لا عودةٌ صامتة.
     *
     * صندوقٌ حجز ورقةً ثمّ انقطع: لا يُعرف أخرجت أم لا. وإعادتُها وحدها قد
     * تطبعها مرّتين — فتُرى في اللوحة «لم يؤكَّد خروجُها» ويقرّر إنسان.
     */
    public static function expireClaims(int $businessId): int
    {
        $expired = PrintJob::where('business_id', $businessId)
            ->where('status', PrintJob::CLAIMED)
            ->where('claim_expires_at', '<', now())
            ->orderBy('id')
            ->get();

        $n = self::expirePending($businessId);

        foreach ($expired as $job) {
            $done = PrintJob::whereKey($job->id)
                ->where('status', PrintJob::CLAIMED)
                ->where('claim_expires_at', '<', now())
                ->update([
                    'status' => PrintJob::FAILED,
                    'failed_at' => now(),
                    'last_error' => __('لم يؤكّد الصندوق خروج الورقة في مهلتها — تحقّق من الطابعة ثم أعد الإرسال.'),
                    'claim_expires_at' => null,
                    'updated_at' => now(),
                ]);

            if ($done === 1) {
                $n++;
                self::audit($job, 'لم يؤكّد الصندوق خروجها في مهلتها — لا تُعاد وحدها');
            }
        }

        return $n;
    }

    /** ورقٌ لم يُحجز في مهلته — «فشل» بسببه، يُعاد من اللوحة بضغطة */
    private static function expirePending(int $businessId): int
    {
        $stale = PrintJob::where('business_id', $businessId)
            ->where('status', PrintJob::PENDING)
            ->where('updated_at', '<', now()->subHours(self::PENDING_HOURS))
            ->orderBy('id')
            ->get();

        $n = 0;

        foreach ($stale as $job) {
            $done = PrintJob::whereKey($job->id)
                ->where('status', PrintJob::PENDING)
                ->where('updated_at', '<', now()->subHours(self::PENDING_HOURS))
                ->update([
                    'status' => PrintJob::FAILED,
                    'failed_at' => now(),
                    'last_error' => __('لم يستلمها صندوق خلال :hours ساعة — لم يُطبع منها شيء. تحقّق من صندوق الفرع وطابعته ثم أعد الإرسال.', ['hours' => self::PENDING_HOURS]),
                    'updated_at' => now(),
                ]);

            if ($done === 1) {
                $n++;
                self::audit($job, 'لم يستلمها صندوق في مهلتها — لم يُطبع منها شيء');
            }
        }

        return $n;
    }

    /**
     * «لم تخرج» — يقولها الصندوقُ الذي فتح حوارَها: ألغى الكاشيرُ الحوار، أو
     * لم تطبع الطابعة. فتعود إلى طابوره لتُطبع **بضغطةٍ أخرى** — لا وحدها.
     */
    public static function notPrinted(PrintJob $job, PosDevice $device): bool
    {
        $to = self::target($job);

        $done = PrintJob::whereKey($job->id)
            ->where('status', PrintJob::REQUESTED)
            ->where('claimed_by_device_id', $device->id)
            ->update([
                'status' => PrintJob::PENDING,
                'pos_device_id' => $to,
                'claimed_by_device_id' => null,
                'claimed_at' => null,
                'requested_at' => null,
                'last_error' => __('قال الصندوق إن الورقة لم تخرج.'),
                'updated_at' => now(),
            ]);

        if ($done === 1) {
            self::audit($job, 'قال الكاشير على صندوق «'.$device->name.'» إنّها لم تخرج — عادت إلى الطابور');
        }

        return $done === 1;
    }

    /**
     * «خرجت الورقة» — بقول إنسانٍ نظر في الطابعة.
     *
     * - من الصندوق (`$device`): لما فتح هو حوارَه وحده.
     * - من اللوحة: لما ينتظر (طُبعت بيدٍ من زرّ اللوحة، فلا يطبعها الصندوقُ
     *   بعدُ)، أو فُتح حوارُه، أو فشل، أو أُلغي. ولا لما يحجزه صندوقٌ الآن:
     *   الشرطُ في الكتابة نفسِها، فاللوحةُ والصندوقُ في اللحظة نفسِها يكسب
     *   أحدُهما ولا يُكتب الآخر.
     */
    public static function confirmPrinted(PrintJob $job, int $userId, ?PosDevice $device = null): bool
    {
        $q = PrintJob::whereKey($job->id);

        if ($device) {
            $q->where('status', PrintJob::REQUESTED)->where('claimed_by_device_id', $device->id);
        } else {
            $q->whereIn('status', self::CONFIRMABLE);
        }

        $done = $q->update([
            'status' => PrintJob::PRINTED,
            'printed_at' => now(),
            'confirmed_at' => now(),
            'confirmed_by' => $userId,
            'claim_expires_at' => null,
            'last_error' => null,
            'updated_at' => now(),
        ]);

        if ($done === 1) {
            self::audit($job, $device
                ? 'أكّد الكاشير على صندوق «'.$device->name.'» خروج الورقة'
                : 'أُكّد من لوحة التجهيز خروج الورقة — لا تُطبع على الصندوق');
        }

        return $done === 1;
    }

    /**
     * يُعيدها إلى الطابور بيدٍ مأذونة — بعد أن نظر في الورق.
     *
     * ما فشل، أو فُتح حوارُه ولم يُؤكَّد، أو أُلغي حين أُغلق التجهيز. ولا
     * تُعاد ورقةٌ يحجزها صندوقٌ الآن (قد تكون تخرج في هذه اللحظة)، ولا ورقةٌ
     * ثبت خروجُها — إعادةُ طباعتها بزرّ اللوحة، لا بطابورٍ يطبع وحده.
     *
     * وتُوجَّه من جديد: صندوقُها إن بقي يصلح، وإلّا صندوقٌ معيَّنٌ في فرعها.
     */
    public static function requeue(PrintJob $job, int $userId): bool
    {
        $to = self::target($job);

        $done = PrintJob::whereKey($job->id)
            ->whereIn('status', self::REQUEUEABLE)
            ->update([
                'status' => PrintJob::PENDING,
                'pos_device_id' => $to,
                'attempts' => 0,
                'claimed_by_device_id' => null,
                'claimed_at' => null,
                'claim_expires_at' => null,
                'requested_at' => null,
                'failed_at' => null,
                'cancelled_at' => null,
                'last_error' => null,
                'requeued_by' => $userId,
                'updated_at' => now(),
            ]);

        if ($done === 1) {
            $name = $to ? PosDevice::whereKey($to)->value('name') : null;
            self::audit($job, $name
                ? 'أُعيدت إلى طابور صندوق «'.$name.'»'
                : 'أُعيدت إلى الطابور — تنتظر صندوقًا يُعيَّن في فرعها');
        }

        return $done === 1;
    }

    /**
     * أُغلق التجهيزُ بعد السداد لمتجر: ما ينتظر يُلغى — ولا يعود وحده إن فُتح.
     *
     * - المنتظِرُ ← `cancelled`: فتحُ المفتاح بعد أسبوعٍ لا يطبع ورقَ أسبوعٍ مضى
     *   على صندوقٍ لا يتوقّعه. ويُعاد بيدٍ من اللوحة إن لزم.
     * - وما يحجزه صندوقٌ الآن لا يُمسّ: قد يكون يخرج. وردُّه يُقبل ويُكتب
     *   (`acknowledge`) — فإن لم يُطبع منه شيء أُلغي ولم يعد إلى الطابور.
     * - وما فُتح حوارُه أو فشل يبقى كما هو: لا شيءَ فيه يُطبع وحده.
     *
     * ولا يمسّ دفعًا ولا فاتورةً ولا دفترًا ولا مخزونًا.
     */
    public static function cancelPending(int $businessId): int
    {
        $n = 0;

        foreach (PrintJob::where('business_id', $businessId)->where('status', PrintJob::PENDING)->orderBy('id')->get() as $job) {
            $done = PrintJob::whereKey($job->id)
                ->where('status', PrintJob::PENDING)
                ->update([
                    'status' => PrintJob::CANCELLED,
                    'cancelled_at' => now(),
                    'last_error' => __('أُغلق التجهيز بعد السداد لهذا النشاط قبل أن تُطبع.'),
                    'updated_at' => now(),
                ]);

            if ($done === 1) {
                $n++;
                self::audit($job, 'أُلغيت قبل أن تُطبع — أُغلق التجهيز بعد السداد لهذا النشاط');
            }
        }

        return $n;
    }

    /* ═══════════════════════════ القراءة ═══════════════════════════ */

    /** حالُ الورقة كما تُقرأ في اللوحة — بلا وعدٍ بما لا يُعرف */
    public static function label(?PrintJob $job): ?string
    {
        if (! $job) {
            return null;
        }

        return match ($job->status) {
            PrintJob::PENDING => __('بانتظار الطباعة على الصندوق'),
            PrintJob::CLAIMED => __('يطبعها الصندوق الآن'),
            PrintJob::REQUESTED => __('فُتح حوار الطباعة على الصندوق — لم يُؤكَّد خروج الورقة'),
            PrintJob::PRINTED => $job->confirmed_at !== null
                ? __('أُكّد خروج الورقة')
                : __('طُبعت على طابعة الصندوق'),
            PrintJob::FAILED => __('لم تُطبع — أعد الإرسال إلى الصندوق أو اطبعها يدويًا'),
            PrintJob::CANCELLED => __('أُلغيت حين أُغلق التجهيز بعد السداد — أعد الإرسال أو اطبعها يدويًا'),
            default => null,
        };
    }

    /**
     * الورقةُ كما تُرسل إلى اللوحة وصفحة الفاتورة — حالُها، وما يجوز فيها.
     *
     * والجوازُ من هنا وحده: الزرُّ يُعرض لما يقبله الخادم، ولا يُخفى عمّا يقبله.
     *
     * @return array{status: string, label: ?string, error: ?string, confirmable: bool, requeueable: bool}|null
     */
    public static function card(?PrintJob $job): ?array
    {
        if (! $job) {
            return null;
        }

        return [
            'status' => $job->status,
            'label' => self::label($job),
            'error' => in_array($job->status, [PrintJob::PENDING, PrintJob::FAILED, PrintJob::CANCELLED], true) ? $job->last_error : null,
            'confirmable' => in_array($job->status, self::CONFIRMABLE, true),
            'requeueable' => in_array($job->status, self::REQUEUEABLE, true),
        ];
    }

    /** رقمُ فاتورة الورقة — في متجرها وحده */
    public static function numberOf(PrintJob $job): ?string
    {
        $prep = CustomerInvoicePreparation::where('business_id', $job->business_id)->find($job->subject_id);

        return $prep
            ? CustomerInvoice::where('business_id', $job->business_id)->whereKey($prep->customer_invoice_id)->value('number')
            : null;
    }

    /**
     * كلُّ انتقالٍ يُكتب على مهمّة الورقة — ومن فعله يكتبه السجلّ نفسُه.
     *
     * والفعلُ `status` كانتقالات المهمّة: فيُقرأ في سجلّها في اللوحة
     * (`PreparationController::invoiceTimeline`) لا في شاشة النشاط وحدها.
     */
    private static function audit(PrintJob $job, string $what): void
    {
        $number = self::numberOf($job);

        Activity::log('status', 'ورقة الصندوق'.($number ? ' للفاتورة '.$number : '').': '.$what, [
            'subject_id' => (int) $job->subject_id,
            'subject_type' => (string) $job->subject_type,
            'business_id' => (int) $job->business_id,
        ]);
    }
}
