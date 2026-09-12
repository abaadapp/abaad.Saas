<?php

namespace App\Support;

use App\Models\CrmLead;
use App\Models\CrmMessage;

/**
 * ما يُقرأ من المحادثة بيقين — لا بتخمين نموذج.
 *
 * ═══ ولمَ هذا قبل الذكاء الاصطناعيّ لا بعده ═══
 *
 * ثلاثةٌ ممّا يطلبه البيع تُقاس ولا تُخمَّن: هل سأل عن السعر؟ هل طلب
 * تجربة؟ هل طلب إنسانًا؟ والجوابُ في نصّ رسالته حرفًا. ونموذجٌ يُسأل عنها
 * يُجيب أحيانًا بخلافها، ولا يقول لماذا.
 *
 * فما يُقاس يُقاس هنا، **ويُعرض ومعه الجملةُ التي دلّت عليه**. والنموذج
 * يُسأل عمّا لا يُقاس: صياغةُ ردٍّ، وفهمُ ما لم يُقل صراحةً.
 *
 * ═══ والإشارةُ ليست حقيقة ═══
 *
 * «كم السعر؟» إشارةُ نيّةِ شراء، وليست نيّةَ شراء. ومن يكتبها قد يكون
 * يقارن. فتُعرض إشارةً باسمها ومصدرِها، ويقرّر الإنسان.
 */
final class CrmSignals
{
    /**
     * عباراتُ نيّةِ الشراء — عربيّةً بلهجةٍ عُمانيّةٍ دارجة وإنجليزيّة.
     *
     * والقائمةُ مكتوبةٌ لا مُولَّدة: كلُّ سطرٍ فيها رآه أحدٌ في محادثةٍ
     * حقيقيّة أو يكاد. وقائمةٌ يُولّدها نموذجٌ تُطابق ما لا يعنيه أحد.
     *
     * ═══ وصيغةٌ واحدةٌ لكلّ عبارة ═══
     *
     * كانت تحمل الهجاءَين معًا: «أبي أجرب» و«ابي اجرب». وذلك تكرارٌ يقول
     * الشيء نفسه مرّتين — فيُضاف سطرٌ جديدٌ بهجاءٍ واحدٍ يومًا ولا يُطابق
     * نصفَ من يكتبه. وأثبتته طفرة: حذفتُ تسويةَ الهمزات فلم يسقط حارس،
     * لأنّ القائمة كانت تحرس نفسَها بالتكرار.
     *
     * فالمكتوبُ هنا هجاءٌ واحد، والتسويةُ في `fold` هي التي تُطابق البقيّة —
     * على الطرفين معًا. موضعٌ واحد يُصلَح فيه الهجاءُ كلُّه.
     *
     * @var array<string, list<string>>
     */
    private const INTENT = [
        'pricing' => [
            'كم السعر', 'كم سعر', 'السعر كم', 'بكم', 'كم تكلفة', 'كم الاشتراك',
            'الأسعار', 'كم شهري', 'كم سنوي', 'price', 'how much', 'cost',
        ],
        'trial' => [
            'أبي أجرب', 'أريد أجرب', 'تجربة', 'أجرب', 'نسخة تجريبية',
            'demo', 'trial', 'try it',
        ],
        'subscribe' => [
            'كيف أشترك', 'أبي أشترك', 'أريد الاشتراك', 'أقدر أبدأ', 'نبدأ',
            'أرسل الرابط', 'subscribe', 'sign up', 'get started',
        ],
        'demo' => [
            'عرض تقديمي', 'موعد', 'نلتقي', 'زيارة', 'اجتماع', 'meeting', 'schedule',
        ],
    ];

