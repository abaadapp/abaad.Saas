<?php

namespace App\Support;

use App\Models\Business;
use App\Models\User;
use App\Models\WhatsAppCampaign;
use App\Models\WhatsAppCampaignTemplate;
use App\Models\WhatsAppConnection;
use Illuminate\Support\Str;

/**
 * ‏‏قوالبُ العروض — يكتبها التاجر بلغته، وتُرسل إلى Meta لمراجعتها، ويُتابع حالُها حتى تُعتمد.
 *
 * ═══ ولمَ قالبٌ لا نصٌّ حرّ ═══
 *
 * رسالةٌ يبدؤها المتجر لا تخرج إلّا بقالبٍ اعتمدته Meta. وهو على حساب أعمال المتجر نفسِه: قالبُ
 * متجرٍ لا يُرسل من رقم غيره.
 *
 * ═══ وما يُضاف من عندنا ═══
 *
 * زرُّ «إيقاف الرسائل» في كلّ قالب — لا يُحذف ولا يُخفى: من حقّ من تصله العروض أن يوقفها بضغطة، وMeta
 * تشترط ذكرَ طريق الإيقاف. وسطرٌ تحته يقول ذلك.
 *
 * ═══ والمتغيّرات ═══
 *
 * `{الاسم}` و`{المتجر}` تُكتب بالعربيّة وتخرج إلى Meta `{{1}}` و`{{2}}` بترتيبها، ومعها مثالٌ كما تشترط.
 * وقواعدُها في وثائقها: لا يبدأ النصُّ بمتغيّر ولا ينتهي به، ولا يتلاصق اثنان.
 */
class WhatsAppCampaignTemplates
{
    public const MAX_TEMPLATES = 30;

    public const MAX_TITLE = 80;

    /** ‏‏حدُّ Meta ١٠٢٤ — وتحته هامشٌ لما يُملأ من متغيّرات */
    public const MAX_BODY = 900;

    public const MAX_BUTTON = 25;

    public const LANGUAGES = ['ar', 'en'];

    /** ‏‏لغةُ القالب عندنا ← رمزُها عند Meta */
    public const META_LANGUAGE = ['ar' => 'ar', 'en' => 'en_US'];

    public const OPT_OUT = ['ar' => 'إيقاف الرسائل', 'en' => 'Stop messages'];

    public const FOOTER = ['ar' => 'لا تريد عروضنا؟ اضغط «إيقاف الرسائل»', 'en' => 'No more offers? Tap “Stop messages”'];

    /** ‏‏ما يُكتب في النصّ ← ما يُملأ به */
    public const VARIABLES = ['name' => ['{الاسم}', '{name}'], 'store' => ['{المتجر}', '{store}']];

    /** ‏‏ومن لا اسمَ له — Meta تردّ متغيّرًا فارغًا */
    public const NO_NAME = ['ar' => 'عميلنا العزيز', 'en' => 'dear customer'];

    public const PREFIX = 'abaad_offer_';

    /** ‏‏حالٌ قد تتبدّل من عند Meta — يُسأل عنها كلَّ ساعة */
    public const UNDER_REVIEW = ['PENDING', 'IN_APPEAL', 'PAUSED'];

    /**
     * ‏‏النصُّ كما يخرج إلى Meta، ومتغيّراتُه بترتيبها.
     *
     * @return array{body: string, variables: list<string>}
     */
    public static function compile(string $body): array
    {
        $order = [];
        $tokens = [];

        foreach (self::VARIABLES as $key => $list) {
            foreach ($list as $t) {
                $tokens[$t] = $key;
            }
        }

        $pattern = '/'.implode('|', array_map(fn ($t) => preg_quote($t, '/'), array_keys($tokens))).'/u';

        $out = preg_replace_callback($pattern, function ($m) use (&$order, $tokens) {
            $order[] = $tokens[$m[0]];

            return '{{'.count($order).'}}';
        }, trim($body));

        return ['body' => (string) $out, 'variables' => $order];
    }

    /** ‏‏ما يمنع إرسالَ القالب إلى Meta — بكلماتٍ يفهمها التاجر، أو `null` */
    public static function problem(string $body): ?string
    {
        $body = trim($body);

        if ($body === '') {
            return __('اكتب نص الرسالة.');
        }

        if (mb_strlen($body) > self::MAX_BODY) {
            return __('النص أطول من :max حرف — قصّره.', ['max' => self::MAX_BODY]);
        }

        $out = self::compile($body)['body'];

        if (str_contains($body, '{{') || preg_match('/\{[^{}]*\}/u', (string) preg_replace('/\{\{\d+\}\}/', '', $out))) {
            return __('في النص قوسان لا نعرفهما — استعمل أزرار «الاسم» و«المتجر» وحدها.');
        }

        if (preg_match('/^\{\{\d+\}\}/', $out) || preg_match('/\{\{\d+\}\}[\s\p{P}]*$/u', $out)) {
            return __('لا يبدأ النص بالاسم أو المتجر ولا ينتهي بهما — واتساب يرفضه. أضف كلمة قبلهما وبعدهما.');
        }

        if (preg_match('/\}\}\s*\{\{/', $out)) {
            return __('بين «الاسم» و«المتجر» كلمة على الأقل — واتساب يرفض تلاصقهما.');
        }

        return null;
    }

