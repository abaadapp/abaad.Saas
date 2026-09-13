<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * حارسُ فعلٍ بعينه — لا قسمٍ كامل.
 *
 * `CheckAbility` تحرس القسم المستنتَج من اسم المسار، وهي كافيةٌ حين يكون
 * القسمُ وحدةً واحدة. ومنها ما ليس كذلك: «الموقع الإلكتروني» تشغيلٌ يوميٌّ
 * يفعله الموظّف وضبطٌ يملكه صاحب المتجر، وقسمٌ واحد يمنحهما معًا.
 *
 * والحراسةُ في المسار لا في أوّل كلّ دالّة: دالّةٌ تُضاف غدًا وينسى كاتبُها
 * سطرَ الفحص تفتح البابَ كلَّه، ولا شيء يكشفه — المسارُ يعمل، والشاشةُ تُرسم،
 * والتاجرُ لا يعرف أنّ موظّفًا بدّل نطاقه.
 *
 *   Route::middleware('may:website.configure')->group(...)
 */
class RequireAction
{
    public function handle(Request $request, Closure $next, string $action): Response
    {
        $user = $request->user();

        if (! $user) {
            return redirect()->route('login');
        }

        abort_unless($user->may($action), 403, __('ليس لديك صلاحية لهذا الإجراء.'));

        return $next($request);
    }
}
