<?php

namespace App\Support;

use App\Models\PlatformDocument;
use App\Models\PlatformDocumentCode;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * رمزُ التحقّق قبل التوقيع — رابطُ المستند وحده لا يكفي.
 *
 * ═══ المسار ═══
 *
 * يفتح العميلُ الرابطَ فلا يرى إلّا عنوانَ المستند وآخرَ أربعة أرقامٍ من
 * هاتفه المعتمد. يطلب الرمز فيُرسل إلى ذلك الرقم وحده (`signing_phone`،
 * يكتبه مديرُ المنصّة عند الإصدار ولا تغيّره صفحةُ التوقيع). يكتبه فتُفتح
 * له في متصفّحه هو بياناتُ المستند والتوقيع: الإذنُ في جلسته، وفي الصفّ
 * بصمتُه، فمن نسخ الرابط إلى جهازٍ آخر بدأ من أوّله.
 *
 * ═══ حدود الرمز ═══
 *
 *   - ستّةُ أرقامٍ عشوائيّة، صالحةٌ عشرَ دقائق، بخمس محاولات.
 *   - الأحدثُ وحده يعمل: طلبُ رمزٍ جديد يُبطل ما قبله ممّا لم يُتحقَّق منه.
 *   - دقيقةٌ بين طلبين، وخمسةٌ في الساعة، وعشرةٌ في اليوم لكلّ مستند — رسالةٌ
 *     تُدفع كلفتُها، وبابٌ لا يُطرق بلا حدّ.
 *   - لا يُحفظ الرمزُ بل HMAC له بسرّ التطبيق، مربوطًا بالمستند والرقم. ولا
 *     يُكتب في سجلّ النشاط ولا في سجلّ الأخطاء ولا في رابط.
 *   - التحقّقُ يأذن بالتوقيع نصفَ ساعة، ويُستهلك مع التوقيع في معاملته
 *     نفسها تحت قفل المستند. والإلغاءُ والانتهاءُ يُبطلانه.
 *
 * وما يُثبته الرمزُ أنّ الموقِّعَ يملك الهاتفَ المعتمد ساعةَ التوقيع — لا
 * هويّتَه الشخصيّة، ولا هو توثيقٌ قانونيٌّ لها.
 */
class SigningCodes
{
    public const DIGITS = 6;

    public const CODE_MINUTES = 10;

    public const MAX_ATTEMPTS = 5;

    public const RESEND_SECONDS = 60;

    public const PER_HOUR = 5;

    public const PER_DAY = 10;

    /** نافذةُ ما بعد التحقّق: يقرأ فيها العميلُ المستندَ ويكمله ويوقّعه */
    public const GRANT_MINUTES = 30;

    private const SESSION = 'signing_grants';

    /** هل رُبط مزوّدٌ يوصل الرمز؟ — وإلّا فالتوقيعُ مغلق */
    public static function available(): bool
    {
        return app()->bound(SigningCodeSender::class);
    }

    /** «•••• 4567» — ما تعرضه الصفحةُ العامّة من الرقم */
    public static function mask(?string $phone): string
    {
        $digits = preg_replace('/\D+/', '', (string) $phone) ?? '';

        return '•••• '.substr($digits, -4);
    }