    /** ‏‏الرقمُ الذي تخرج منه الحملات: رقمُ المتجر المستقلّ بحساب أعماله — أو `null` */
    public static function connection(Business $business): ?WhatsAppConnection
    {
        $c = WhatsAppConnections::forBusiness((int) $business->id);

        return $c !== null && $c->isUsable() && filled($c->waba_id) ? $c : null;
    }

    /**
     * ‏‏يُكتب القالب ويُرسل إلى Meta. ولا يُحفظ عندنا ما لم تقبل استلامَه.
     *
     * @param  array<string, mixed>  $data
     * @return array{ok: bool, template: ?WhatsAppCampaignTemplate, message: ?string}
     */
    public static function create(Business $business, array $data, ?User $user): array
    {
        $refused = fn (string $why) => ['ok' => false, 'template' => null, 'message' => $why];
        $connection = self::connection($business);

        if ($connection === null) {
            return $refused(__('الحملات تخرج من رقم متجرك — اربطه أولًا.'));
        }

        if (($problem = self::problem((string) ($data['body'] ?? ''))) !== null) {
            return $refused($problem);
        }

        if (WhatsAppCampaignTemplate::where('business_id', $business->id)->count() >= self::MAX_TEMPLATES) {
            return $refused(__('وصلت إلى :max قالبًا — احذف واحدًا قبل أن تضيف غيره.', ['max' => self::MAX_TEMPLATES]));
        }

        $language = in_array($data['language'] ?? null, self::LANGUAGES, true) ? $data['language'] : 'ar';
        $body = trim((string) $data['body']);
        $compiled = self::compile($body);
        $button = self::button($business, (array) ($data['button'] ?? []));

        if ($button === false) {
            return $refused(__('رابط الزر يبدأ بـ https:// أو يكون رابط متجرك.'));
        }

        $name = self::PREFIX.$business->id.'_'.Str::lower(Str::random(10));

        $r = MetaWhatsAppClient::createTemplate($connection, [
            'name' => $name,
            'language' => self::META_LANGUAGE[$language],
            'category' => 'MARKETING',
            'parameter_format' => 'POSITIONAL',
            'components' => self::components($business, $language, $compiled, $button),
        ]);

        if (! $r['ok']) {
            return $refused(__('رفض واتساب القالب: :why', ['why' => $r['message']]));
        }

        $template = WhatsAppCampaignTemplate::create([
            'business_id' => $business->id,
            'waba_id' => (string) $connection->waba_id,
            'meta_name' => $name,
            'meta_id' => $r['id'],
            'title' => mb_substr(trim((string) ($data['title'] ?? '')) ?: mb_substr($body, 0, 30), 0, self::MAX_TITLE),
            'language' => $language,
            'body' => $body,
            'variables' => $compiled['variables'],
            'footer' => self::FOOTER[$language],
            'button' => $button,
            'category' => mb_substr((string) ($r['category'] ?: 'MARKETING'), 0, 20),
            'status' => mb_substr((string) ($r['status'] ?: WhatsAppCampaignTemplate::PENDING), 0, 24),
            'created_by' => $user?->id,
            'checked_at' => now(),
        ]);

        Activity::log('settings', 'حملات واتساب: أُرسل القالب «'.$template->title.'» للمراجعة', [
            'subject_type' => WhatsAppCampaignTemplate::class,
            'subject_id' => $template->id,
        ]);

        return ['ok' => true, 'template' => $template, 'message' => null];
    }

