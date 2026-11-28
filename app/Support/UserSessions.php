<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

/**
 * جلساتُ حسابٍ تُنهى حين يتغيّر ما يُسمح له به — لا حين يخرج هو.
 *
 * ═══ لماذا ═══
 *
 * التعطيلُ يُطرد في الطلب التالي (`CheckTenantStatus`)، لكنّ «تذكّرني» تبقى:
 * يُعاد تفعيلُ الحساب فيعود جهازٌ قديمٌ بكعكته كأنّ شيئًا لم يكن. والربطُ ببوتيك
 * أو فكُّه يغيّر ما يبلغه الحساب كلَّه، والجلسةُ المفتوحة على هاتفٍ ما زالت هي:
 * من فُكّ ربطُه عاد إلى صلاحيّاته كلِّها في جلسةٍ لم يدخلها التاجرُ ليراها.
 *
 * فحين يُعطَّل الحساب، أو يتغيّر ربطُه ببوتيك (رُبط أو نُقل أو فُكّ):
 *   - تُحذف جلساتُه من جدول الجلسات (مشغّلُ الإنتاج `database`)،
 *   - ويُبدَّل رمزُ «تذكّرني» فلا تُحييها كعكةٌ قديمة،
 *   - ويُختم الربطُ في كلّ جلسة (`stamp`): جلسةٌ فُتحت على ربطٍ غيرِ ربطه الآن
 *     تُنهى في طلبها التالي — ولو لم يكن المشغّلُ `database` فلم يُحذف شيء.
 *
 * فيدخل من جديدٍ بكلمة مروره، على ما صار له.
 */
final class UserSessions
{
    /** مفتاحُ الختم في الجلسة: لمن فُتحت، وعلى أيّ ربط */
    public const STAMP = 'account_binding';

    /** يُنهي كلَّ جلسةٍ مفتوحةٍ لهذا الحساب، ويُبطل «تذكّرني» */
    public static function end(User $user): void
    {
        try {
            if (config('session.driver') === 'database' && Schema::hasTable($table = (string) config('session.table', 'sessions'))) {
                DB::table($table)->where('user_id', $user->id)->delete();
            }
        } catch (Throwable) {
            // والختمُ يُنهيها في طلبها التالي على كلّ حال
        }

        // بلا أحداث النموذج: تبديلُ رمزٍ لا تعديلٌ يُسجَّل أو يُراقَب
        $token = Str::random(60);
        DB::table($user->getTable())->where('id', $user->id)->update(['remember_token' => $token]);
        $user->setRememberToken($token);
    }

    /** ما يُختم به الربط: الحسابُ وبوتيكُه */
    private static function binding(User $user): array
    {
        return ['user' => (int) $user->id, 'boutique' => $user->boutique_id !== null ? (int) $user->boutique_id : null];
    }

    /**
     * أفُتحت هذه الجلسةُ على ربطٍ غيرِ ربطه الآن؟ — فتُنهى ويُقال ذلك.
     *
     * جلسةٌ لا ختمَ فيها (فُتحت قبل هذا، أو هويّةٌ تبدّلت بالدخول كمستخدم) تُختم ولا
     * تُنهى: لا يُخرَج أحدٌ يوم النشر، ولا يُخرَج مديرُ المنصّة حين يدخل كتاجر.
     */
    public static function stale(Request $request, User $user): bool
    {
        if (! $request->hasSession()) {
            return false;
        }

        $session = $request->session();
        $now = self::binding($user);
        $seen = $session->get(self::STAMP);

        if (! is_array($seen) || ($seen['user'] ?? null) !== $now['user']) {
            $session->put(self::STAMP, $now);

            return false;
        }

        if (($seen['boutique'] ?? null) === $now['boutique']) {
            return false;
        }

        Auth::guard('web')->logout();
        $session->invalidate();
        $session->regenerateToken();

        return true;
    }
}
