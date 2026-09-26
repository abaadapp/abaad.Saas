<?php

namespace App\Support;

use App\Models\NotificationState;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * ═══ التنبيهُ إجراءٌ يُتابَع، لا رسالةٌ تُقرأ فتزول ═══
 *
 * والبنيةُ هنا مبنيّةٌ على حقيقةٍ واحدةٍ في هذا النظام: التنبيهاتُ **مشتقّةٌ
 * لا مخزَّنة** (`Demo::buildNotifications`). فالمفتاحُ يُنتَج ما دام سببُه
 * قائمًا، ويختفي ساعةَ يزول.
 *
 * ويتبع ذلك ثلاثةُ أشياء بلا كودٍ إضافيّ:
 *
 *   • **غيابُ المفتاح هو دليلُ الحلّ.** فالإغلاقُ التلقائيُّ ليس ميزةً
 *     تُبنى، بل قراءةٌ صحيحةٌ لما تقوله البنية.
 *
 *   • **«تم» تُتحقَّق بإعادة السؤال لا بإعادة الحساب.** من ضغطها على تنبيهٍ
 *     قابلٍ للتحقّق نُعيد بناءَ قائمته: إن كان المفتاحُ ما زال يُنتَج فالمشكلةُ
 *     قائمةٌ ولا إنجاز. وهذا يستعمل `Product::scopeNeedsStockAlert` وحالاتِ
 *     الطلب و`GoodsReceipts::PENDING` **كما هي**، فلا تُنسخ قاعدةُ مخزونٍ
 *     ولا حالةُ طلبٍ إلى هنا. وموضعان يحكمان على الشيء نفسِه يفترقان يومًا.
 *
 *   • **عودةُ المشكلة دورةٌ جديدة.** صنفٌ نفد فأُعيد تخزينُه ثمّ نفد ثانيةً
 *     مفتاحُه هو المفتاحُ الأوّل. فالدورةُ تُغلق عند الغياب، وعودتُه تفتح
 *     دورةً برقمٍ تالٍ — ولا يبقى إنجازُ الأمس مانعًا لتنبيه اليوم.
 *
 * ═══ ولا يُفرض «تم» على ما لا يُنجَز ═══
 *
 * «ملخّصُ اليوم» خبرٌ، و«وصل ردُّ الدعم» خبر. وزرُّ إنجازٍ فوق خبرٍ يسأل
 * صاحبَه سؤالًا لا جواب له. فهذه تبقى على القراءة والإخفاء كما كانت.
 */
class Notifications
{
    /** إشعارٌ يُقرأ ويُخفى — ولا يُنجَز */
    public const INFO = 'info';

    /** إجراءٌ يُتابَع — له «تم» و«تأجيل» */
    public const TASK = 'task';

    /** يُتحقَّق من حلّه بإعادة سؤال مصدره */
    public const AUTO = 'auto';

    /** لا يملك النظامُ دليلًا على إنجازه — فيُصدَّق صاحبُه ويُسجَّل اسمُه */
    public const MANUAL = 'manual';

