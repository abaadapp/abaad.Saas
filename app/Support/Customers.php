<?php

namespace App\Support;

use App\Models\Customer;

class Customers
{
    /**
     * اسمُ العميل كما كُتب — لا يُنقل ولا يُترجم.
     *
     * كان يمرّ بـ`LocalName::apply`: «Ahmed Al Balushi» يُكتب في `name`
     * «أحمد البلوشي» بنقلٍ آليّ، والأصلُ يُنحّى إلى `name_en`، ثمّ تختار
     * الشاشةُ بينهما بلغة الواجهة. فالعميلُ الواحد يُقرأ باسمين، ويُطبع على
     * فاتورته اسمٌ لم يكتبه أحد — والنقلُ تخمينٌ يصيب ويخطئ في اسم إنسان.
     *
     * فالاسمُ يُحفظ في `name` كما كتبه العميلُ أو الموظّف، بأيّ حرفٍ وبأيّ
     * خليط («Mohammed محمد»)، ويُقرأ هو نفسُه في كلّ شاشةٍ وبكلّ لغة.
     *
     * و`name_en` لا يُكتب هنا ولا يُمحى: عمودٌ باقٍ لما حُفظ فيه قبلُ (وفيه
     * أصلُ من نُقل اسمُه آليًّا)، والبحثُ ما زال يقرؤه. وتعديلُ العميل لا
     * يمسّه. أمّا المورّدون فقاعدتُهم كما هي (`LocalName`).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function asEntered(array $data): array
    {
        if (array_key_exists('name', $data)) {
            $data['name'] = trim((string) $data['name']);
        }

        unset($data['name_en']);

        return $data;
    }

    /**
     * لغةُ رسائل واتساب — تُسأل عند التسجيل، ولا يُحفظ عميلٌ بلا جواب.
     *
     * لا تخمينَ من الرقم ولا من حروف الاسم: رقمٌ عمانيٌّ قد يكون لهنديٍّ لا
     * يقرأ العربيّة، واسمٌ لاتينيٌّ قد يكون لعمانيٍّ كتبه الكاشير كذلك.
     * ورسالةٌ بلغةٍ خاطئة أسوأ من سؤالٍ واحدٍ عند التسجيل. والرسالةُ بعدها
     * تخرج وحدَها عند تغيير الحالة — فلا لحظةَ سؤالٍ غيرَ هذه.
     *
     * ويُطبَّق في الأبواب الأربعة: شاشةُ العملاء، ونافذةُ الصندوق، ونافذةُ
     * الفاتورة، والتعديل.
     */
    public static function languageRule(): array
    {
        return ['required', 'string', \Illuminate\Validation\Rule::in(WhatsAppEvent::LANGUAGES)];
    }