    /**
     * عباراتُ الاعتراض.
     *
     * وتُعرض لتُؤكَّد باليد لا لتُكتب حقيقةً: «غالي» قد تكون مزاحًا، وقد
     * تكون سببَ الخسارة. والتقريرُ يُبنى على ما أكّده إنسان.
     *
     * @var array<string, list<string>>
     */
    private const OBJECTION = [
        'price' => ['غالي', 'غالية', 'مرتفع', 'كثير عليّ', 'ما أقدر أدفع', 'expensive', 'too much'],
        'competitor' => ['عندي نظام', 'أستخدم', 'شركة ثانية', 'بديل', 'competitor', 'another system'],
        'missing_feature' => ['ما فيه', 'ما يدعم', 'لا يدعم', 'ناقص', 'مو موجود', "doesn't support", 'missing'],
        'training' => ['صعب', 'ما أعرف أستخدم', 'تدريب', 'معقد', 'complicated', 'training'],
        'migration' => ['بياناتي', 'أنقل', 'تحويل البيانات', 'migrate', 'import my data'],
        'timing' => ['مو الحين', 'بعدين', 'لاحقًا', 'مشغول', 'later', 'not now'],
        'approval' => ['أستشير', 'شريكي', 'المدير', 'أخوي', 'partner', 'my boss'],
    ];

    /**
     * ما يوجب إنسانًا — ولا يُترك لنموذج.
     *
     * ═══ ولمَ قائمةٌ صريحة ═══
     *
     * طلبُ إنسانٍ، وشكوى، واستردادُ مال، ومسألةٌ قانونيّة: أربعةٌ إن أجاب
     * عنها نموذجٌ أخطأ خطأً يُكلّف. وثقةُ النموذج بنفسه لا تُقاس، فالقائمةُ
     * تُقرأ قبله لا بعده.
     *
     * @var list<string>
     */
    private const HANDOFF = [
        'أبي أكلم', 'أكلم موظف', 'أبي إنسان', 'مو روبوت', 'ما أبي رد آلي',
        'شكوى', 'أشتكي', 'استرجاع', 'استرداد', 'أسترد فلوسي',
        'محامي', 'قانوني', 'محكمة',
        'human', 'real person', 'speak to someone', 'complaint', 'refund', 'lawyer',
    ];

    /* ═══════════════════ القراءة ═══════════════════ */

    /**
     * ما دلّت عليه رسائلُ العميل الواردة.
     *
     * والواردةُ وحدَها تُقرأ: ردُّ موظّف المبيعات فيه ذكرُ السعر دائمًا، فلو
     * قُرئ لَصار كلُّ عميلٍ «سأل عن السعر» بعد أوّل ردّ.
     *
     * @return array{
     *     intent: list<array{key:string,label:string,quote:string}>,
     *     objections: list<array{key:string,label:string,quote:string}>,
     *     handoff: ?string,
     *     inbound: int,
     *     outbound: int,
     *     lastInboundAt: ?string
     * }
     */
    public static function read(CrmLead $lead): array
    {
        $messages = CrmMessage::where('lead_id', $lead->id)
            ->orderBy('id')->limit(300)->get(['direction', 'body', 'created_at']);

        $inbound = $messages->where('direction', CrmMessage::IN);

        $intent = [];
        $objections = [];
        $handoff = null;

        foreach ($inbound as $message) {
            $text = self::fold((string) $message->body);

            if ($text === '') {
                continue;
            }

            foreach (self::INTENT as $key => $needles) {
                if (! isset($intent[$key]) && ($quote = self::match($text, $needles, (string) $message->body))) {
                    $intent[$key] = ['key' => $key, 'label' => self::intentLabel($key), 'quote' => $quote];
                }
            }

            foreach (self::OBJECTION as $key => $needles) {
                if (! isset($objections[$key]) && ($quote = self::match($text, $needles, (string) $message->body))) {
                    $objections[$key] = ['key' => $key, 'label' => Crm::lostReasonLabel(
                        in_array($key, Crm::LOST_REASONS, true) ? $key : 'other'
                    ), 'quote' => $quote];
                    /* واسمُ الاعتراض غيرُ اسم سبب الخسارة حين لا يُطابقه */
                    $objections[$key]['label'] = self::objectionLabel($key);
                }
            }

            if ($handoff === null && ($quote = self::match($text, self::HANDOFF, (string) $message->body))) {
                $handoff = $quote;
            }
        }

        $last = $inbound->last();

        return [
            'intent' => array_values($intent),
            'objections' => array_values($objections),
            'handoff' => $handoff,
            'inbound' => $inbound->count(),
            'outbound' => $messages->where('direction', CrmMessage::OUT)->count(),
            'lastInboundAt' => optional($last?->created_at)->format('Y-m-d H:i'),
        ];
    }

