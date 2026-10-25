<?php

namespace App\Support;

use App\Models\Business;
use App\Models\Plan;
use App\Models\PlatformDocument;
use App\Models\PlatformDocumentTemplate;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * مستنداتُ أبعاد مع عملائها — يُصدرها مدير المنصّة ويوقّعها العميلُ برابط.
 *
 * ═══ الإصدار ═══
 *
 * يُنسخ القالبُ في المستند ساعةَ الإصدار: عنوانُه وبنودُه، والباقةُ بسعر دورتها
 * يومَها، ومدّةُ الاشتراك بدايةً ونهاية. فلا يغيّر تعديلُ القالب أو سعرِ الباقة
 * غدًا عقدًا أُرسل.
 *
 * ═══ المشاركة ═══
 *
 * المديرُ ينسخ الرابطَ أو يفتح به واتساب من جهازه — ويُسجَّل فعلُه ذاك (`share`)،
 * ولا يُقرأ دليلَ إرسالٍ ولا وصول. والنتيجةُ واحدةٌ أيًّا كانت الطريق.
 *
 * ═══ التوقيع ═══
 *
 * الرابطُ وحده مفتاحُه (٣٢ حرفًا لا تُخمَّن، وصلاحيّةٌ محدودة). يكمل العميلُ
 * بياناته ويقرّ ويرسم توقيعه، فيُقفل المستند في معاملةٍ واحدة تحت قفل الصفّ:
 * الحالةُ «موقَّع»، والتوقيعُ صورةٌ خاصّة، والإقرارُ بنصّه ووقته، وعنوانُ الجهاز
 * ومتصفّحُه، وبصمةُ sha256 لكلّ ما وُقِّع عليه. ضغطتان لا تكتبان توقيعين.
 *
 * وبعد المعاملة تُحفظ الورقةُ الموقَّعة كما هي (`archive`) — وفشلُ حفظها لا يردّ
 * التوقيع: تُرسم عند أوّل طلبٍ لها من البيانات الموقَّعة نفسها.
 *
 * ولا يمدّد التوقيعُ اشتراكًا ولا يكتب فاتورة: التمديدُ عند سداد فاتورة التجديد.
 *
 * ═══ النماذج ═══
 *
 * قالبٌ بنموذج (`form`، انظر `DocumentForms`) يُنسخ نموذجُه في المستند مع حقول
 * الفريق (`team`) ساعةَ الإصدار، وإجاباتُ العميل (`answers`) ساعةَ التوقيع. وإن
 * سأل النموذجُ العميلَ عن بياناته بحقوله فهي بيانُه، لا `FIELDS`.
 *
 * ═══ الاعتماد ═══
 *
 * لا يُصدر إلّا من قالبٍ معتمدٍ نصُّه كما هو الآن (`PlatformDocumentTemplate::approved`).
 * ورسومُه كما اعتُمدت: الثابتةُ هي المبلغ، و«يبدأ من» حدُّه الأدنى. ومقدّمُ الخدمة
 * بما كُتب من بياناته نسخةٌ في المستند ساعتَها — وما لم يُكتب (السجلّ، العنوان) لا
 * يُطبع، ولا يمنع اعتمادًا ولا إصدارًا: قرارُ المالك.
 */
class PlatformDocuments
{
    /** بيانُ العميل في العقد — كما صُمِّم */
    public const FIELDS = [
        ['k' => 'name', 'ar' => 'اسم العميل', 'en' => 'Full name', 'req' => true],
        ['k' => 'biz', 'ar' => 'اسم النشاط', 'en' => 'Business name', 'req' => true],
        ['k' => 'cr', 'ar' => 'السجل التجاري', 'en' => 'CR number', 'req' => true, 'dir' => 'ltr'],
        ['k' => 'civil', 'ar' => 'الرقم المدني', 'en' => 'Civil ID', 'req' => true, 'dir' => 'ltr'],
        ['k' => 'phone', 'ar' => 'الهاتف', 'en' => 'Phone', 'req' => true, 'dir' => 'ltr', 'type' => 'tel'],
        ['k' => 'email', 'ar' => 'البريد الإلكتروني', 'en' => 'Email', 'req' => false, 'dir' => 'ltr', 'type' => 'email'],
        ['k' => 'addr', 'ar' => 'العنوان', 'en' => 'Address', 'req' => false],
        ['k' => 'branches', 'ar' => 'عدد الفروع', 'en' => 'Branches', 'req' => false, 'dir' => 'ltr', 'type' => 'number'],
    ];

