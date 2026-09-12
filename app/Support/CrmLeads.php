<?php

namespace App\Support;

use App\Models\Business;
use App\Models\CrmLead;
use App\Models\CrmNote;
use App\Models\CrmStageEvent;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * كلُّ ما يُكتب في دفتر المبيعات يمرّ من هنا.
 *
 * ═══ ولمَ بابٌ واحد ═══
 *
 * تغييرُ المرحلة ثلاثةُ أفعالٍ لا فعل: يُكتب العمود، ويُقيَّد الانتقالُ في
 * تاريخه، ويُشتقّ الحال. ومتحكّمٌ يكتب العمودَ بيده ينسى الثاني — فيصير في
 * الدفتر صفٌّ مرحلتُه «عرض سعر» وتاريخُه يقول إنّه ما زال «جديدًا»، ثمّ
 * يُبنى على ذلك التاريخِ تقريرُ مسارِ التحويل كلُّه.
 *
 * فالكتابةُ هنا، والمتحكّماتُ تُحقّق من المُدخل وتنادي.
 */
final class CrmLeads
{
    /* ═══════════════════ الإنشاء ═══════════════════ */

    /**
     * عميلٌ محتمَل — يُوجَد أو يُنشأ، ولا يُكرَّر.
     *
     * ═══ ولمَ `firstOrCreate` على الرقم المطبَّع ═══
     *
     * الرقمُ هو الهويّة. و«فحصٌ ثمّ إنشاء» في PHP يترك بين القراءة والكتابة
     * نافذةً — إشعاران من ميتا في اللحظة نفسِها يصنعان صفّين للرقم الواحد،
     * ثمّ يردّ موظّفان على نصفَي محادثة. والتفرّدُ في القاعدة هو الحارس،
     * و`Contention` تلتقط الاصطدام.
     *
     * ═══ وما لا يُخترع ═══
     *
     * اسمُ النشاط وعددُ الفروع والولايةُ والميزانيّة لا تُكتب هنا: لم يقلها
     * أحد. حقلٌ يُملأ بالحدس يُقرأ بعد شهرٍ على أنّه حقيقةٌ قالها صاحبُه.
     *
     * @param  array<string, mixed>  $extra  حقولٌ تُكتب عند الإنشاء وحده
     * @return array{lead: CrmLead, created: bool}
     */
    public static function findOrCreateByPhone(
        string $rawPhone,
        string $source = Crm::SOURCE_MANUAL,
        ?string $name = null,
        array $extra = [],
    ): array {
        $normalized = WhatsAppPhone::normalize($rawPhone);

        if ($normalized === null) {
            throw new \InvalidArgumentException('رقمٌ لا يصلح للتطبيع: '.$rawPhone);
        }

        $existing = CrmLead::where('phone', $normalized)->first();

        if ($existing) {
            return ['lead' => $existing, 'created' => false];
        }

        $lead = Contention::attempt(fn () => CrmLead::create([
            'phone' => $normalized,
            'phone_raw' => mb_substr(trim($rawPhone), 0, 40),
            'name' => $name ? mb_substr(trim($name), 0, 150) : null,
            'source' => in_array($source, Crm::SOURCES, true) ? $source : Crm::SOURCE_MANUAL,
            'status' => Crm::ACTIVE,
            'stage' => Crm::NEW,
            'first_contact_at' => now(),
            'last_contact_at' => now(),
            ...$extra,
        ]));

        /*
         * والاصطدامُ يعني أنّ الصفَّ كُتب — لا أنّه ضاع.
         *
         * `Contention::attempt` تردّ `null` حين يسبقها غيرُها إلى المفتاح
         * الفريد. فيُقرأ الصفُّ الذي كتبه السابق، ويُعامَل معاملةَ الموجود:
         * إنشاءٌ ثانٍ هو ما مُنع، لا قراءةُ ما أُنشئ.
         */
        if (! $lead) {
            return ['lead' => CrmLead::where('phone', $normalized)->firstOrFail(), 'created' => false];
        }

        CrmStageEvent::create([
            'lead_id' => $lead->id,
            'from_stage' => null,
            'to_stage' => Crm::NEW,
            'user_id' => auth()->id(),
            'user_name' => auth()->user()?->name ?? __('النظام'),
            'created_at' => now(),
        ]);

        Activity::log('created', 'أضاف عميلًا محتملًا '.$lead->displayName());

        return ['lead' => $lead, 'created' => true];
    }