    /**
     * يرسل رمزًا جديدًا إلى الرقم المعتمد — ويُبطل ما قبله ممّا لم يُستعمل.
     */
    public static function send(PlatformDocument $document, ?string $ip): void
    {
        if (! self::available()) {
            throw new RuntimeException(__('التوقيع الإلكتروني غير متاح حاليًا.'));
        }
        $phone = (string) $document->signing_phone;
        if ($phone === '') {
            throw new RuntimeException(__('لا رقم معتمد لهذا المستند — تواصل مع مُرسله.'));
        }

        $code = str_pad((string) random_int(0, 10 ** self::DIGITS - 1), self::DIGITS, '0', STR_PAD_LEFT);

        $row = DB::transaction(function () use ($document, $phone, $code, $ip) {
            // تحت قفل المستند: طلبان معًا لا يعبران الحدَّ معًا
            $doc = PlatformDocument::whereKey($document->id)->lockForUpdate()->firstOrFail();
            if (! $doc->readable()) {
                throw new RuntimeException(__('هذا الرابط لم يعد صالحًا.'));
            }

            $sent = PlatformDocumentCode::where('document_id', $doc->id);
            $last = (clone $sent)->latest('id')->first();
            if ($last && $last->created_at->gt(now()->subSeconds(self::RESEND_SECONDS))) {
                throw new RuntimeException(__('انتظر دقيقة قبل طلب رمز جديد.'));
            }
            if ((clone $sent)->where('created_at', '>', now()->subHour())->count() >= self::PER_HOUR
                || (clone $sent)->where('created_at', '>', now()->subDay())->count() >= self::PER_DAY) {
                throw new RuntimeException(__('طلبت رموزًا كثيرة — حاول لاحقًا أو تواصل مع مُرسل المستند.'));
            }

            PlatformDocumentCode::where('document_id', $doc->id)->whereNull('verified_at')->whereNull('revoked_at')
                ->update(['revoked_at' => now(), 'updated_at' => now()]);

            return PlatformDocumentCode::create([
                'document_id' => $doc->id,
                'phone' => $phone,
                'code_hash' => self::digest($doc, $phone, $code),
                'expires_at' => now()->addMinutes(self::CODE_MINUTES),
                'requested_ip' => $ip ? mb_substr($ip, 0, 45) : null,
            ]);
        });

        try {
            app(SigningCodeSender::class)->send($phone, $code, $document);
        } catch (Throwable $e) {
            $row->forceFill(['revoked_at' => now()])->save();
            // سببُ الفشل للمطوّر — والرمزُ ممحوٌّ منه لو حمله المزوّدُ في رسالته
            Log::warning('signing code not delivered', [
                'document' => $document->id,
                'error' => str_replace($code, '******', $e->getMessage()),
            ]);

            throw new RuntimeException(__('تعذّر إرسال الرمز الآن — حاول بعد قليل.'));
        }

        Activity::log('updated', 'أُرسل رمز تحقق للمستند '.$document->number.' إلى '.self::mask($phone), [
            'business_id' => null, 'subject_id' => $document->id, 'subject_type' => PlatformDocument::class,
        ]);
    }

    /**
     * يتحقّق من الرمز — ويعيد الإذنَ الذي يُحفظ في جلسة هذا المتصفّح.
     *
     * والمحاولةُ الخاطئة تُكتب قبل الرفض: الرفضُ رميٌ، والرميُ داخل المعاملة
     * يمحو ما كُتب فيها، فيصير الحدُّ بلا حدّ.
     *
     * @return array{0:int, 1:string}
     */
    public static function verify(PlatformDocument $document, string $input, ?string $ip): array
    {
        $code = preg_replace('/\s+/', '', (string) Digits::western($input)) ?? '';
        if (! preg_match('/^\d{'.self::DIGITS.'}$/', $code)) {
            throw new RuntimeException(__('اكتب الرمز المكوّن من 6 أرقام.'));
        }

        $out = DB::transaction(function () use ($document, $code, $ip) {
            $doc = PlatformDocument::whereKey($document->id)->lockForUpdate()->firstOrFail();
            if (! $doc->readable()) {
                return ['error' => __('هذا الرابط لم يعد صالحًا.')];
            }

            $row = PlatformDocumentCode::where('document_id', $doc->id)
                ->whereNull('verified_at')->whereNull('revoked_at')
                ->latest('id')->lockForUpdate()->first();
            if (! $row) {
                return ['error' => __('اطلب رمز التحقق أولًا.')];
            }
            if ($row->expires_at->isPast()) {
                return ['error' => __('انتهت صلاحية الرمز — اطلب رمزًا جديدًا.')];
            }
            if ($row->attempts >= self::MAX_ATTEMPTS) {
                return ['error' => __('استنفدت محاولات هذا الرمز — اطلب رمزًا جديدًا.')];
            }
            if ($row->phone !== $doc->signing_phone) {
                $row->forceFill(['revoked_at' => now()])->save();

                return ['error' => __('اطلب رمزًا جديدًا.')];
            }

            $row->increment('attempts');
            if (! hash_equals($row->code_hash, self::digest($doc, $row->phone, $code))) {
                $left = self::MAX_ATTEMPTS - $row->attempts;

                return ['error' => $left > 0
                    ? __('الرمز غير صحيح — بقيت :n محاولات.', ['n' => $left])
                    : __('الرمز غير صحيح — استنفدت المحاولات، اطلب رمزًا جديدًا.')];
            }

            $nonce = Str::random(40);
            $row->forceFill([
                'verified_at' => now(),
                'verified_ip' => $ip ? mb_substr($ip, 0, 45) : null,
                'grant_hash' => hash('sha256', $nonce),
            ])->save();

            // «فتحه العميل» حين أثبت رقمه — لا حين جلبت معاينةُ رابطٍ الصفحة
            if ($doc->viewed_at === null) {
                $doc->forceFill(['viewed_at' => now()])->save();
            }

            return ['grant' => [$row->id, $nonce]];
        });

        if (isset($out['error'])) {
            throw new RuntimeException($out['error']);
        }

        return $out['grant'];
    }

