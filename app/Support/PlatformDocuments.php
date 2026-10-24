<?php

namespace App\Support;

use App\Models\Business;
use App\Models\Plan;
use App\Models\PlatformDocument;
use App\Models\PlatformDocumentTemplate;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * مستنداتُ أبعاد مع عملائها — يُصدرها مدير المنصّة ويوقّعها العميلُ برابط.
 *
 * ═══ الإصدار ═══
 *
 * يُنسخ القالبُ في المستند ساعةَ الإصدار: عنوانُه وبنودُه، والباقةُ بسعر دورتها
 * يومَها. فلا يغيّر تعديلُ القالب أو سعرِ الباقة غدًا عقدًا أُرسل.
 *
 * ═══ التوقيع ═══
 *
 * العميلُ يكمل بياناته ويقرّ ويرسم توقيعه، فيُقفل المستند: يُحفظ التوقيعُ صورةً
 * خاصّة، وعنوانُ الجهاز ومتصفّحُه ووقتُه، وبصمةُ sha256 لكلّ ما وُقِّع عليه.
 * والقفلُ تحت قفل الصفّ: ضغطتان على «اعتماد» لا تكتبان توقيعين.
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

    /** دوراتُ الاشتراك — نصفُ السنة بسعر الشهر ستَّ مرّات (`Billing::price`) */
    public const CYCLES = [
        'monthly' => ['label' => 'شهري', 'en' => 'Monthly', 'unit' => 'شهريًا'],
        'semiannual' => ['label' => 'نصف سنوي', 'en' => 'Semi-annual', 'unit' => 'كل ستة أشهر'],
        'yearly' => ['label' => 'سنوي', 'en' => 'Yearly', 'unit' => 'سنويًا'],
    ];

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
     * @param  array{template_id:int, business_id?:?int, client?:array, plan_id?:?int, cycle?:?string, amount?:mixed, amount_label?:?string, days?:?int, notes?:?string}  $data
     */
    public static function issue(User $by, array $data): PlatformDocument
    {
        $template = PlatformDocumentTemplate::where('active', true)->findOrFail($data['template_id']);
        $business = ! empty($data['business_id']) ? Business::findOrFail($data['business_id']) : null;

        // ما كتبه المدير يتقدّم على ما يُعرف عن النشاط
        $client = array_merge(self::clean($business ? self::prefill($business) : []), self::clean($data['client'] ?? []));
        if (($client['name'] ?? '') === '' && ($client['biz'] ?? '') === '') {
            throw new RuntimeException(__('اذكر اسم العميل أو اسم نشاطه.'));
        }

        $plan = null;
        if ($template->includes_plan) {
            $model = Plan::find($data['plan_id'] ?? $business?->plan_id);
            if (! $model) {
                throw new RuntimeException(__('اختر الباقة.'));
            }
            $plan = self::planSnapshot($model, (string) ($data['cycle'] ?? 'monthly'));
        }

        $amount = null;
        if ($template->includes_amount) {
            if (! is_numeric($data['amount'] ?? null) || (float) $data['amount'] < 0) {
                throw new RuntimeException(__('اكتب مبلغ الخدمة.'));
            }
            $amount = round((float) $data['amount'], 3);
        }

        $days = max(1, min(90, (int) ($data['days'] ?? self::DEFAULT_DAYS)));

        return DB::transaction(function () use ($by, $template, $business, $client, $plan, $amount, $data, $days) {
            $doc = PlatformDocument::create([
                // رقمٌ مؤقّتٌ فريد، ثمّ رقمُ العقد من معرّفه — لا عدّادَ يتسابق عليه اثنان
                'number' => 'TMP-'.Str::random(24),
                'template_id' => $template->id,
                'client_business_id' => $business?->id,
                'service' => $template->service,
                'title' => $template->title,
                'title_en' => $template->title_en,
                'terms' => array_values($template->terms ?? []),
                'plan' => $plan,
                'amount' => $amount,
                'amount_label' => $amount !== null ? (trim((string) ($data['amount_label'] ?? '')) ?: $template->name) : null,
                'client' => $client,
                'client_label' => self::label($client),
                'token' => self::token(),
                'status' => PlatformDocument::SENT,
                'expires_at' => now()->addDays($days)->endOfDay(),
                'created_by' => $by->id,
                'created_by_name' => $by->name,
                'notes' => trim((string) ($data['notes'] ?? '')) ?: null,
            ]);
            $doc->update(['number' => 'AB-'.now()->format('Ym').'-'.str_pad((string) $doc->id, 4, '0', STR_PAD_LEFT)]);

            Activity::log('created', 'أصدر مستندًا للتوقيع: '.$doc->number.' — '.($client['biz'] ?? $client['name'] ?? ''), [
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
     * التوقيع — يقفل المستند ولا يُعاد.
     *
     * @param  array<string, string>  $client  بيانُ العميل كما أقرّه
     */
    public static function sign(PlatformDocument $document, array $client, string $signature, ?string $ip, ?string $agent): PlatformDocument
    {
        $png = self::decodeSignature($signature);

        return DB::transaction(function () use ($document, $client, $png, $ip, $agent) {
            $doc = PlatformDocument::whereKey($document->id)->lockForUpdate()->firstOrFail();
            if ($doc->status === PlatformDocument::SIGNED) {
                throw new RuntimeException(__('وُقِّع هذا المستند من قبل.'));
            }
            if (! $doc->signable()) {
                throw new RuntimeException(__('هذا الرابط لم يعد صالحًا للتوقيع.'));
            }

            $values = array_merge($doc->client ?? [], self::clean($client));
            $missing = array_values(array_filter(self::FIELDS, fn ($f) => $f['req'] && ($values[$f['k']] ?? '') === ''));
            if ($missing) {
                throw new RuntimeException(__('أكمل: :fields', ['fields' => implode('، ', array_map(fn ($f) => $f['ar'], $missing))]));
            }

            $path = 'documents/'.$doc->id.'/signature-'.Str::random(24).'.png';
            Storage::disk('local')->put($path, $png);

            $doc->forceFill([
                'client' => $values,
                'client_label' => self::label($values),
                'status' => PlatformDocument::SIGNED,
                'signed_at' => now(),
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
     * بصمةُ ما وُقِّع عليه — رقمُه ونصُّه وباقتُه وبيانُ العميل والتوقيعُ ووقتُه.
     *
     * تُطبع في الورقة. فإن عُدِّل شيءٌ في الصفّ بعد التوقيع لم تعد البصمةُ تطابقه.
     */
    public static function hash(PlatformDocument $doc, ?string $png = null): string
    {
        $png ??= $doc->signature_path ? (string) Storage::disk('local')->get($doc->signature_path) : '';

        return hash('sha256', json_encode([
            'number' => $doc->number,
            'title' => $doc->title,
            'terms' => $doc->terms,
            'plan' => $doc->plan,
            'amount' => $doc->amount === null ? null : (string) $doc->amount,
            'amount_label' => $doc->amount_label,
            'client' => $doc->client,
            'signed_at' => optional($doc->signed_at)->format('Y-m-d H:i:s'),
            'signature' => hash('sha256', $png),
        ], JSON_UNESCAPED_UNICODE));
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

    /** هويّةُ أبعاد في رأس المستند — من إعدادات المنصّة لا من الكود */
    public static function provider(): array
    {
        $s = Setting::whereNull('business_id')
            ->whereIn('key', ['app_name', 'company', 'official_email', 'phone', 'website'])
            ->pluck('value', 'key');

        return [
            'name' => trim((string) $s->get('company')) ?: (trim((string) $s->get('app_name')) ?: __('أبعاد')),
            'email' => trim((string) $s->get('official_email')),
            'phone' => trim((string) $s->get('phone')),
            'website' => trim((string) $s->get('website')),
        ];
    }

    /**
     * ورقةُ المستند — موقَّعًا بتوقيعه وبصمته، أو «لم يُوقَّع بعد».
     *
     * والقالبُ جداولُ وحدها: محرّكُ الورق لا يقرأ flex ولا grid.
     */
    public static function pdf(PlatformDocument $doc)
    {
        $html = view('pdf.platform-document', [
            'doc' => $doc,
            'provider' => self::provider(),
            'fields' => self::FIELDS,
            'signature' => self::signatureDataUri($doc),
        ])->render();

        return Pdf::a4($html, $doc->number);
    }

    /** تاريخُ المستند كما يُطبع — بتوقيت المنصّة */
    public static function date(?Carbon $at): string
    {
        return $at ? $at->copy()->timezone(config('app.timezone'))->format('Y-m-d H:i') : '—';
    }
}