    /**
     * دوراتُ الاشتراك — بأسعار `Billing::price` ومُدَد `Billing::extend` نفسها.
     *
     * نصفُ السنة بسعر الشهر ستَّ مرّات، والسنةُ بسعرها المعتمد في الباقة.
     */
    public const CYCLES = [
        'monthly' => ['label' => 'شهري', 'en' => 'Monthly', 'unit' => 'شهريًا', 'months' => 1, 'duration' => 'شهر واحد'],
        'semiannual' => ['label' => 'نصف سنوي', 'en' => 'Semi-annual', 'unit' => 'كل ستة أشهر', 'months' => 6, 'duration' => '6 أشهر'],
        'yearly' => ['label' => 'سنوي', 'en' => 'Yearly', 'unit' => 'سنويًا', 'months' => 12, 'duration' => '12 شهرًا'],
    ];

    /** نصُّ الإقرار كما يقرؤه العميل بجانب خانته — ويُحفظ معه حين يوقّع */
    public const CONSENT = 'أقر بأن البيانات أعلاه صحيحة، وأوافق على الشروط والأحكام الواردة في هذا المستند، وأن رسم توقيعي هنا وضغطي «إرسال التوقيع» توقيع مني على هذا المستند.';

    /** أيّامُ صلاحية الرابط إن لم يُحدَّد غيرُها */
    public const DEFAULT_DAYS = 14;

    /** أكبرُ صورةِ توقيعٍ تُقبل — رسمُ إصبعٍ على لوحةٍ لا يبلغها */
    public const MAX_SIGNATURE_BYTES = 400_000;

    public static function keys(): array
    {
        return array_column(self::FIELDS, 'k');
    }

    /** نسخةُ الباقة في المستند: اسمُها وحدودُها وسعرُ دورتها اليوم */
    public static function planSnapshot(Plan $plan, string $cycle): array
    {
        $cycle = array_key_exists($cycle, self::CYCLES) ? $cycle : 'monthly';
        $price = match ($cycle) {
            'yearly' => (float) $plan->yearly_price,
            'semiannual' => round((float) $plan->monthly_price * 6, 3),
            default => (float) $plan->monthly_price,
        };

        return [
            'plan_id' => $plan->id,
            'name' => $plan->name,
            'cycle' => $cycle,
            'cycle_label' => self::CYCLES[$cycle]['label'],
            'unit' => self::CYCLES[$cycle]['unit'],
            'duration' => self::CYCLES[$cycle]['duration'],
            'price' => number_format($price, 3, '.', ''),
            'limits' => self::limits($plan),
        ];
    }

    /** «حتى 10 فروع · 100 مستخدم · منتجات غير محدودة» — من أعمدة الباقة لا من نصّها */
    public static function limits(Plan $plan): string
    {
        $part = fn ($n, string $one, string $many) => (int) $n === 1 ? $one : 'حتى '.number_format((int) $n).' '.$many;

        return implode(' · ', [
            $plan->max_branches === null ? 'فروع غير محدودة' : $part($plan->max_branches, 'فرع واحد', 'فروع'),
            $plan->max_employees === null ? 'مستخدمون بلا حد' : $part($plan->max_employees, 'مستخدم واحد', 'مستخدم'),
            $plan->max_products === null ? 'منتجات غير محدودة' : $part($plan->max_products, 'منتج واحد', 'منتج'),
        ]);
    }

    /**
     * أوّلُ يومٍ في مدّةٍ جديدة — كما يبدؤها سدادُ التجديد (`Billing::markPaid`):
     * من نهاية الاشتراك الجاري إن لم تمضِ، وإلّا من اليوم.
     */
    public static function defaultStart(?Business $business): Carbon
    {
        return $business?->ends_at && $business->ends_at->isFuture()
            ? $business->ends_at->copy()->startOfDay()
            : now()->startOfDay();
    }

    /** نهايةُ المدّة من بدايتها ودورتها — بلا فيضٍ إلى الشهر التالي */
    public static function termEnd(Carbon $start, string $cycle): Carbon
    {
        return Carbon::instance(Billing::extend($start, $cycle))->startOfDay();
    }

    /** ما يُعرف عن نشاطٍ قائم — ويكمل العميلُ الباقي (السجلّ والرقم المدنيّ) */
    public static function prefill(Business $business): array
    {
        return array_filter([
            'name' => $business->owner_name,
            'biz' => $business->name,
            'phone' => $business->phone,
            'email' => $business->email,
            'addr' => trim(implode('، ', array_filter([$business->city, $business->address]))) ?: null,
            'branches' => (string) $business->branches()->count() ?: null,
        ], fn ($v) => $v !== null && $v !== '');
    }