    /**
     * الهاتف يعرّف الشخص — فلا يتكرّر داخل المتجر الواحد.
     *
     * سجلّان بالهاتف نفسه يعنيان شخصًا واحدًا برصيدَي نقاط: يشتري فتُضاف
     * نقاطه إلى أحدهما، ويأتي ليستبدل فيُقرأ الآخر، فيُقال له «رصيدك صفر»
     * وقد اشترى للتوّ. ولا يظهر شيء من ذلك في أي شاشة — لأن كلا السجلّين
     * صحيح على حدة.
     *
     * ═══ والرقمُ رقمٌ مهما كُتب ═══
     *
     * كان القيد يقابل **النصّ** بالنصّ: `where('phone', $value)`. والناس
     * يكتبون أرقامهم كما اعتادوا — «91234567» و«9123 4567» و«+968 91234567»
     * و«٩١٢٣٤٥٦٧» — فتمرّ الثلاثةُ الأخيرة كأنّها أرقامٌ أخرى. جرّبتُها:
     * **ثلاثةُ عملاء لشخصٍ واحد، بلا رسالةٍ واحدة**. وهو الضررُ بعينه الذي
     * كُتب هذا القيدُ ليمنعه.
     *
     * فيُقابَل الرقمُ مطبَّعًا بـ`WhatsAppPhone::normalize` — وهي «موضعٌ واحد
     * يعرف كيف يُكتب الرقم»، تُسقط المسافات والرموز وتردّ الأرقام العربية
     * لاتينيّةً وتُلبس المحلّيَّ مفتاحَ دولته. ولا يُكتب المطبَّعُ في صفّ
     * العميل: من كتب رقمه بشكلٍ يفهمه يجده كما كتبه.
     *
     * والمقابلةُ النصّيّة تبقى إلى جانبها: رقمٌ لا يصلح للتطبيع — تحويلةٌ
     * داخليّة من ثلاث خانات مثلًا — كان يُحرس أمس، فلا يُترك اليوم.
     *
     * الفراغ مسموح: عابرٌ بلا هاتف عميلٌ صحيح، والقيد على القيم المكتوبة.
     */
    public static function phoneRule(int $businessId, ?int $exceptId = null): array
    {
        return ['nullable', 'string', 'max:50', function ($attribute, $value, $fail) use ($businessId, $exceptId) {
            if (blank($value)) {
                return;
            }

            /*
             * وصفوفُ المتجر تُقرأ لتُقابَل مطبَّعةً — ولا سبيلَ إلى ذلك في
             * `where`: العمود يحمل الرقم كما كُتب، والتطبيع دالّةُ تطبيقٍ لا
             * دالّةُ قاعدة. وهي قراءةُ أعمدةٍ أربعةٍ لعملاء متجرٍ واحد.
             */
            $wanted = WhatsAppPhone::normalize($value);

            $clash = \App\Models\Customer::withTrashed()
                ->where('business_id', $businessId)
                ->whereNotNull('phone')->where('phone', '!=', '')
                ->when($exceptId, fn ($q) => $q->whereKeyNot($exceptId))
                ->get(['id', 'name', 'phone', 'deleted_at'])
                ->first(fn ($c) => (string) $c->phone === (string) $value
                    || ($wanted !== null && WhatsAppPhone::normalize($c->phone) === $wanted));

            if (! $clash) {
                return;
            }

            /*
             * ورقمٌ كُتب بشكلٍ آخر يُقال شكلُه.
             *
             * «هذا الرقم مسجَّل لعميل آخر» عن رقمٍ يراه الكاتبُ مختلفًا عمّا
             * في الشاشة رسالةٌ تُربك ولا تدلّ — فيُذكر الاسمُ والرقمُ كما
             * كُتب، ليُعرف أنّ الشخص واحد.
             */
            if (! $clash->trashed() && (string) $clash->phone !== (string) $value) {
                $fail(__('هذا رقم العميل «:name» مكتوبًا بشكلٍ آخر (:phone) — نقاط الولاء تتبع الرقم.', [
                    'name' => $clash->name, 'phone' => $clash->phone,
                ]));

                return;
            }

            /*
             * والمحذوف يُقال إنه محذوف.
             *
             * القيد كان يقرأ الجدول كلَّه — والمحذوف ناعمًا صفٌّ باقٍ فيه —
             * فيردّ «مسجَّل لعميل آخر» عن عميلٍ لا تعرضه أيّ شاشة. والكاشير
             * واقفٌ والعميل أمامه يبحث عن اسمٍ ليس في القائمة ولا في البحث،
             * فلا مخرج له إلا أن يترك الرقم. والمخرج موجود — استعادةٌ من
             * المحذوفات تردّ العميل بنقاطه — لكنّ الرسالة لم تكن تدلّ عليه.
             */
            $fail($clash->trashed()
                ? __('هذا الرقم مسجَّل لعميل محذوف: «:name» — استعِده من الإعدادات ← المحذوفات.', ['name' => $clash->name])
                : __('هذا الرقم مسجَّل لعميل آخر — نقاط الولاء تتبع الرقم.'));
        }];
    }