    /**
     * ═══ تصنيفُ كلّ مصدرٍ يعرفه الجرس ═══
     *
     * والمفتاحُ بادئتُه، وأطولُ بادئةٍ تطابق هي الحاكمة — `support-reply-`
     * قبل `support-`، وإلّا قرأ ردُّ التاجر نفسَه صفَّ مدير المنصّة.
     *
     * ولمَ `manual` لهذه الخمسة بالذات:
     *
     *   `dormant-`       الردُّ اتّصالٌ أو رسالة، وعودةُ الزبون قرارُه لا قرارُ
     *                    التاجر. فاشتراطُ شرائه لإغلاق الصفّ يُبقيه مفتوحًا
     *                    على من فعل ما عليه.
     *   `gbp-review-`    الردُّ يقع في Google لا عندنا، ولا نقرأ منه شيئًا.
     *   `stray-payment-` يردّ التاجرُ المالَ أو يجهّز بديلًا — وكلاهما لا
     *                    يكتب `order_id`، فالغيابُ ليس دليلَ معالجة.
     *   `season-reminder-` و`custom-` (تذكير): موعدٌ حان، ولا سجلَّ يقول إنّه نُفِّذ.
     *
     * و`custom-` تحتمل الوجهين — قاعدةٌ تُقاس أو تذكيرٌ بموعد — فالصفُّ
     * نفسُه يحمل `resolve` ويعلو على هذا الجدول. انظر `resolveOf`.
     */
    public const MAP = [
        'support-reply-' => [self::INFO, self::AUTO],
        'support-' => [self::INFO, self::AUTO],
        'sub-' => [self::INFO, self::MANUAL],
        'gbp-review-' => [self::TASK, self::MANUAL],
        'wa-delivery' => [self::TASK, self::AUTO],
        'season-reminder-' => [self::TASK, self::MANUAL],
        'daily-' => [self::INFO, self::MANUAL],
        'archive-' => [self::INFO, self::MANUAL],
        'grn-' => [self::TASK, self::AUTO],
        'low-' => [self::TASK, self::AUTO],
        'dormant-' => [self::TASK, self::MANUAL],
        'custom-' => [self::TASK, self::MANUAL],
        'stray-payment-' => [self::TASK, self::MANUAL],
        'order-' => [self::TASK, self::AUTO],
    ];

    /**
     * مُددُ التأجيل المسموحة، بالدقائق.
     *
     * ومجموعةٌ مغلقةٌ لا رقمٌ حرّ: `snooze_until` بعد سنتين يُخرج التنبيهَ من
     * النظام بلا أن يُنجزه أحد — إخفاءٌ أبديٌّ بثوب تأجيل، وهو العطبُ الذي
     * تعالجه هذه الميزةُ أصلًا.
     */
    public const SNOOZE = [60, 240, 1440, 4320, 10080];

    /** كم يومًا يبقى المنجَزُ في سجلّ «المكتملة» */
    public const HISTORY_DAYS = 30;

    /**
     * ثمنُ الفرز فوق البناء — استعلامٌ واحد.
     *
     * الجرسُ يُستطلع كلَّ ثلاثين ثانيةً لكلّ من يفتح اللوحة، ويُبنى مع كلّ
     * صفحة. فرقمٌ مكتوبٌ هنا يقرؤه الحارسُ يجعل أيَّ استعلامٍ يُضاف سهوًا
     * يسقط في الفحص لا في الإنتاج.
     */
    public const STATE_QUERIES = 1;

    /** بادئةُ المصدر الذي يُنتج هذا المفتاح — أطولُ ما يطابقه، و`null` لمجهول */
    public static function sourceOf(string $key): ?string
    {
        $best = null;

        foreach (array_keys(self::MAP) as $prefix) {
            if (! str_starts_with($key, $prefix)) {
                continue;
            }

            if ($best === null || mb_strlen($prefix) > mb_strlen($best)) {
                $best = $prefix;
            }
        }

        return $best;
    }

    /** تصنيفُ مفتاحٍ — بأطول بادئةٍ تطابقه، و`null` لمفتاحٍ لا يعرفه الجرس */
    public static function kindOf(string $key): ?array
    {
        $prefix = self::sourceOf($key);

        return $prefix === null
            ? null
            : ['kind' => self::MAP[$prefix][0], 'resolve' => self::MAP[$prefix][1]];
    }

    /** طريقةُ التحقّق لهذا الصفّ — ما كتبه المصدرُ يعلو على الجدول */
    public static function resolveOf(array $item): string
    {
        return $item['resolve'] ?? (self::kindOf($item['key'])['resolve'] ?? self::MANUAL);
    }

    /** نوعُ هذا الصفّ — خبرٌ أم إجراء */
    public static function kindOfItem(array $item): string
    {
        return $item['kind'] ?? (self::kindOf($item['key'])['kind'] ?? self::INFO);
    }

