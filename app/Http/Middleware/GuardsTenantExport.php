<?php

namespace App\Http\Middleware;

use App\Support\TenantExportGuard;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * يقف أمام كلِّ تصديرٍ ومستندٍ في لوحة النشاط ونقطة البيع — قبل المتحكّم.
 *
 * مسارٌ لا يعرفه `TenantExportGuard` يمرّ كما هو. والقرارُ كلُّه هناك، لا في
 * عشرين متحكّمًا: من يضيف تصديرًا جديدًا يضيف اسمَه إلى القائمة، وحارسُ الجرد
 * في الاختبارات يُسقط من نسي.
 */
class GuardsTenantExport
{
    public function handle(Request $request, Closure $next): Response
    {
        $policy = TenantExportGuard::policyFor($request->route()?->getName());

        if ($policy === null) {
            return $next($request);
        }

        $reason = TenantExportGuard::verdict($request, $policy);

        return $reason === null ? $next($request) : TenantExportGuard::refuse($request, $reason);
    }
}