    /**
     * مفاتيحُ البحث برقم العميل — رقمُه كما تفهمه `WhatsAppPhone`، لا كما كُتب.
     *
     * العمودُ يحمل الرقم كما كتبه صاحبُه: «91234567» و«+968 9123 4567»
     * و«٩١٢٣-٤٥٦٧». ومن يبحث يكتبه بشكلٍ آخر. فلكلّ رقمٍ مفتاحان: المطبَّعُ
     * (`WhatsAppPhone::normalize` — يُسقط الرموز ويُلبس المحلّيَّ مفتاحَ دولته)،
     * وأرقامُه كما هي لرقمٍ لا يصلح للتطبيع (تحويلةٌ داخليّة). والهاتفُ ورقمُ
     * التواصل معًا: الجهةُ تُعرف بمن يردّ عنها.
     *
     * ويقرؤها البحثُ في الخادم (`idsByPhone`) وفي شاشة الفاتورة الجديدة —
     * فلا يُكتب التطبيعُ مرّتين.
     *
     * @return list<string>
     */
    public static function phoneKeys(?string ...$phones): array
    {
        $keys = [];
        foreach ($phones as $raw) {
            if (blank($raw)) {
                continue;
            }

            $keys[] = WhatsAppPhone::normalize($raw);
            $keys[] = preg_replace('/\D+/', '', Digits::western((string) $raw));
        }

        return array_values(array_unique(array_filter($keys, fn ($k) => $k !== null && $k !== '')));
    }

    /**
     * ما كتبه الباحثُ أرقامًا — أو `null` إن لم يكن رقمَ هاتف.
     *
     * نصٌّ فيه حرف (رقمُ فاتورة «CINV-0003»، أو اسم) ليس هاتفًا، فلا يُطابَق
     * بأرقامه. و«00» بادئةُ الاتصال الدوليّ تسقط كما تسقط في `WhatsAppPhone`.
     * وأقلُّ من ثلاث خانات لا يُبحث به: كلُّ رقمٍ فيه «9».
     */
    public static function phoneNeedle(string $term): ?string
    {
        $western = Digits::western(trim($term));

        if (! preg_match('/^[\d\s+().\/-]+$/', $western)) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $western) ?? '';
        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        }

        return strlen($digits) >= 3 ? $digits : null;
    }

    /**
     * عملاءُ المتجر الذين يحمل رقمُهم — أو رقمُ التواصل — ما كُتب، كاملًا أو جزءًا.
     *
     * والمقابلةُ في التطبيق لا في `where`: التطبيعُ دالّةُ تطبيقٍ لا دالّةُ قاعدة
     * (كما في `phoneRule`). وهي قراءةُ ثلاثة أعمدةٍ لعملاء متجرٍ واحد — ومن متجره
     * وحده، فرقمٌ مطابقٌ في متجرٍ آخر لا يُرى.
     *
     * @return list<int>
     */
    public static function idsByPhone(int $businessId, string $term): array
    {
        $needle = self::phoneNeedle($term);
        if ($needle === null) {
            return [];
        }

        return Customer::where('business_id', $businessId)
            ->where(fn ($q) => $q->where(fn ($w) => $w->whereNotNull('phone')->where('phone', '!=', ''))
                ->orWhere(fn ($w) => $w->whereNotNull('contact_phone')->where('contact_phone', '!=', '')))
            ->get(['id', 'phone', 'contact_phone'])
            ->filter(fn ($c) => self::phoneMatches(self::phoneKeys($c->phone, $c->contact_phone), $needle))
            ->map(fn ($c) => (int) $c->id)->values()->all();
    }

    /** @param  list<string>  $keys */
    public static function phoneMatches(array $keys, string $needle): bool
    {
        foreach ($keys as $key) {
            if (str_contains($key, $needle)) {
                return true;
            }
        }

        return false;
    }
}