    /** يُبقي من بيان العميل الحقولَ المعروفة وحدها، نصوصًا مشذّبة */
    public static function clean(array $client): array
    {
        $out = [];
        foreach (self::keys() as $k) {
            $v = trim((string) ($client[$k] ?? ''));
            if ($v !== '') {
                $out[$k] = mb_substr($v, 0, 190);
            }
        }

        return $out;
    }

    /**
     * يُصدر مستندًا من قالب — ويعيده برابطه.
     *
     * وعقدُ التجديد (`renews_document_id`) يبدأ من عقدٍ موقَّع: بيانُ عميله وباقتُه
     * ودورتُه أساسٌ يعدّله المدير، ومدّتُه تبدأ حيث انتهت مدّةُ سابقه. ولا يُصدر
     * تجديدٌ ثانٍ لعقدٍ له تجديدٌ قائم.
     *
     * @param  array{template_id:int, business_id?:?int, client?:array, plan_id?:?int, cycle?:?string, term_starts_on?:?string, term_ends_on?:?string, renews_document_id?:?int, amount?:mixed, amount_label?:?string, days?:?int, notes?:?string, team?:array}  $data
     */
    public static function issue(User $by, array $data): PlatformDocument
    {
        $template = PlatformDocumentTemplate::where('active', true)->findOrFail($data['template_id']);
        if (! $template->approved()) {
            throw new RuntimeException(__('هذا القالب لم يُعتمد بنصّه الحاليّ — لا يُصدر منه مستند حتى يعتمده مدير المنصّة.'));
        }
        // مقدّمُ الخدمة كما هو اليوم — بما كُتب منه، وما لم يُكتب لا يُطبع
        $provider = self::provider();

        $renews = ! empty($data['renews_document_id']) ? PlatformDocument::findOrFail($data['renews_document_id']) : null;
        if ($renews) {
            if ($renews->status !== PlatformDocument::SIGNED) {
                throw new RuntimeException(__('لا يُجدَّد إلا عقدٌ موقّع.'));
            }
            $open = $renews->renewals()->get()->first(fn (PlatformDocument $r) => $r->status === PlatformDocument::SIGNED || $r->signable());
            if ($open) {
                throw new RuntimeException(__('لهذا العقد تجديدٌ قائم: :n. ألغِه قبل إصدار غيره.', ['n' => $open->number]));
            }
        }

        $businessId = $renews ? $renews->client_business_id : ($data['business_id'] ?? null);
        $business = ! empty($businessId) ? Business::find($businessId) : null;

        // ما كتبه المدير يتقدّم على ما يُعرف — والتجديدُ يبدأ من بيان العقد الذي يجدّده
        $client = array_merge(
            self::clean($business ? self::prefill($business) : []),
            self::clean($renews?->client ?? []),
            self::clean($data['client'] ?? []),
        );
        if (($client['name'] ?? '') === '' && ($client['biz'] ?? '') === '') {
            throw new RuntimeException(__('اذكر اسم العميل أو اسم نشاطه.'));
        }
        // هاتفُ العميل دوليًّا — إليه يُفتح واتساب من جهاز المدير
        $phone = WhatsAppPhone::normalize($client['phone'] ?? null);
        if ($phone === null) {
            throw new RuntimeException(__('اكتب هاتف العميل صحيحًا.'));
        }

        $plan = null;
        $start = $end = null;
        if ($template->includes_plan) {
            $model = Plan::find($data['plan_id'] ?? ($renews?->plan['plan_id'] ?? $business?->plan_id));
            if (! $model) {
                throw new RuntimeException(__('اختر الباقة.'));
            }
            $plan = self::planSnapshot($model, (string) ($data['cycle'] ?? ($renews?->plan['cycle'] ?? 'monthly')));

            $start = ! empty($data['term_starts_on'])
                ? Carbon::parse($data['term_starts_on'])->startOfDay()
                : ($renews?->term_ends_on?->copy() ?? self::defaultStart($business));
            $end = ! empty($data['term_ends_on'])
                ? Carbon::parse($data['term_ends_on'])->startOfDay()
                : self::termEnd($start, $plan['cycle']);
            if ($end->lte($start)) {
                throw new RuntimeException(__('نهاية الاشتراك يجب أن تكون بعد بدايته.'));
            }
        }

        $amount = null;
        if ($template->includes_amount) {
            $fee = $template->default_amount === null ? null : round((float) $template->default_amount, 3);
            $given = $data['amount'] ?? null;
            // رسومٌ ثابتة: المبلغُ هو ما اعتُمد ويذكره النصّ — لا يُكتب غيرُه هنا
            if ($template->amount_rule === PlatformDocumentTemplate::AMOUNT_FIXED && $fee !== null) {
                if ($given !== null && $given !== '' && (! is_numeric($given) || round((float) $given, 3) !== $fee)) {
                    throw new RuntimeException(__('رسوم هذه الخدمة ثابتة: :fee ر.ع كما اعتُمدت. لتغييرها عدّل القالب واعتمده من جديد.', ['fee' => number_format($fee, 3, '.', '')]));
                }
                $given = $fee;
            }
            if (! is_numeric($given) || (float) $given < 0) {
                throw new RuntimeException(__('اكتب مبلغ الخدمة.'));
            }
            $amount = round((float) $given, 3);
            if ($template->amount_rule === PlatformDocumentTemplate::AMOUNT_MIN && $fee !== null && $amount < $fee) {
                throw new RuntimeException(__('سعر هذه الخدمة يبدأ من :fee ر.ع كما اعتُمد.', ['fee' => number_format($fee, 3, '.', '')]));
            }
        }

        $days = max(1, min(90, (int) ($data['days'] ?? self::DEFAULT_DAYS)));

        // النموذجُ نسخةٌ في المستند بنصّه التامّ (رسومُه ومقدّمُ خدمته)، ومعه ما كتبه الفريقُ في حقوله وحدها
        $form = DocumentForms::render($template->form ?: null, [
            'fee' => $template->default_amount === null ? null : (string) $template->default_amount,
            'provider' => self::providerLine($provider),
        ]);
        $team = $form ? (DocumentForms::clean($form, is_array($data['team'] ?? null) ? $data['team'] : [], 'team') ?: null) : null;

        return DB::transaction(function () use ($by, $template, $business, $renews, $client, $phone, $plan, $start, $end, $amount, $data, $days, $form, $team, $provider) {
            $doc = PlatformDocument::create([
                // رقمٌ مؤقّتٌ فريد، ثمّ رقمُ العقد من معرّفه — لا عدّادَ يتسابق عليه اثنان
                'number' => 'TMP-'.Str::random(24),
                'template_id' => $template->id,
                'client_business_id' => $business?->id,
                'renews_document_id' => $renews?->id,
                'service' => $template->service,
                'title' => $template->title,
                'title_en' => $template->title_en,
                'terms' => array_values($template->terms ?? []),
                'form' => $form,
                'team' => $team,
                'plan' => $plan,
                'term_starts_on' => $start?->toDateString(),
                'term_ends_on' => $end?->toDateString(),
                'amount' => $amount,
                'amount_label' => $amount !== null ? (trim((string) ($data['amount_label'] ?? '')) ?: ($template->default_amount_label ?: $template->name)) : null,
                'provider' => $provider,
                'template_version' => $template->version,
                'client' => $client,
                'client_label' => self::label($client),
                'client_phone' => $phone,
                'token' => self::token(),
                'status' => PlatformDocument::SENT,
                'expires_at' => now()->addDays($days)->endOfDay(),
                'created_by' => $by->id,
                'created_by_name' => $by->name,
                'notes' => trim((string) ($data['notes'] ?? '')) ?: null,
            ]);
            $doc->update(['number' => 'AB-'.now()->format('Ym').'-'.str_pad((string) $doc->id, 4, '0', STR_PAD_LEFT)]);

            Activity::log('created', ($renews ? 'أصدر عقد تجديد للتوقيع: ' : 'أصدر مستندًا للتوقيع: ').$doc->number.' — '.($client['biz'] ?? $client['name'] ?? ''), [
                'business_id' => null, 'subject_id' => $doc->id, 'subject_type' => PlatformDocument::class,
            ]);

            return $doc;
        });
    }