    /* ═══════════════════ المرحلة ═══════════════════ */

    /**
     * نقلُ المرحلة — والتاريخُ يُكتب معها لا بعدها.
     *
     * و«مشترك» لا تُبلَغ من هنا: الاشتراكُ يصنع متجرًا، وتلك `convert`.
     * و«مفقود» تحتاج سببًا — `lose`.
     */
    public static function moveStage(CrmLead $lead, string $stage, User $actor, ?string $reason = null): CrmLead
    {
        if (! in_array($stage, Crm::STAGES, true)) {
            throw new \InvalidArgumentException('مرحلةٌ لا يعرفها المسار: '.$stage);
        }

        if ($lead->stage === $stage) {
            return $lead;
        }

        $from = (string) $lead->stage;

        DB::transaction(function () use ($lead, $stage, $actor, $reason, $from) {
            $lead->forceFill([
                'stage' => $stage,
                'status' => Crm::statusFor($stage),
                'last_contact_at' => $lead->last_contact_at ?? now(),
            ])->save();

            CrmStageEvent::create([
                'lead_id' => $lead->id,
                'from_stage' => $from,
                'to_stage' => $stage,
                'user_id' => $actor->id,
                'user_name' => $actor->name,
                'reason' => $reason ? mb_substr($reason, 0, 300) : null,
                'created_at' => now(),
            ]);
        });

        Activity::log('status', 'نقل العميل المحتمل '.$lead->displayName().' إلى مرحلة '.Crm::stageLabel($stage));

        return $lead->refresh();
    }

    /**
     * خسارةٌ بسبب — ولا خسارةَ بلا سبب.
     *
     * وبلا قائمةٍ مغلقة لا يُجمع تقريرُ الأسباب: «السعر» و«غالي» و«الأسعار
     * مرتفعة» ثلاثةُ صفوفٍ لسببٍ واحد.
     */
    public static function lose(CrmLead $lead, string $reason, ?string $note, User $actor): CrmLead
    {
        if (! in_array($reason, Crm::LOST_REASONS, true)) {
            throw new \InvalidArgumentException('سببٌ لا تعرفه القائمة: '.$reason);
        }

        $lead->forceFill([
            'lost_reason' => $reason,
            'lost_note' => $note ? mb_substr($note, 0, 300) : null,
        ])->save();

        return self::moveStage($lead, Crm::LOST, $actor, Crm::lostReasonLabel($reason));
    }

    /**
     * إعادةُ عميلٍ خسرناه إلى الطريق — وسببُ الخسارة يُمحى.
     *
     * سببٌ يبقى على صفٍّ عاد نشطًا يُعدّ في تقرير الأسباب مرّةً ثانية، فيقول
     * التقريرُ إنّنا خسرنا بالسعر عميلًا هو اليوم في مرحلة «عرض سعر».
     */
    public static function reopen(CrmLead $lead, User $actor): CrmLead
    {
        $lead->forceFill(['lost_reason' => null, 'lost_note' => null])->save();

        return self::moveStage($lead, Crm::CONTACTED, $actor, __('أُعيد فتحه'));
    }

    /* ═══════════════════ الإسناد ═══════════════════ */

    /**
     * إسنادٌ إلى موظّفِ منصّةٍ — ولا يُسنَد إلى موظّف متجر.
     *
     * ولمَ الفحصُ هنا لا في التحقّق وحده: مُعرّفٌ يأتي من المتصفّح، و«موجودٌ
     * في `users`» يشمل كلَّ كاشيرٍ في كلّ متجر. وإسنادُ عميلٍ محتمَلٍ إلى
     * كاشير محلِّ ورودٍ يعني أنّ شاشةً في مكانٍ ما ستُظهر له اسمَه.
     */
    public static function assign(CrmLead $lead, ?User $assignee, User $actor): CrmLead
    {
        if ($assignee && ! $assignee->isSuperAdmin()) {
            throw new \InvalidArgumentException('لا يُسنَد عميلٌ محتمَل إلا إلى موظّف منصّة.');
        }

        $lead->forceFill([
            'assigned_to' => $assignee?->id,
            'assigned_by' => $assignee ? $actor->id : null,
            'assigned_at' => $assignee ? now() : null,
        ])->save();

        Activity::log('updated', $assignee
            ? 'أسند العميل المحتمل '.$lead->displayName().' إلى '.$assignee->name
            : 'ألغى إسناد العميل المحتمل '.$lead->displayName());

        return $lead;
    }

