<?php

namespace App\Exceptions;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * قيدٌ بتاريخٍ في فترةٍ مقفلة — رُدّ قبل أن يُكتب منه حرف.
 *
 * ═══ ولمَ `RuntimeException` ═══
 *
 * هو عقدُ `Ledger::post` منذ كان: قيدٌ مختلّ، أو حسابٌ مغلق، أو مرجعُ متجرٍ
 * آخر — كلُّها `RuntimeException` يلتقطها المستدعي ويعرض رسالتها. فهذا
 * يمرّ من الأبواب نفسها بلا تعديلٍ فيها: صندوقٌ يلتقط ليُبقي البيعة،
 * ومتحكّمٌ يلتقط ليقول السبب.
 *
 * وصنفُه الخاصّ لما لا يلتقطه أحد: يُعرض جوابًا مفهومًا (`render`) لا
 * صفحةَ خطأ خادم، ولا يُسجَّل عطبًا (`report`) — هو جوابٌ لا عطب.
 */
class ClosedPeriod extends RuntimeException
{
    public function __construct(
        public readonly Carbon $date,
        public readonly Carbon $closedThrough,
        public readonly bool $byVatReturn,
    ) {
        parent::__construct($byVatReturn
            ? __('قُدِّم إقرارُ الضريبة حتى :through، فلا يُكتب قيدٌ بتاريخ :date. اختر تاريخًا بعد :through.', [
                'through' => $closedThrough->toDateString(), 'date' => $date->toDateString(),
            ])
            : __('الدفاتر مقفلة حتى :through، فلا يُكتب قيدٌ بتاريخ :date. اختر تاريخًا بعده، أو يُعيد صاحبُ النشاط فتحَ الفترة من الإعدادات.', [
                'through' => $closedThrough->toDateString(), 'date' => $date->toDateString(),
            ]));
    }

    /** جوابٌ للتاجر لا عطبٌ في السجلّ */
    public function report(): void {}

    public function render(Request $request): Response
    {
        if ($request->expectsJson()) {
            return response()->json([
                'message' => $this->getMessage(),
                'errors' => ['period' => [$this->getMessage()]],
            ], 422);
        }

        return back()
            ->withErrors(['period' => $this->getMessage()])
            ->with('toast', ['msg' => $this->getMessage(), 'type' => 'danger']);
    }
}