    /** «الاسم — النشاط · الهاتف»: ما يُعرض في القائمة ويُبحث فيه */
    public static function label(array $client): string
    {
        $who = implode(' — ', array_filter([$client['name'] ?? null, $client['biz'] ?? null]));

        return mb_substr(implode(' · ', array_filter([$who, $client['phone'] ?? null])), 0, 250);
    }

    /** رمزُ الرابط — ٣٢ حرفًا لا تُخمَّن */
    private static function token(): string
    {
        do {
            $token = Str::random(32);
        } while (PlatformDocument::where('token', $token)->exists());

        return $token;
    }

    /**
     * يسجّل أنّ المدير شارك الرابط — بالنسخ أو بفتح واتساب.
     *
     * فعلُه هو لا أثرُه: لا يقول إنّ الرسالة خرجت ولا إنّها وصلت. والرابطُ لا يُكتب
     * في السجلّ — رقمُ المستند يكفي من يقرأ.
     */
    public static function share(PlatformDocument $doc, string $via): void
    {
        if (! isset(PlatformDocument::SHARED[$via])) {
            throw new RuntimeException('Unknown share method: '.$via);
        }

        $doc->forceFill(['shared_via' => $via, 'shared_at' => now()])->save();

        Activity::log('updated', ($via === 'whatsapp' ? 'فتح واتساب لإرسال المستند ' : 'نسخ رابط توقيع المستند ').$doc->number, [
            'business_id' => null, 'subject_id' => $doc->id, 'subject_type' => PlatformDocument::class,
        ]);
    }

