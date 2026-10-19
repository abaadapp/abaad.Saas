<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\BankAccount;
use App\Models\CustomerPayment;
use App\Support\Cheques;
use App\Support\Permissions;
use App\Support\Demo;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/**
 * الشيكات — ورقةٌ في اليد ليست مالًا في البنك.
 *
 * وشاشتُها تحت «المالية» لا تحت «العملاء»: من يُقرّ أنّ شيكًا صُرِف يكتب في
 * الدفتر، وهو إذنٌ ماليّ لا إذنُ إدارةِ عملاء.
 */
class ChequeController extends Controller
{
    private function bid(): int
    {
        return (int) (auth()->user()->business_id ?? Demo::bid());
    }

    /** الشيكُ بعينه — من شيكات هذا المتجر وحده */
    private function cheque(int|string $id): CustomerPayment
    {
        return CustomerPayment::where('business_id', $this->bid())
            ->where('method', Cheques::METHOD)
            ->whereKey($id)->firstOrFail();
    }

    public function index(Request $request): Response
    {
        $bid = $this->bid();

        $status = $request->string('status')->toString();
        $status = in_array($status, Cheques::STATUSES, true) ? $status : Cheques::PENDING;

        $rows = Cheques::query($bid, $status)
            ->with('customer:id,name')
            ->orderByRaw('cheque_due_at is null')
            ->orderBy('cheque_due_at')
            ->orderByDesc('id')
            ->limit(300)
            ->get();

        $today = now()->startOfDay();

        return Inertia::render('Admin/Finance/Cheques', [
            'status' => $status,
            'statuses' => Cheques::STATUSES,
            'summary' => Cheques::summary($bid),
            'soonDays' => Cheques::SOON_DAYS,
            'cheques' => $rows->map(fn (CustomerPayment $p) => [
                'id' => $p->id,
                'number' => $p->number,
                'customer' => $p->customer?->name ?? '—',
                'amount' => (float) $p->amount,
                'reference' => $p->external_reference,
                'received_at' => optional($p->occurred_at)->format('Y-m-d'),
                'due_at' => $p->cheque_due_at ? Carbon::parse($p->cheque_due_at)->format('Y-m-d') : null,
                /*
                 * وتأخّرُ الاستحقاق يُحسب في الخادم لا في المتصفّح.
                 *
                 * ساعةُ الجهاز تُضبط بيد صاحبها، وشاشةٌ تقرأ «متأخّر» من
                 * ساعةٍ مقدَّمةٍ يومين تُري التاجر تأخّرًا لم يقع.
                 */
                'overdue' => $p->cheque_due_at !== null
                    && $p->cheque_status === Cheques::PENDING
                    && Carbon::parse($p->cheque_due_at)->lt($today),
                'settled_at' => optional($p->cheque_settled_at)->format('Y-m-d'),
                'note' => $p->cheque_note,
                'status' => $p->cheque_status,
            ])->all(),
            /*
             * والاسمُ من `displayName()` لا من عمودٍ اسمُه `name`.
             *
             * لا عمودَ بهذا الاسم في الجدول: الاسمُ يُشتقّ من الوسم أو اسم
             * البنك أو آخرِ أربعةٍ من الآيبان. و`get(['id','name'])` كانت
             * تُخرج قائمةً بلا أسماء — مقبضٌ يُختار منه فارغ.
             *
             * والمعطَّلُ لا يُعرض ويبقى مقبولًا في الخادم: شيكٌ قديمٌ قد
             * يشير إلى حسابٍ أُغلق.
             */
            'bankAccounts' => BankAccount::where('business_id', $bid)
                ->where('active', true)
                ->orderByDesc('is_primary')->orderBy('id')
                ->get(['id', 'label', 'bank_name', 'account_name', 'iban', 'is_primary'])
                ->map(fn (BankAccount $a) => ['value' => (string) $a->id, 'label' => $a->displayName()])->all(),
        ]);
    }

    public function clear(Request $request, int|string $id)
    {
        /*
         * والصرفُ يكتبه من يسجّل التحصيل — لا من يقرأ «المالية» وحدها.
         *
         * هو تمامُ التحصيل: ينقل المالَ من «شيكات تحت التحصيل» إلى البنك، ولا
         * يمسّ ذمّةَ العميل (`Permissions::CUSTOMER_PAYMENT_CREATE`). وأخوه
         * `bounce` غيرُه — انظره.
         */
        abort_unless(auth()->user()?->may(Permissions::CUSTOMER_PAYMENT_CREATE), 403);

        $data = $request->validate([
            'cleared_on' => ['nullable', 'date'],
            'bank_account_id' => ['nullable', 'integer'],
        ]);

        /*
         * والشيكُ يُطلب **خارج** `try`.
         *
         * `ModelNotFoundException` يرث `RuntimeException` — فكان `catch`
         * أسفلُ يبتلع «ليس من شيكاتك» ويردّه خطأَ تحقّقٍ على حقل التاريخ.
         * فيرى من جرّب معرّفًا من متجرٍ آخر رسالةً عن تاريخٍ خاطئ، ولا يُردّ
         * بـ404 كما يجب.
         */
        $cheque = $this->cheque($id);

        try {
            $cheque = Cheques::clear(
                $cheque,
                $data['cleared_on'] ?? null,
                isset($data['bank_account_id']) ? (int) $data['bank_account_id'] : null,
                auth()->id(),
            );
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['cleared_on' => $e->getMessage()]);
        }

        return back()->with('toast', [
            'msg' => __('حُصّل الشيك :n', ['n' => $cheque->number]),
            'type' => 'success',
        ]);
    }

    public function bounce(Request $request, int|string $id)
    {
        /*
         * والارتدادُ إلغاءُ تحصيلٍ باسمٍ آخر — فبفعل الإلغاء لا بفعل التسجيل.
         *
         * يعكس قيدَ القبض نفسَه (`Ledger::reverse`) ويُسقط السندَ من مدفوع
         * الفاتورة (`CustomerInvoice::paidTotal`): فتعود الذمّةُ على العميل كما يعيدها
         * `CustomerPayments::cancel` حرفًا بحرف. فلو كفاه `customerPayment.create`
         * لألغى تحصيلَ الشيك من حُجب عنه الإلغاء — بسببٍ يكتبه هو: «رصيدٌ لا
         * يكفي». والتسجيلُ يرثه كلُّ من كانت له «المالية» (`LEGACY_SECTION_ACTIONS`)،
         * فكان الحارسُ القسمَ نفسَه تقريبًا.
         */
        abort_unless(auth()->user()?->may(Permissions::CUSTOMER_PAYMENT_CANCEL), 403);

        $data = $request->validate([
            'reason' => ['required', 'string', 'max:200'],
        ]);

        /* وخارج `try` كذلك — انظر `clear` */
        $cheque = $this->cheque($id);

        try {
            $cheque = Cheques::bounce($cheque, $data['reason'], auth()->id());
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['reason' => $e->getMessage()]);
        }

        return back()->with('toast', [
            'msg' => __('سُجّل ارتداد الشيك :n — وعادت الذمّة على العميل', ['n' => $cheque->number]),
            'type' => 'warning',
        ]);
    }
}