    /**
     * درجةُ الاهتمام — مجموعُ إشاراتٍ يُقرأ كلٌّ منها وحدَه.
     *
     * ═══ ولمَ لا يُسأل نموذجٌ عنها ═══
     *
     * رقمٌ يُخرجه نموذجٌ لا يُفسَّر ولا يُعاد إنتاجُه: يقول «٧٥٪» اليوم
     * و«٤٠٪» غدًا عن المحادثة نفسِها، ويُبنى عليه ترتيبُ من يُتابَع أوّلًا.
     *
     * فهي هنا جمعٌ صريح: كلُّ إشارةٍ ووزنُها، وتُعرض مفصَّلةً لا رقمًا
     * وحدَه. ومن لا يوافق يرى على ماذا لا يوافق.
     *
     * @return array{score:int, reasons: list<array{label:string,points:int}>}
     */
    public static function score(CrmLead $lead, ?array $signals = null): array
    {
        $signals = $signals ?? self::read($lead);
        $reasons = [];

        $add = function (string $label, int $points) use (&$reasons) {
            $reasons[] = ['label' => $label, 'points' => $points];
        };

        $intentKeys = array_column($signals['intent'], 'key');

        if (in_array('subscribe', $intentKeys, true)) {
            $add(__('طلب الاشتراك صراحةً'), 30);
        }

        if (in_array('trial', $intentKeys, true)) {
            $add(__('طلب تجربة'), 20);
        }

        if (in_array('pricing', $intentKeys, true)) {
            $add(__('سأل عن السعر'), 15);
        }

        if (in_array('demo', $intentKeys, true)) {
            $add(__('طلب موعدًا أو عرضًا'), 10);
        }

        /* والتفاعلُ نفسُه إشارة: من يكتب ثلاثَ مرّاتٍ ليس كمن كتب مرّة */
        if ($signals['inbound'] >= 3) {
            $add(__('راسلنا :n مرّات', ['n' => $signals['inbound']]), 10);
        } elseif ($signals['inbound'] === 2) {
            $add(__('راسلنا مرّتين'), 5);
        }

        /* واكتمالُ البيانات: من قال اسمَ نشاطه وعددَ فروعه جادٌّ في الغالب */
        $known = 0;
        foreach (['business_name', 'wilayat', 'branches_count'] as $field) {
            if (filled($lead->{$field})) {
                $known++;
            }
        }

        if ($known >= 2) {
            $add(__('بياناته شبه مكتملة'), 10);
        }

        if ($lead->interested_plan_id) {
            $add(__('حُدّدت باقةٌ يهتمّ بها'), 10);
        }

        /*
         * والاعتراضُ يخصم — ولا يُلغي.
         *
         * من اعترض على السعر ثمّ طلب تجربةً أقربُ إلى الشراء ممّن لم يكتب
         * شيئًا. فالخصمُ محدود.
         */
        if ($signals['objections'] !== []) {
            $add(__('اعترض على شيء'), -10);
        }

        /* وصمتٌ طويلٌ بعد آخر رسالةٍ منه يُبرّد الإشارة */
        if ($lead->last_contact_at && $lead->last_contact_at->lt(now()->subDays(14))) {
            $add(__('لم يكتب منذ أكثر من أسبوعين'), -15);
        }

        $score = 0;
        foreach ($reasons as $r) {
            $score += $r['points'];
        }

        return ['score' => max(0, min(100, $score)), 'reasons' => $reasons];
    }