    /** رسالةُ واتساب الجاهزة — يراجعها المديرُ ويضغط «إرسال» بنفسه */
    public static function whatsappText(PlatformDocument $doc, string $link): string
    {
        // الرسالةُ باسم المنصّة الذي يعرفه العميل — والاسمُ القانونيّ في العقد نفسه.
        // وإن لم يُكتب اسمٌ قيلت الرسالةُ بلا اسم، لا باسمٍ يُستعار
        $provider = self::providerName(self::providerOf($doc));
        $who = $doc->client['name'] ?? $doc->client['biz'] ?? '';
        $what = ['provider' => $provider, 'title' => $doc->title, 'number' => $doc->number];

        return implode("\n", array_filter([
            __('مرحبًا :name،', ['name' => $who]),
            $provider !== ''
                ? __('يسعدنا في :provider إرسال :title رقم :number لمراجعته وتوقيعه إلكترونيًا عبر الرابط التالي:', $what)
                : __('يسعدنا إرسال :title رقم :number لمراجعته وتوقيعه إلكترونيًا عبر الرابط التالي:', $what),
            $link,
            __('الرابط صالح حتى :date.', ['date' => $doc->expires_at?->copy()->timezone(config('app.timezone'))->format('Y-m-d') ?? '—']),
            __('مع التحية،'),
            $provider,
        ], fn ($line) => $line !== ''));
    }

    /**
     * التوقيع — يقفل المستند ولا يُعاد.
     *
     * والرفضُ قبل الكتابة كلِّها: رابطٌ ملغى أو منتهٍ، أو موقَّعٌ من قبل، أو حقلٌ
     * إلزاميٌّ ناقص، أو صورةٌ ليست توقيعًا. وطلبان معًا ينتظر ثانيهما قفلَ الأوّل
     * فيرى «موقَّع».
     *
     * و`$copyKey` مفتاحُ نسخة الموقِّع على جهازه: تُحفظ بصمتُه مع التوقيع في المعاملة
     * نفسها، والمفتاحُ نفسُه لا يُكتب هنا.
     *
     * و`$answers` إجاباتُه في حقول النموذج (إن كان للمستند نموذج): يُقبل منها ما
     * للعميل وحده، وحقولُه الإلزاميّة شرطٌ كبيان العميل.
     *
     * @param  array<string, string>  $client  بيانُ العميل كما أقرّه — بلا الهاتف: يعتمده المدير
     */
    public static function sign(PlatformDocument $document, array $client, string $signature, ?string $ip, ?string $agent, ?string $copyKey = null, array $answers = []): PlatformDocument
    {
        $png = self::decodeSignature($signature);
        unset($client['phone']);

        $doc = DB::transaction(function () use ($document, $client, $png, $ip, $agent, $copyKey, $answers) {
            $doc = PlatformDocument::whereKey($document->id)->lockForUpdate()->firstOrFail();
            if ($doc->status === PlatformDocument::SIGNED) {
                throw new AlreadySigned(__('وُقِّع هذا المستند من قبل.'));
            }
            if (! $doc->signable()) {
                throw new RuntimeException(__('هذا الرابط لم يعد صالحًا للتوقيع.'));
            }

            $form = $doc->form ?: null;
            $given = $form ? DocumentForms::clean($form, $answers, 'client') : [];
            if (DocumentForms::asksClient($form)) {
                // بيانُ العميل في حقول النموذج — والعامُّ يبقى كما أصدره المدير
                $values = $doc->client ?? [];
                $missing = DocumentForms::missing($form, $given);
            } else {
                $values = array_merge($doc->client ?? [], self::clean($client));
                $missing = array_map(fn ($f) => $f['ar'], array_values(array_filter(self::FIELDS, fn ($f) => $f['req'] && ($values[$f['k']] ?? '') === '')));
            }
            if ($missing) {
                throw new RuntimeException(__('أكمل: :fields', ['fields' => implode('، ', $missing)]));
            }

            $path = 'documents/'.$doc->id.'/signature-'.Str::random(24).'.png';
            /*
             * والقرصُ الذي لا يكتب يقول `false` ولا يرمي (`throw` مطفأ في ضبطه) — فلو
             * مضى التوقيعُ لَحُفظ مستندٌ «موقَّع» بلا صورة توقيعه، وبصمتُه تُحسب من
             * صورةٍ في الذاكرة لا في القرص: يُرى غدًا «تغيّر بعد توقيعه». فيُردّ هنا
             * والمعاملةُ لم تكتب شيئًا، ويُعيد العميلُ الإرسال.
             */
            if (! Storage::disk('local')->put($path, $png)) {
                throw new RuntimeException(__('تعذّر حفظ التوقيع الآن — أعد الإرسال بعد قليل.'));
            }

            $doc->forceFill([
                'client' => $values,
                'client_label' => self::label($values),
                'status' => PlatformDocument::SIGNED,
                'signed_at' => now(),
                'agreed_at' => now(),
                'consent_text' => DocumentForms::consent($form) ?? self::CONSENT,
                'answers' => $form ? ($given ?: null) : null,
                'copy_key_hash' => $copyKey !== null && $copyKey !== '' ? hash('sha256', $copyKey) : null,
                'signer_ip' => $ip ? mb_substr($ip, 0, 45) : null,
                'signer_agent' => $agent ? mb_substr($agent, 0, 512) : null,
                'signature_path' => $path,
            ]);
            $doc->content_hash = self::hash($doc, $png);
            $doc->save();

            Activity::log('updated', 'وقّع العميل المستند: '.$doc->number.' — '.($values['biz'] ?? $values['name'] ?? ''), [
                'business_id' => null, 'subject_id' => $doc->id, 'subject_type' => PlatformDocument::class,
            ]);

            return $doc;
        });

        // الورقةُ بعد المعاملة: فشلُ حفظها لا يردّ توقيعًا حُفظ
        try {
            self::archive($doc);
        } catch (Throwable $e) {
            Log::warning('signed document paper not archived', ['document' => $doc->id, 'error' => $e->getMessage()]);
        }

        return $doc;
    }