    /** صفوفُ هذا المستخدمِ المفتوحةُ في نشاطه — استعلامٌ واحدٌ يخدم الجرسَ كلَّه */
    public static function openStates(User $user, int $businessId): Collection
    {
        return NotificationState::where('user_id', $user->id)
            ->where('business_id', $businessId)
            ->whereNull('resolved_at')
            ->get()->keyBy('notif_key');
    }

    /**
     * يقسم ما بناه الجرسُ إلى: ما يحتاج إجراءً، وما أُجّل، وما أُنجز وما زال قائمًا.
     *
     * ═══ والبناءُ المقصوصُ ليس حجّةً على الغياب ═══
     *
     * الجرسُ يبني عشرةً **من كلّ مصدر** لا أكثر (`BELL_COUNT_CEILING`). فصنفٌ
     * منخفضٌ ترتيبُه الخامسَ عشرَ يغيب عن هذا البناء وهو على الرفّ ناقصٌ كما
     * كان — ولو قُرئ غيابُه حلًّا لَأُغلق تنبيهٌ لم يُحلّ، وهو أسوأ ما يمكن
     * أن يفعله نظامُ تنبيهات: طمأنينةٌ كاذبة.
     *
     * فلا يُقرأ الغيابُ إلّا من بناءٍ كامل، ولا يُطلب هذا البناءُ إلّا حين
     * يغيب مفتاحٌ متتبَّع — وهو انتقالٌ يقع مرّةً في الدورة لا في كلّ استطلاع.
     *
     * @param  list<array<string, mixed>>  $raw  ما بناه الجرسُ الآن
     * @param  callable():list<array<string, mixed>>  $full  بناءٌ كاملٌ يُطلب عند الحاجة وحدَها
     * @return array{active: list<array<string, mixed>>, snoozed: list<array<string, mixed>>, done: list<array<string, mixed>>}
     */
    public static function sift(array $raw, callable $full, ?User $user, ?int $businessId): array
    {
        $rows = self::describe($raw);

        if (! $user || ! $businessId) {
            return ['active' => $rows, 'snoozed' => [], 'done' => []];
        }

        $states = self::openStates($user, $businessId);

        if ($states->isEmpty()) {
            return ['active' => $rows, 'snoozed' => [], 'done' => []];
        }

        $states = self::closeWhatIsGone($states, $rows, $full);

        $active = $snoozed = $done = [];

        foreach ($rows as $row) {
            $state = $states->get($row['key']);

            if ($state === null) {
                $active[] = $row;

                continue;
            }

            /*
             * وأُنجز ثمّ عاد — على مصدرٍ يُتحقَّق منه — فهو مشكلةٌ ثانية.
             *
             * و«تم» على هذا النوع لا تُقبل إلّا والمفتاحُ غائب، فحضورُه الآن
             * يعني أنّ السببَ عاد بعد أن زال. وإبقاؤه في «المكتملة» يُخفي
             * نفادًا جديدًا خلف إنجازٍ قديم.
             */
            if ($state->done_at !== null && self::resolveOf($row) === self::AUTO) {
                $state->forceFill(['resolved_at' => now()])->save();
                $states->forget($row['key']);
                $active[] = $row;

                continue;
            }

            if ($state->snoozing()) {
                $snoozed[] = $row + ['snoozed_until' => $state->snooze_until->toIso8601String()];

                continue;
            }

            if ($state->done_at !== null) {
                $done[] = $row + ['done_at' => $state->done_at->toIso8601String()];

                continue;
            }

            /* فُتح فقُرئ — والقراءةُ ليست إنجازًا، فيبقى في «تحتاج إجراء» */
            $active[] = $row + ['seen' => $state->seen_at !== null];
        }

        return ['active' => $active, 'snoozed' => $snoozed, 'done' => $done];
    }