    /**
     * الإجراءُ المقترح — قاعدةٌ مكتوبة، لا تخمين.
     *
     * ولا يُنفَّذ من نفسه: يُعرض نصًّا، ويضغط الإنسانُ ما يضغط. واقتراحٌ
     * يُنفّذ نفسَه يصير قرارًا لم يتّخذه أحد.
     */
    public static function nextAction(CrmLead $lead, ?array $signals = null): string
    {
        $signals = $signals ?? self::read($lead);
        $intentKeys = array_column($signals['intent'], 'key');

        if ($signals['handoff'] !== null) {
            return __('تواصل معه بنفسك — طلب إنسانًا أو ذكر شكوى.');
        }

        if (in_array('subscribe', $intentKeys, true)) {
            return __('جاهزٌ للاشتراك — أنشئ متجره واربطه من زرّ «تحويل إلى عميل».');
        }

        if (in_array('trial', $intentKeys, true)) {
            return __('طلب تجربة — انقله إلى مرحلة «تجربة» وزوّده بما يحتاج.');
        }

        if (in_array('pricing', $intentKeys, true) && ! $lead->interested_plan_id) {
            return __('سأل عن السعر — حدّد الباقة التي تناسبه في بياناته ثمّ أرسل تفاصيلها.');
        }

        if (blank($lead->business_name) || $lead->branches_count === null) {
            return __('اسأله عن نشاطه وعدد فروعه — فبهما تُعرف الباقة المناسبة.');
        }

        if ($lead->next_follow_up_at === null) {
            return __('اضبط موعد المتابعة القادمة — بلا موعدٍ يُنسى.');
        }

        return __('تابعه في موعده.');
    }

    /* ═══════════════════ أدواتٌ ═══════════════════ */

    /**
     * مطابقةٌ تردّ **الجملة** التي دلّت — لا `true`.
     *
     * ولمَ الجملة: «سأل عن السعر» بلا اقتباسٍ ادّعاءٌ يُصدَّق أو يُكذَّب ولا
     * يُراجَع. ومعه يقرأ موظّفُ المبيعات ما قاله صاحبُه ويحكم.
     *
     * @param  list<string>  $needles
     */
    private static function match(string $folded, array $needles, string $original): ?string
    {
        foreach ($needles as $needle) {
            if (str_contains($folded, self::fold($needle))) {
                return mb_substr(trim($original), 0, 140);
            }
        }

        return null;
    }

    /**
     * تسويةُ النصّ للمقارنة — ولا يُكتب الناتج في أيّ صفّ.
     *
     * الهمزاتُ تُكتب كما اعتاد كاتبُها: «أبي» و«ابي»، «أريد» و«اريد».
     * والتشكيلُ يرد ولا يرد. ومقارنةٌ حرفيّةٌ تُسقط نصفَ ما يُكتب فعلًا.
     */
    private static function fold(string $text): string
    {
        $text = Digits::western($text);
        $text = preg_replace('/[\x{064B}-\x{0652}\x{0640}]/u', '', $text) ?? $text;
        $text = strtr($text, ['أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ى' => 'ي', 'ة' => 'ه', 'ؤ' => 'و', 'ئ' => 'ي']);
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return trim(mb_strtolower($text));
    }

    private static function intentLabel(string $key): string
    {
        return __(match ($key) {
            'pricing' => 'سأل عن السعر',
            'trial' => 'طلب تجربة',
            'subscribe' => 'يريد الاشتراك',
            'demo' => 'طلب موعدًا',
            default => $key,
        });
    }

    private static function objectionLabel(string $key): string
    {
        return __(match ($key) {
            'price' => 'السعر',
            'competitor' => 'يستعمل نظامًا آخر',
            'missing_feature' => 'ميزة ناقصة',
            'training' => 'صعوبة الاستعمال والتدريب',
            'migration' => 'نقل بياناته',
            'timing' => 'ليس الوقت المناسب',
            'approval' => 'يحتاج موافقة شريك',
            default => $key,
        });
    }
}