    /** صورةُ التوقيع من رسم اللوحة: PNG وحدها، وحجمٌ معقول، وأبعادٌ حقيقيّة */
    public static function decodeSignature(string $dataUrl): string
    {
        $prefix = 'data:image/png;base64,';
        if (! str_starts_with($dataUrl, $prefix)) {
            throw new RuntimeException(__('أضف توقيعك.'));
        }
        $png = base64_decode(substr($dataUrl, strlen($prefix)), true);
        if ($png === false || strlen($png) < 100 || strlen($png) > self::MAX_SIGNATURE_BYTES
            || ! str_starts_with($png, "\x89PNG\r\n\x1a\n")) {
            throw new RuntimeException(__('أضف توقيعك.'));
        }
        $size = @getimagesizefromstring($png);
        if (! $size || $size[0] < 50 || $size[1] < 20 || $size[0] > 4000 || $size[1] > 2000) {
            throw new RuntimeException(__('أضف توقيعك.'));
        }

        return $png;
    }

    /**
     * بصمةُ ما وُقِّع عليه — كلُّ ما تطبعه الورقة: رقمُه وتاريخُه وطرفاه ونصُّه
     * وباقتُه ومدّتُه، والإقرارُ ووقتُه، والتوقيعُ ووقتُه وجهازُه.
     *
     * تُطبع في الورقة. فإن عُدِّل شيءٌ في الصفّ بعد التوقيع لم تعد البصمةُ تطابقه.
     */
    public static function hash(PlatformDocument $doc, ?string $png = null): string
    {
        $png ??= $doc->signature_path ? (string) Storage::disk('local')->get($doc->signature_path) : '';

        $signed = [
            'number' => $doc->number,
            'issued' => optional($doc->created_at)->format('Y-m-d'),
            'renews' => $doc->renews_document_id,
            'provider' => $doc->provider,
            'service' => $doc->service,
            'title' => $doc->title,
            'title_en' => $doc->title_en,
            'terms' => $doc->terms,
            'plan' => $doc->plan,
            'term' => [optional($doc->term_starts_on)->format('Y-m-d'), optional($doc->term_ends_on)->format('Y-m-d')],
            'amount' => $doc->amount === null ? null : (string) $doc->amount,
            'amount_label' => $doc->amount_label,
            'client' => $doc->client,
            'consent' => $doc->consent_text,
            'agreed_at' => optional($doc->agreed_at)->format('Y-m-d H:i:s'),
            'signed_at' => optional($doc->signed_at)->format('Y-m-d H:i:s'),
            'signer_ip' => $doc->signer_ip,
            'signer_agent' => $doc->signer_agent,
            'signature' => hash('sha256', $png),
        ];
        // النموذجُ وما كُتب فيه — لمستندات النماذج وحدها، فبصمةُ ما وُقِّع قبلها لا تتغيّر
        if ($doc->form) {
            $signed += ['form' => $doc->form, 'team' => $doc->team, 'answers' => $doc->answers];
        }

        return hash('sha256', json_encode($signed, JSON_UNESCAPED_UNICODE));
    }