    /** يُلحق بكلّ صفٍّ تصنيفَه — لتعرف الشاشةُ أيَّ أزرارٍ تعرض */
    public static function describe(array $items): array
    {
        return array_map(self::decorate(...), $items);
    }

    /** يُلحق بالصفّ نوعَه وطريقةَ تحقّقه — لتعرف الواجهةُ أيَّ أزرارٍ تعرض */
    public static function decorate(array $item): array
    {
        return $item + ['kind' => self::kindOfItem($item), 'resolve' => self::resolveOf($item)];
    }

    /**
     * يُغلق كلَّ دورةٍ زال سببُها — ولا يكتب شيئًا ما لم يقع انتقال.
     *
     * @param  callable():list<array<string, mixed>>  $full
     */
    private static function closeWhatIsGone(Collection $states, array $rows, callable $full): Collection
    {
        $missing = $states->keys()->diff(array_column($rows, 'key'));

        if ($missing->isEmpty()) {
            return $states;
        }

        /*
         * ═══ ولا يُقرأ الغيابُ حلًّا إلّا من شاهدٍ لا يُرشِّح ═══
         *
         * غيابُ المفتاح عن بناء الجرس له أربعةُ أسبابٍ لا سببٌ واحد:
         *
         *   ١) زال سببُه — وهذا وحدَه حلّ.
         *   ٢) نُزعت من صاحبه صلاحيةُ قسمه، فسقط من بنائه وهو قائم.
         *   ٣) أُخفي بالمسار القديم، فيغيب ثلاثين يومًا وسببُه قائم.
         *   ٤) دُفع خارج حصّة المصدر (عشرةٌ من كلّ مصدر) وهو قائم.
         *
         * فيُسأل بناءٌ **كاملٌ غيرُ مرشَّح** (`buildNotifications(100, true)`):
         * لا يُسقط مخفيًّا ولا يقيس إذنًا، فجوابُه عن المصدر لا عن الشاشة.
         *
         * ═══ واختصارٌ كتبتُه ثمّ نزعتُه ═══
         *
         * كان هنا فرزٌ يقول: «مصدرٌ أنتج أقلَّ من حصّته أنتج كلَّ ما عنده،
         * فغيابُ مفتاحه قاطعٌ بلا سؤالٍ ثانٍ». وهو يوفّر بناءً في كلّ نبضة،
         * **وغيرُ سليم**: المصدرُ يجلب حصّتَه ثمّ يُرشّح `$add` منها، فصفّان
         * يظهران من عشرةٍ جُلبت يُقرآن «لم يبلغ حصّتَه». فمفتاحٌ أُخفي أو
         * حُجب بصلاحيّةٍ كان يُقرأ محلولًا — وهو العطبُ نفسُه الذي يعالجه
         * هذا البناء. فالسلامةُ قبل بناءٍ موفَّر.
         */
        $gone = $missing->diff(array_column($full(), 'key'));

        if ($gone->isEmpty()) {
            return $states;
        }

        /*
         * والتصفيةُ بالمسند لا بـ`only`/`except`.
         *
         * `Eloquent\Collection` تُعيد تعريفَ الاثنتين: تُصفّيان بالمفاتيح
         * الأوّليّة للنماذج لا بمفاتيح المصفوفة. والمجموعةُ هنا مُمفتحةٌ
         * بمفتاح التنبيه (`keyBy('notif_key')`)، فـ`only(['low-7'])` تبحث
         * عن نموذجٍ معرّفُه «‏low-7» فلا تجد شيئًا — **وتصمت**: لا استثناءَ
         * ولا صفرَ محدَّث، فيبقى كلُّ تنبيهٍ زال سببُه مفتوحًا إلى الأبد.
         */
        $doomed = $states->filter(fn (NotificationState $s) => $gone->contains($s->notif_key));

        NotificationState::whereIn('id', $doomed->pluck('id')->all())
            ->update(['resolved_at' => now(), 'updated_at' => now()]);

        return $states->reject(fn (NotificationState $s) => $gone->contains($s->notif_key));
    }
}