    /* ═══════════════════ الملاحظات ═══════════════════ */

    public static function note(CrmLead $lead, User $author, string $body): CrmNote
    {
        $note = CrmNote::create([
            'lead_id' => $lead->id,
            'user_id' => $author->id,
            'user_name' => $author->name,
            'body' => mb_substr(trim($body), 0, 5000),
        ]);

        /*
         * وآخرُ ملاحظةٍ تُنسخ سطرًا في صفّ العميل.
         *
         * لتظهر في القائمة بلا وصلةٍ إلى جدول الملاحظات في كلّ صفّ — وهي
         * نسخةٌ للعرض لا مصدرٌ يُقرأ: الأصلُ في `crm_notes` وحده.
         */
        $lead->forceFill(['notes_summary' => mb_substr($note->body, 0, 300)])->save();

        Activity::log('updated', 'كتب ملاحظة على العميل المحتمل '.$lead->displayName());

        return $note;
    }

    /* ═══════════════════ التحويل ═══════════════════ */

    /**
     * ربطُ العميل المحتمَل بمتجرٍ قائم — ولا يُنشأ متجرٌ من هنا.
     *
     * ═══ ولمَ لا يُنشأ ═══
     *
     * إنشاءُ المتجر بابٌ قائمٌ له شاشتُه وتحقّقُه وباقتُه واشتراكُه وحسابُ
     * صاحبه: `SuperAdmin\BusinessController::store`. وبابٌ ثانٍ يصنع متاجرَ
     * يفترق عنه عند أوّل تعديل — فيُضاف متجرٌ بلا اشتراكٍ لأنّ السطر نُسي هنا.
     *
     * فالتحويلُ ربطٌ: يُنشئ الموظّفُ المتجر من بابه ثمّ يربطه، أو يربطه
     * بمتجرٍ قائمٍ إن كان صاحبُه عميلًا قديمًا عاد.
     *
     * ═══ ولا متجرَ يُربط بعميلَين ═══
     *
     * وإلّا صار متجرٌ واحدٌ محسوبًا اشتراكَين في تقرير التحويل.
     */
    public static function convert(CrmLead $lead, Business $business, User $actor): CrmLead
    {
        $taken = CrmLead::where('converted_business_id', $business->id)
            ->where('id', '!=', $lead->id)->first();

        if ($taken) {
            throw new \InvalidArgumentException(
                'هذا المتجر مرتبطٌ بعميلٍ محتمَلٍ آخر: '.$taken->displayName()
            );
        }

        $lead->forceFill([
            'converted_business_id' => $business->id,
            'converted_at' => now(),
        ])->save();

        Activity::log('created', 'حوّل العميل المحتمل '.$lead->displayName().' إلى المتجر '.$business->name);

        return self::moveStage($lead, Crm::WON, $actor, $business->name);
    }

    /* ═══════════════════ العميلُ الحاليّ ═══════════════════ */

    /**
     * هل هذا الرقم لصاحب متجرٍ قائم؟ — يُقال في الشاشة ولا يُكتب في الصفّ.
     *
     * ولمَ يُعرض: من يكلّمنا وهو مشتركٌ أصلًا ليس عميلًا محتملًا بل تاجرٌ له
     * دعمٌ وبابُه. وعرضُ ذلك يمنع موظّفَ المبيعات من أن يبيعه ما اشتراه.
     *
     * والمطابقةُ تُطبَّع في PHP لأنّ الأرقام تُكتب كما اعتاد أصحابُها —
     * وجدولُ المستخدمين موظّفو المتاجر لا زبائنُها: مئاتٌ لا مئاتُ آلاف.
     */
    public static function existingMerchant(CrmLead $lead): ?User
    {
        $matches = [];

        foreach (User::query()->whereNotNull('business_id')->whereNotNull('phone')
            ->with('business:id,name,status')->cursor() as $user) {
            if (WhatsAppPhone::normalize($user->phone) === $lead->phone) {
                $matches[] = $user;
            }
        }

        return count($matches) === 1 ? $matches[0] : null;
    }
}