    /** هل ما في الصفّ هو ما وُقِّع عليه؟ */
    public static function intact(PlatformDocument $doc): bool
    {
        return $doc->content_hash !== null && hash_equals($doc->content_hash, self::hash($doc));
    }

    /** التوقيعُ صورةً مضمَّنة — للورقة ولشاشة المدير، بلا رابطٍ عامّ إلى الملفّ */
    public static function signatureDataUri(PlatformDocument $doc): ?string
    {
        if (! $doc->signature_path || ! Storage::disk('local')->exists($doc->signature_path)) {
            return null;
        }

        return 'data:image/png;base64,'.base64_encode((string) Storage::disk('local')->get($doc->signature_path));
    }

    /** بياناتُ مقدّم الخدمة القانونيّة — تُطبع إن كُتبت، ولا تمنع شيئًا إن لم تُكتب. بمفاتيحها في `provider()` */
    public const PROVIDER_REQUIRED = ['legal_name' => 'الاسم القانوني', 'cr' => 'رقم السجل التجاري', 'address' => 'العنوان القانوني'];

    /**
     * الطرفُ الأوّل في مستندٍ صدر — كما نُسخ يومَ إصداره.
     *
     * وما لم يُنسخ فيه فارغ: مستندٌ قديم يُعرض بما حمله، لا يُكمَّل باسمٍ من اليوم.
     */
    public static function providerOf(PlatformDocument $doc): array
    {
        return ($doc->provider ?? []) + ['name' => '', 'legal_name' => '', 'trade_name' => '', 'cr' => '', 'address' => '', 'email' => '', 'phone' => '', 'website' => ''];
    }

    /**
     * الاسمُ الذي يُخاطَب به العميل — في الرسالة و«تواصل مع …» والترويسة.
     *
     * الاسمُ التجاريّ إن كُتب، وإلّا الاسمُ الذي في المستند (القانونيّ). وإن لم
     * يُكتب أيٌّ منهما فارغ — والجملةُ تُقال بلا اسم.
     */
    public static function providerName(array $provider): string
    {
        return trim((string) ($provider['trade_name'] ?? '')) ?: trim((string) ($provider['name'] ?? ''));
    }

    /**
     * هويّةُ أبعاد في رأس المستند — من إعدادات المنصّة لا من الكود، تُنسخ فيه ساعةَ الإصدار.
     *
     * والاسمُ القانونيّ وسجلُّه وعنوانُه من «مقدّم الخدمة في العقود» (`legal_*`) وحدها
     * — لا من «اسم الشركة المالكة» (`company`، افتراضُه في الشاشة اسمٌ لا كيانَ له) ولا
     * من اسم التطبيق ولا من اسمٍ في الكود. وما لم يُكتب يبقى فارغًا — لا يُملأ بما يشبهه.
     *
     * و`name` هو الاسمُ القانونيّ نفسُه: المفتاحُ الذي قرأته المستنداتُ قبل `legal_name`.
     */
    public static function provider(): array
    {
        $s = Setting::whereNull('business_id')
            ->whereIn('key', ['official_email', 'phone', 'website', 'legal_name', 'legal_trade_name', 'legal_cr', 'legal_address'])
            ->pluck('value', 'key');
        $get = fn (string $k) => trim((string) $s->get($k));

        return [
            'name' => $get('legal_name'),
            'legal_name' => $get('legal_name'),
            'trade_name' => $get('legal_trade_name'),
            'cr' => $get('legal_cr'),
            'address' => $get('legal_address'),
            'email' => $get('official_email'),
            'phone' => $get('phone'),
            'website' => $get('website'),
        ];
    }

    /** ما لم يُكتب من بيانات مقدّم الخدمة القانونيّة — بعناوينه، للتنبيه وحده */
    public static function providerMissing(array $provider): array
    {
        return array_values(array_map(
            fn ($k) => self::PROVIDER_REQUIRED[$k],
            array_filter(array_keys(self::PROVIDER_REQUIRED), fn ($k) => trim((string) ($provider[$k] ?? '')) === ''),
        ));
    }