    /**
     * ‏‏أجزاءُ القالب كما تطلبها Meta — وأزرارُ الردّ مجموعةٌ قبل زرّ الرابط.
     *
     * @param  array{body: string, variables: list<string>}  $compiled
     * @param  array{title: string, url: string}|null  $button
     * @return list<array<string, mixed>>
     */
    private static function components(Business $business, string $language, array $compiled, ?array $button): array
    {
        $body = ['type' => 'BODY', 'text' => $compiled['body']];

        if ($compiled['variables'] !== []) {
            $body['example'] = ['body_text' => [array_map(
                fn ($v) => $v === 'name' ? ($language === 'en' ? 'Sara' : 'سارة') : self::store($business, $language),
                $compiled['variables'],
            )]];
        }

        $buttons = [['type' => 'QUICK_REPLY', 'text' => self::OPT_OUT[$language]]];

        if ($button !== null) {
            $buttons[] = ['type' => 'URL', 'text' => $button['title'], 'url' => $button['url']];
        }

        return [$body, ['type' => 'FOOTER', 'text' => self::FOOTER[$language]], ['type' => 'BUTTONS', 'buttons' => $buttons]];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{title: string, url: string}|false|null `null` بلا زرّ، و`false` رابطٌ لا يصلح
     */
    private static function button(Business $business, array $data): array|false|null
    {
        $title = mb_substr(trim((string) ($data['title'] ?? '')), 0, self::MAX_BUTTON);

        if ($title === '') {
            return null;
        }

        $url = trim((string) ($data['url'] ?? ''));

        if (in_array($url, ChannelAutomations::VARIABLES['store_link'], true)) {
            $url = (string) ChannelAutomations::storeLink($business);
        }

        return preg_match('~^https://[^\s]+$~i', $url) && strlen($url) <= 2000 ? ['title' => $title, 'url' => $url] : false;
    }

    public static function store(Business $business, string $language): string
    {
        return trim((string) ($language === 'en' ? ($business->name_en ?: $business->name) : $business->name));
    }

    /**
     * ‏‏قيمُ المتغيّرات لعميلٍ بعينه — ولا فراغَ فيها.
     *
     * @param  list<string>  $variables
     * @return list<string>
     */
    public static function values(Business $business, array $variables, string $language, ?string $name): array
    {
        // ‏‏وسطرٌ جديدٌ في قيمة متغيّرٍ تردّه Meta
        $clean = fn (string $v) => trim((string) preg_replace('/\s+/u', ' ', $v));

        return array_map(fn ($v) => $v === 'store'
            ? $clean(self::store($business, $language))
            : ($clean((string) $name) ?: self::NO_NAME[$language]), $variables);
    }

    /** ‏‏الرسالةُ كما يقرؤها العميل — للمعاينة */
    public static function preview(Business $business, string $body, string $language, ?string $name = null): string
    {
        foreach (self::VARIABLES as $key => $tokens) {
            $body = str_replace($tokens, self::values($business, [$key], $language, $name)[0], $body);
        }

        return trim($body);
    }

    /**
     * ‏‏يسأل Meta عن حال قوالبنا كلِّها — وكم تبدّل منها.
     *
     * @return array{ok: bool, changed: int, message: ?string}
     */
    public static function sync(Business $business): array
    {
        $connection = self::connection($business);

        if ($connection === null) {
            return ['ok' => false, 'changed' => 0, 'message' => __('الحملات تخرج من رقم متجرك — اربطه أولًا.')];
        }

        $ours = WhatsAppCampaignTemplate::where('business_id', $business->id)->where('waba_id', (string) $connection->waba_id)->get();

        if ($ours->isEmpty()) {
            return ['ok' => true, 'changed' => 0, 'message' => null];
        }

        $r = MetaWhatsAppClient::templateRows($connection);

        if (! $r['ok']) {
            return ['ok' => false, 'changed' => 0, 'message' => $r['message']];
        }

        $seen = collect($r['rows'])->keyBy(fn ($row) => ($row['name'] ?? '').'|'.($row['language'] ?? ''));
        $changed = 0;

        foreach ($ours as $t) {
            $row = $seen->get($t->meta_name.'|'.self::META_LANGUAGE[$t->language]);
            $changed += self::apply($t, $row === null
                // ‏‏قالبٌ لم تعد Meta تعرفه — حُذف من عندها، فلا يُرسل به
                ? ['status' => 'DELETED']
                : ['status' => $row['status'] ?? null, 'category' => $row['category'] ?? null, 'reason' => $row['rejected_reason'] ?? null, 'quality' => $row['quality_score']['score'] ?? null]) ? 1 : 0;
        }

        return ['ok' => true, 'changed' => $changed, 'message' => null];
    }

    /**
     * ‏‏إشعارُ Meta بحال قالب — على حساب الأعمال (`entry.id`) باسم القالب ولغته، ولحساب وصلةٍ بعينها إن سُمّيت.
     *
     * @param  array<string, mixed>  $value
     */
    public static function statusChanged(string $waba, array $value, ?WhatsAppConnection $pinned = null): void
    {
        if ($waba === '' || ($pinned !== null && (string) $pinned->waba_id !== $waba)) {
            return;
        }

        $language = str_replace('-', '_', (string) ($value['message_template_language'] ?? ''));

        WhatsAppCampaignTemplate::where('waba_id', $waba)
            ->where('meta_name', (string) ($value['message_template_name'] ?? ''))
            ->when($pinned !== null, fn ($q) => $q->where('business_id', $pinned->business_id))
            ->get()
            ->filter(fn ($t) => $language === '' || self::META_LANGUAGE[$t->language] === $language)
            ->each(fn ($t) => self::apply($t, [
                'status' => $value['event'] ?? null,
                'category' => $value['message_template_category'] ?? null,
                'reason' => $value['reason'] ?? null,
            ]));
    }

    /** @param array<string, mixed> $now */
    private static function apply(WhatsAppCampaignTemplate $t, array $now): bool
    {
        // ‏‏وأحداثُ Meta التي ليست حالًا (`FLAGGED` تحذير، و`REINSTATED` عودة) تُترجَم إلى حالها
        $status = match ((string) ($now['status'] ?? '')) {
            '' , 'FLAGGED' => $t->status,
            'REINSTATED', 'UNARCHIVED' => WhatsAppCampaignTemplate::APPROVED,
            default => mb_substr((string) $now['status'], 0, 24),
        };
        $was = [$t->status, $t->category, $t->rejected_reason, $t->quality];

        $t->forceFill([
            'status' => $status,
            'category' => filled($now['category'] ?? null) ? mb_substr((string) $now['category'], 0, 20) : $t->category,
            'rejected_reason' => filled($now['reason'] ?? null) && $now['reason'] !== 'NONE' ? mb_substr((string) $now['reason'], 0, 60) : ($status === 'REJECTED' ? $t->rejected_reason : null),
            'quality' => filled($now['quality'] ?? null) ? mb_substr((string) $now['quality'], 0, 12) : $t->quality,
            'checked_at' => now(),
        ])->save();

        return $was !== [$t->status, $t->category, $t->rejected_reason, $t->quality];
    }

    /** @return array{ok: bool, message: ?string} */
    public static function delete(Business $business, WhatsAppCampaignTemplate $t): array
    {
        if (WhatsAppCampaign::where('template_id', $t->id)->whereIn('status', WhatsAppCampaign::OPEN)->exists()) {
            return ['ok' => false, 'message' => __('حملة لم تنتهِ تستعمل هذا القالب — ألغها أولًا.')];
        }

        $connection = self::connection($business);

        // ‏‏ومن حسابٍ غيرِ المربوط الآن يُحذف عندنا وحده — لا نملك مفتاحَ حسابه
        if ($connection !== null && (string) $connection->waba_id === $t->waba_id && $t->status !== 'DELETED') {
            $r = MetaWhatsAppClient::removeTemplate($connection, $t->meta_name);

            if (! $r['ok']) {
                return ['ok' => false, 'message' => __('لم يُحذف من واتساب: :why', ['why' => $r['message']])];
            }
        }

        $title = $t->title;
        $t->delete();

        Activity::log('settings', 'حملات واتساب: حُذف القالب «'.$title.'»', ['subject_type' => WhatsAppCampaignTemplate::class, 'subject_id' => $t->id]);

        return ['ok' => true, 'message' => null];
    }

    public static function label(string $status): string
    {
        return match ($status) {
            'APPROVED' => 'معتمد',
            'PENDING', 'IN_APPEAL' => 'قيد مراجعة واتساب',
            'REJECTED' => 'مرفوض',
            'PAUSED' => 'أوقفه واتساب مؤقتًا',
            'DISABLED', 'LOCKED' => 'عطّله واتساب',
            'ARCHIVED' => 'مؤرشف في واتساب',
            'DELETED', 'PENDING_DELETION' => 'محذوف من واتساب',
            default => 'حال غير معروفة',
        };
    }

    /** ‏‏سببُ الرفض بكلماتٍ تُقرأ — ورمزٌ لا نعرفه يُعرض كما كتبته Meta */
    public static function why(?string $reason): ?string
    {
        return match ($reason) {
            null, '', 'NONE' => null,
            'ABUSIVE_CONTENT' => __('محتوى يخالف سياسة واتساب.'),
            'INVALID_FORMAT' => __('صيغة النص لا تصلح — راجع المتغيّرات والأسطر.'),
            'TAG_CONTENT_MISMATCH', 'INCORRECT_CATEGORY' => __('النص لا يطابق نوع «عرض».'),
            'SCAM' => __('رآه واتساب مضلّلًا.'),
            'PROMOTIONAL' => __('نص ترويجي في قالب ليس للعروض.'),
            default => $reason,
        };
    }
}