    /** يحفظ الإذنَ في جلسة المتصفّح الذي تحقّق */
    public static function remember(Session $session, PlatformDocument $document, array $grant): void
    {
        $session->put(self::SESSION.'.'.$document->id, $grant);
    }

    /** الإذنُ الذي يحمله هذا المتصفّح للمستند — كما حُفظ، بلا فحص */
    public static function held(Session $session, PlatformDocument $document): ?array
    {
        $grant = $session->get(self::SESSION.'.'.$document->id);

        return is_array($grant) && count($grant) === 2 ? array_values($grant) : null;
    }

    /** هل يقرأ هذا المتصفّحُ المستند؟ — تحقّق منه، والرابطُ مفتوح */
    public static function granted(Session $session, PlatformDocument $document): bool
    {
        $grant = self::held($session, $document);
        if (! $grant || ! $document->readable()) {
            return false;
        }

        return self::valid(PlatformDocumentCode::whereKey($grant[0])->first(), $document, (string) $grant[1]);
    }

    /**
     * يستهلك الإذنَ مع التوقيع — داخل معاملته وتحت قفل المستند.
     *
     * @param  array{0:int, 1:string}|null  $grant
     */
    public static function consume(PlatformDocument $lockedDocument, ?array $grant): PlatformDocumentCode
    {
        $row = $grant ? PlatformDocumentCode::whereKey($grant[0])->lockForUpdate()->first() : null;
        if (! $row || $row->consumed_at !== null || ! self::valid($row, $lockedDocument, (string) $grant[1])) {
            throw new RuntimeException(__('أثبت رقمك برمز التحقق قبل التوقيع.'));
        }

        $row->forceFill(['consumed_at' => now()])->save();

        return $row;
    }

    /** يُبطل ما لم يُستهلك من رموز المستند — عند إلغائه */
    public static function revoke(PlatformDocument $document): void
    {
        PlatformDocumentCode::where('document_id', $document->id)->whereNull('consumed_at')->whereNull('revoked_at')
            ->update(['revoked_at' => now(), 'updated_at' => now()]);
    }

    /** آخرُ رمزٍ ينتظر الكتابة — لتعرض الصفحةُ خانتَه */
    public static function pending(PlatformDocument $document): ?PlatformDocumentCode
    {
        return PlatformDocumentCode::where('document_id', $document->id)
            ->whereNull('verified_at')->whereNull('revoked_at')
            ->where('expires_at', '>', now())->where('attempts', '<', self::MAX_ATTEMPTS)
            ->latest('id')->first();
    }

    private static function valid(?PlatformDocumentCode $row, PlatformDocument $document, string $nonce): bool
    {
        return $row !== null
            && (int) $row->document_id === (int) $document->id
            && $row->verified_at !== null
            && $row->revoked_at === null
            && $row->grant_hash !== null
            && hash_equals($row->grant_hash, hash('sha256', $nonce))
            && $document->signing_phone !== null
            && $row->phone === $document->signing_phone
            && $row->verified_at->gt(now()->subMinutes(self::GRANT_MINUTES));
    }

    /** بصمةُ الرمز — بسرّ التطبيق، مربوطةً بالمستند والرقم */
    private static function digest(PlatformDocument $document, string $phone, string $code): string
    {
        return hash_hmac('sha256', $document->id.'|'.$phone.'|'.$code, (string) config('app.key'));
    }
}