    /** «مشاريع الذهلي الجديدة — الاسم التجاري للمنصة: أبعاد — السجل التجاري رقم …» — ما كُتب منه وحده */
    public static function providerLine(array $provider): string
    {
        return implode(' — ', array_filter([
            trim((string) ($provider['legal_name'] ?? '')),
            ($provider['trade_name'] ?? '') !== '' ? 'الاسم التجاري للمنصة: '.$provider['trade_name'] : null,
            ($provider['cr'] ?? '') !== '' ? 'السجل التجاري رقم '.$provider['cr'] : null,
        ]));
    }

    /**
     * ورقةُ المستند مرسومةً من بياناته — موقَّعًا بتوقيعه وبصمته، أو «لم يُوقَّع بعد».
     *
     * والقالبُ جداولُ وحدها: محرّكُ الورق لا يقرأ flex ولا grid.
     */
    public static function pdf(PlatformDocument $doc)
    {
        $html = view('pdf.platform-document', [
            'doc' => $doc,
            'provider' => self::providerOf($doc),
            'fields' => self::FIELDS,
            'signature' => self::signatureDataUri($doc),
        ])->render();

        return Pdf::a4($html, $doc->number);
    }

    /**
     * يحفظ الورقةَ الموقَّعة كما رُسمت — مرّةً، ببصمة ملفّها.
     *
     * فما ينزّله العميلُ غدًا والمديرُ بعد سنة هو الملفُّ نفسه، لا رسمٌ جديد قد
     * يختلف بتحديث محرّك الورق أو قالبه.
     */
    public static function archive(PlatformDocument $doc): void
    {
        if ($doc->status !== PlatformDocument::SIGNED || $doc->pdf_path) {
            return;
        }

        $bytes = (string) self::pdf($doc)->getContent();
        $path = 'documents/'.$doc->id.'/signed-'.Str::random(16).'.pdf';
        Storage::disk('local')->put($path, $bytes);

        $saved = PlatformDocument::whereKey($doc->id)->whereNull('pdf_path')
            ->update(['pdf_path' => $path, 'pdf_hash' => hash('sha256', $bytes)]);
        if ($saved) {
            $doc->forceFill(['pdf_path' => $path, 'pdf_hash' => hash('sha256', $bytes)]);
        } else {
            // سبقه طلبٌ آخر بحفظها — تبقى ورقتُه
            Storage::disk('local')->delete($path);
            $doc->refresh();
        }
    }

    /**
     * الورقةُ كما تُنزَّل — المحفوظةُ للموقَّع إن طابقت بصمتُها، وإلّا مرسومةً الآن.
     */
    public static function paper(PlatformDocument $doc): Response
    {
        if ($doc->status === PlatformDocument::SIGNED) {
            if (! $doc->pdf_path || ! Storage::disk('local')->exists($doc->pdf_path)) {
                if ($doc->pdf_path) {
                    Log::warning('archived document paper missing', ['document' => $doc->id]);
                    PlatformDocument::whereKey($doc->id)->update(['pdf_path' => null, 'pdf_hash' => null]);
                    $doc->forceFill(['pdf_path' => null, 'pdf_hash' => null]);
                }
                try {
                    self::archive($doc);
                } catch (Throwable $e) {
                    Log::warning('signed document paper not archived', ['document' => $doc->id, 'error' => $e->getMessage()]);
                }
            }

            if ($doc->pdf_path && Storage::disk('local')->exists($doc->pdf_path)) {
                $bytes = (string) Storage::disk('local')->get($doc->pdf_path);
                if ($doc->pdf_hash && hash_equals($doc->pdf_hash, hash('sha256', $bytes))) {
                    return response($bytes, 200, [
                        'Content-Type' => 'application/pdf',
                        'Content-Disposition' => 'inline; filename="'.$doc->number.'.pdf"',
                    ]);
                }
                Log::warning('archived document paper does not match its hash', ['document' => $doc->id]);
            }
        }

        return self::pdf($doc);
    }

    /** تاريخُ المستند كما يُطبع — بتوقيت المنصّة */
    public static function date(?Carbon $at): string
    {
        return $at ? $at->copy()->timezone(config('app.timezone'))->format('Y-m-d H:i') : '—';
    }

    /** يومٌ كما يُطبع — بلا ساعة */
    public static function day(?Carbon $at): string
    {
        return $at ? $at->format('Y-m-d') : '—';
    }
}
