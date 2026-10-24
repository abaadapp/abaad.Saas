<?php

namespace App\Support;

use App\Models\Branch;
use App\Models\BranchOperationEvent;
use App\Models\BranchStock;
use App\Models\BranchTransfer;
use App\Models\BranchTransferLine;
use App\Models\Business;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\StockTransfer;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * نقلُ المخزون بين الفروع — مراحلُ السند، ومن يفعل كلَّ مرحلة، وحركةُ الرفوف.
 *
 *   طلب ← اعتماد ← تحضير ← إرسال (قيدَ النقل) ← استلام
 *                                              ↘ فرقٌ في الاستلام ← تسوية ← مستلم
 *   ويُرفض الطلب، ويُلغى ما لم يُرسل.
 *
 * ═══ ولا بابَ ثانٍ ═══
 *
 * كلُّ انتقالٍ هنا: يقفل صفَّ السند، ويقرأ حالتَه بعد القفل، ويسأل الفاعلَ عن
 * فعله وفرعه، ثمّ يكتب الحالةَ والحركاتِ وسطرَ السجلّ في معاملةٍ واحدة. فضغطتان
 * على «إرسال» لا تُخرجان البضاعة مرّتين — الثانية تقرأ «قيد النقل» فتُردّ —،
 * ومتحكّمٌ يُنسى فيه فحصٌ لا يفتح بابًا: الفحصُ في الخدمة لا في المتحكّم.
 *
 * ═══ والمخزونُ قيدَ النقل ═══
 *
 * الإرسالُ يُخرج الكميّة من رفّ المصدر ومن الإجماليّ معًا — بالخصم المشروط
 * (`BranchStock::draw`): لا يُرسَل ما ليس على الرفّ، ولا ينزل رصيدٌ تحت الصفر
 * ولو تزامن مع بيع. فيبقى «مجموع الفروع = كميّة المنتج» صادقًا في كلّ لحظة،
 * وما بين الفرعين في سطر السند (`BranchTransferLine::outstanding`). ولا يُباع
 * ما قيدَ النقل في الصندوق ولا في الموقع: ليس على رفٍّ ولا في الإجماليّ.
 *
 * والاستلامُ يُدخل ما وصل رفَّ المستلم والإجماليَّ معًا. وما نقص يبقى قيدَ
 * النقل فرقًا معلّقًا — لا يُمحى صامتًا ولا يُضاف للمستلم — حتى يُسوّى بإجراءٍ
 * موثَّق (`resolve`).
 *
 * ═══ ولا قيدَ محاسبيًّا للنقل ═══
 *
 * البضاعةُ لم تخرج من ملك النشاط: حسابُ المخزون في الدفتر كما هو. والقيدُ
 * الوحيد هنا تسويةُ «فُقد أو تلف» — خسارةٌ بالقاعدة نفسِها التي تكتب بها شاشةُ
 * التعديلات تلفَها (`StockLosses::record`).
 */
final class BranchTransfers
{
    /** أنواعُ حركات المخزون — تُقرأ في «سجل المخزون» */
    public const MOVE_OUT = 'نقل — إرسال';

    public const MOVE_IN = 'نقل — استلام';

    public const MOVE_RETURN = 'نقل — إرجاع إلى المصدر';

    public const MOVE_LATE = 'نقل — استلام متأخر';

    /** نوعُ صفّ المصروف حين يُسوّى فرقٌ بـ«فُقد أو تلف» */
    public const LOSS_TYPE = 'فقد أثناء النقل';

    /** أبوابُ تسوية الفرق */
    public const RESOLVE_LOST = 'lost';

    public const RESOLVE_RETURNED = 'returned';

    public const RESOLVE_LATE = 'late';

    public const RESOLUTIONS = [self::RESOLVE_LOST, self::RESOLVE_RETURNED, self::RESOLVE_LATE];

    // ═══ من يفعل ماذا ═══

    /** صاحبُ النشاط أو موظّفٌ مسندٌ إلى أحد هذه الفروع */
    public static function covers(User $user, ?int ...$branchIds): bool
    {
        if (Permissions::isOwner($user)) {
            return true;
        }

        foreach ($branchIds as $id) {
            if ($id && $user->worksAt($id)) {
                return true;
            }
        }

        return false;
    }

    /** هل يملك فعلًا من أفعال عمليات الفروع أصلًا؟ — يقرؤه البابُ والقائمة */
    public static function participates(User $user): bool
    {
        foreach (Permissions::TRANSFER_ACTIONS as $action) {
            if ($user->may($action)) {
                return true;
            }
        }

        return false;
    }

    /** يرى السند: من يرى التحويلاتِ كلَّها، أو له فعلٌ فيها وهو في أحد طرفيه */
    public static function canView(User $user, BranchTransfer $transfer): bool
    {
        if ((int) $transfer->business_id !== (int) $user->business_id) {
            return false;
        }

        if (Permissions::isOwner($user) || $user->may(Permissions::TRANSFER_VIEW_ALL)) {
            return true;
        }

        return self::participates($user) && self::covers($user, $transfer->from_branch_id, $transfer->to_branch_id);
    }

    /** ما يُعرض من السندات في القائمة — بالقاعدة نفسها */
    public static function visibleTo(User $user)
    {
        $q = BranchTransfer::where('business_id', $user->business_id);

        if (Permissions::isOwner($user) || $user->may(Permissions::TRANSFER_VIEW_ALL)) {
            return $q;
        }

        if (! self::participates($user)) {
            return $q->whereRaw('1 = 0');
        }

        $allowed = CostsAndLosses::allowedBranches((int) $user->business_id, $user);

        return $allowed === null ? $q : $q->where(fn ($w) => $w->whereIn('from_branch_id', $allowed)->orWhereIn('to_branch_id', $allowed));
    }

    /**
     * ما يملك الفاعلُ أن يفعله بهذا السند الآن — تقرؤه الشاشةُ فتُظهر أزرارها.
     *
     * والخادمُ يسأل السؤالَ نفسَه عند كلّ فعل (`authorize`): زرٌّ يُعرض ولا
     * يعمل أسوأ من زرٍّ لا يُعرض، وزرٌّ يُخفى ولا يُحرس بابُه ليس حارسًا.
     *
     * @return array<string, bool>
     */
    public static function abilities(User $user, BranchTransfer $transfer): array
    {
        $out = [];
        foreach (['approve', 'reject', 'prepare', 'dispatch', 'receive', 'resolve', 'cancel'] as $step) {
            $out[$step] = self::refusal($user, $step, $transfer) === null;
        }

        return $out;
    }

    /** يقطع إن لم يكن له أن يفعل — برسالةٍ تقول لماذا */
    public static function authorize(User $user, string $step, BranchTransfer $transfer): void
    {
        $why = self::refusal($user, $step, $transfer);
        if ($why !== null) {
            throw new TransferRefused($why);
        }
    }

    /** سببُ الرفض — أو `null` إن جاز */
    public static function refusal(User $user, string $step, BranchTransfer $transfer): ?string
    {
        if ((int) $transfer->business_id !== (int) $user->business_id) {
            return __('هذا السند ليس من نشاطك.');
        }

        $from = $transfer->from_branch_id;
        $to = $transfer->to_branch_id;
        $status = $transfer->status;

        // ما يُنشئ عملًا جديدًا يحتاج القدرة؛ وما يُغلق عملًا قائمًا يبقى بعد تخفيض الباقة
        if (in_array($step, ['approve', 'prepare', 'dispatch'], true) && ! self::licensed($transfer->business_id)) {
            return PlanFeatures::refusal(Business::find($transfer->business_id), 'enterprise_transfers');
        }

        return match ($step) {
            'approve', 'reject' => match (true) {
                $status !== BranchTransfer::PENDING => __('السند ليس بانتظار الموافقة.'),
                ! $user->may(Permissions::TRANSFER_APPROVE) => __('ليست لديك صلاحية الموافقة على النقل.'),
                ! self::covers($user, $from) => __('لست مُسنَدًا إلى الفرع المصدر.'),
                // فصلُ المسؤوليّات: لا يعتمد طلبَه إلّا صاحبُ النشاط بقرارٍ صريح (`approve`)
                $step === 'approve' && (int) $transfer->requested_by === (int) $user->id && ! Permissions::isOwner($user) => __('لا يعتمد صاحبُ الطلب طلبَه — يعتمده غيرُه.'),
                default => null,
            },
            'prepare' => match (true) {
                $status !== BranchTransfer::APPROVED => __('السند ليس معتمدًا بانتظار التحضير.'),
                ! $user->may(Permissions::TRANSFER_DISPATCH) => __('ليست لديك صلاحية تجهيز البضاعة.'),
                ! self::covers($user, $from) => __('لست مُسنَدًا إلى الفرع المصدر.'),
                default => null,
            },
            'dispatch' => match (true) {
                $status !== BranchTransfer::PREPARING => __('السند ليس قيد التحضير — جهّزه قبل الإرسال.'),
                ! $user->may(Permissions::TRANSFER_DISPATCH) => __('ليست لديك صلاحية تأكيد الإرسال.'),
                ! self::covers($user, $from) => __('لست مُسنَدًا إلى الفرع المصدر.'),
                default => null,
            },
            'receive' => match (true) {
                $status !== BranchTransfer::IN_TRANSIT => __('السند ليس بانتظار الاستلام.'),
                ! $user->may(Permissions::TRANSFER_RECEIVE) => __('ليست لديك صلاحية تأكيد الاستلام.'),
                ! self::covers($user, $to) => __('لست مُسنَدًا إلى الفرع المستلم.'),
                default => null,
            },
            'resolve' => match (true) {
                $status !== BranchTransfer::DISCREPANCY => __('لا فرقَ معلّقًا في هذا السند.'),
                ! $user->may(Permissions::TRANSFER_RESOLVE) => __('ليست لديك صلاحية معالجة فرق الاستلام.'),
                ! self::covers($user, $from, $to) => __('لست مُسنَدًا إلى أحد فرعي السند.'),
                default => null,
            },
            'cancel' => match (true) {
                in_array($status, BranchTransfer::MOVING, true) => __('أُرسلت البضاعة — يُغلق السند بالاستلام، وما لم يصل يُسوّى «أُعيد إلى المصدر».'),
                ! in_array($status, [BranchTransfer::PENDING, BranchTransfer::APPROVED, BranchTransfer::PREPARING], true) => __('السند منتهٍ ولا يُلغى.'),
                // صاحبُ الطلب يسحبه ما دام بانتظار الموافقة
                $status === BranchTransfer::PENDING && (int) $transfer->requested_by === (int) $user->id => null,
                ! $user->may(Permissions::TRANSFER_APPROVE) => __('ليست لديك صلاحية إلغاء سندٍ معتمد.'),
                ! self::covers($user, $from) => __('لست مُسنَدًا إلى الفرع المصدر.'),
                default => null,
            },
            default => __('إجراءٌ غير معروف.'),
        };
    }

    /** هل تملك باقةُ النشاط نقلَ المخزون؟ */
    public static function licensed(int $businessId): bool
    {
        return PlanFeatures::allows(Business::find($businessId), 'enterprise_transfers');
    }

    // ═══ المراحل ═══

    /**
     * طلبُ نقل — لا يحرّك رفًّا.
     *
     * و`execute` لمن يملك المراحلَ الثلاث في الفرع المصدر: يُعتمد ويُجهَّز
     * ويُرسَل في المعاملة نفسها، وتُكتب كلُّ مرحلةٍ في السجلّ باسمه. والاستلامُ
     * يبقى للفرع المستلم: ما وصل يقوله من فتح الصندوق لا من أرسله.
     *
     * @param  array{from_branch_id: int, to_branch_id: int, lines: list<array{product_id: int, quantity: int}>, notes?: ?string, responsible_user_id?: ?int, client_key?: ?string, execute?: bool, self_approve?: bool}  $data
     */
    public static function create(User $user, array $data, ?int $supplyRequestId = null, ?User $requester = null, bool $preApproved = false): BranchTransfer
    {
        $bid = (int) $user->business_id;

        if (! self::licensed($bid)) {
            throw new TransferRefused(PlanFeatures::refusal($user->business, 'enterprise_transfers'));
        }

        [$from, $to] = self::branches($bid, (int) $data['from_branch_id'], (int) $data['to_branch_id']);

        if (! $preApproved) {
            if (! $user->may(Permissions::TRANSFER_CREATE)) {
                throw new TransferRefused(__('ليست لديك صلاحية إنشاء طلب نقل.'));
            }
            if (! self::covers($user, $from->id, $to->id)) {
                throw new TransferRefused(__('لست مُسنَدًا إلى أحد الفرعين.'));
            }
        }

        $lines = self::lines($bid, $data['lines'] ?? []);
        $responsible = self::responsible($bid, $data['responsible_user_id'] ?? null);
        $key = self::clientKey($data['client_key'] ?? null);
        $requester ??= $user;

        /*
         * والتنفيذُ المباشر معاملةٌ واحدة تحيط بالمراحل كلّها: رصيدٌ ناقصٌ عند
         * الإرسال يُرجع الاعتمادَ والتحضيرَ والطلبَ معه — لا سندٌ يبقى «قيد
         * التحضير» لأنّ الضغطة الواحدة سقطت في آخرها.
         */
        return DB::transaction(function () use ($bid, $user, $requester, $from, $to, $lines, $responsible, $key, $data, $supplyRequestId, $preApproved) {
            $transfer = self::open($bid, $user, $requester, $from, $to, $lines, $responsible, $key, $data, $supplyRequestId, $preApproved);

            if (! empty($data['execute']) && $transfer->wasRecentlyCreated && $transfer->status === BranchTransfer::PENDING) {
                self::approve($transfer, $user, [], (bool) ($data['self_approve'] ?? false));
                self::prepare($transfer, $user);
                self::dispatch($transfer, $user);
            }

            return $transfer->fresh();
        });
    }

    /** يكتب السندَ وأسطرَه بحالة الطلب — أو يُعيد ما كُتب بالمفتاح نفسه */
    private static function open(int $bid, User $user, User $requester, Branch $from, Branch $to, array $lines, ?User $responsible, ?string $key, array $data, ?int $supplyRequestId, bool $preApproved): BranchTransfer
    {
        return DB::transaction(function () use ($bid, $user, $requester, $from, $to, $lines, $responsible, $key, $data, $supplyRequestId, $preApproved) {
            // رقمٌ واحد لكلّ سند — يُحسب تحت قفل النشاط فلا يتكرّر بين ضغطتين
            Business::whereKey($bid)->lockForUpdate()->first();

            if ($key !== null && ($existing = BranchTransfer::where('business_id', $bid)->where('client_key', $key)->first())) {
                return $existing;
            }

            $transfer = BranchTransfer::create([
                'business_id' => $bid,
                'number' => self::nextNumber($bid),
                'status' => $preApproved ? BranchTransfer::APPROVED : BranchTransfer::PENDING,
                'from_branch_id' => $from->id,
                'to_branch_id' => $to->id,
                'from_branch_name' => $from->name,
                'to_branch_name' => $to->name,
                'branch_supply_request_id' => $supplyRequestId,
                'notes' => self::text($data['notes'] ?? null),
                'responsible_user_id' => $responsible?->id,
                'requested_by' => $requester->id,
                'requested_by_name' => $requester->name,
                'approved_by' => $preApproved ? $user->id : null,
                'approved_by_name' => $preApproved ? $user->name : null,
                'approved_at' => $preApproved ? now() : null,
                'client_key' => $key,
            ]);

            foreach ($lines as $line) {
                $transfer->lines()->create([
                    'business_id' => $bid,
                    'product_id' => $line['product']->id,
                    'product_name' => $line['product']->name,
                    'sku' => $line['product']->sku,
                    'requested_qty' => $line['quantity'],
                    'approved_qty' => $preApproved ? $line['quantity'] : null,
                ]);
            }

            self::log($transfer, $requester, 'requested', null, BranchTransfer::PENDING, $supplyRequestId ? __('من طلب تزويد') : null);
            if ($preApproved) {
                self::log($transfer, $user, 'approved', BranchTransfer::PENDING, BranchTransfer::APPROVED, __('اعتُمد مع طلب التزويد'));
            }

            return $transfer;
        });
    }

    /**
     * اعتمادُ الطلب — والكميّةُ المعتمدة لكلّ سطرٍ لا تزيد على المطلوبة.
     *
     * والاعتمادُ لا يحرّك رفًّا: موافقةٌ على ورق. والمخزونُ يُفحص عند الإرسال،
     * فإن نقص الرفُّ بينهما رُدّ الإرسالُ لا الاعتماد.
     *
     * @param  array<int, int>  $approved  [معرّف السطر => الكميّة]
     */
    public static function approve(BranchTransfer $transfer, User $user, array $approved = [], bool $selfApprove = false): void
    {
        self::transition($transfer, $user, 'approve', function (BranchTransfer $t) use ($user, $approved, $selfApprove) {
            $self = (int) $t->requested_by === (int) $user->id;
            if ($self && ! $selfApprove) {
                throw new TransferRefused(__('أنت من طلب هذا النقل — اعتمادُه بنفسك يحتاج تأكيدًا صريحًا.'));
            }

            $total = 0;
            foreach ($t->lines as $line) {
                $qty = array_key_exists($line->id, $approved) ? (int) $approved[$line->id] : (int) $line->requested_qty;
                if ($qty < 0 || $qty > (int) $line->requested_qty) {
                    throw new TransferRefused(__('الكمية المعتمدة من «:name» بين صفر و:max.', ['name' => $line->product_name, 'max' => $line->requested_qty]));
                }
                $line->update(['approved_qty' => $qty]);
                $total += $qty;
            }

            if ($total < 1) {
                throw new TransferRefused(__('لا كميةَ معتمدة في السند — ارفضه بدل اعتماده فارغًا.'));
            }

            $t->update([
                'status' => BranchTransfer::APPROVED,
                'approved_by' => $user->id,
                'approved_by_name' => $user->name,
                'approved_at' => now(),
                'self_approved' => $self,
            ]);

            return $self ? __('اعتمده صاحب النشاط بنفسه بقرارٍ صريح') : null;
        });
    }

    public static function reject(BranchTransfer $transfer, User $user, string $reason): void
    {
        $reason = self::reason($reason);

        self::transition($transfer, $user, 'reject', function (BranchTransfer $t) use ($reason) {
            $t->update(['status' => BranchTransfer::REJECTED, 'decision_reason' => $reason, 'closed_at' => now()]);

            return $reason;
        });
    }

    public static function prepare(BranchTransfer $transfer, User $user): void
    {
        self::transition($transfer, $user, 'prepare', function (BranchTransfer $t) use ($user) {
            $t->update([
                'status' => BranchTransfer::PREPARING,
                'prepared_by' => $user->id,
                'prepared_by_name' => $user->name,
                'prepared_at' => now(),
            ]);

            return null;
        });
    }

    /**
     * تأكيدُ الإرسال — تخرج البضاعةُ من رفّ المصدر وتصير قيدَ النقل.
     *
     * والكميّةُ المرسلة لكلّ سطرٍ لا تزيد على المعتمدة (المصدرُ يُرسل أقلَّ إن
     * وجد أقلّ). وكلُّ سطرٍ يُخصم بالشرط في القاعدة نفسها: سطرٌ واحدٌ لا رصيدَ
     * له يردّ الإرسالَ كلَّه — لا نصفُ سندٍ يخرج ونصفُه يبقى.
     *
     * @param  array<int, int>  $sent  [معرّف السطر => الكميّة]
     */
    public static function dispatch(BranchTransfer $transfer, User $user, array $sent = []): void
    {
        self::transition($transfer, $user, 'dispatch', function (BranchTransfer $t) use ($user, $sent) {
            $plan = [];
            foreach ($t->lines as $line) {
                $max = $line->plannedQty();
                $qty = array_key_exists($line->id, $sent) ? (int) $sent[$line->id] : $max;
                if ($qty < 0 || $qty > $max) {
                    throw new TransferRefused(__('الكمية المرسلة من «:name» بين صفر و:max.', ['name' => $line->product_name, 'max' => $max]));
                }
                $plan[$line->id] = $qty;
            }

            if (array_sum($plan) < 1) {
                throw new TransferRefused(__('لا شيءَ يُرسَل — ألغِ السند إن لم تكن البضاعة متوفّرة.'));
            }

            $products = self::lockProducts($t);

            foreach ($t->lines as $line) {
                $qty = $plan[$line->id];
                $product = $products->get($line->product_id);

                if ($qty > 0 && ! $product) {
                    throw new TransferRefused(__('«:name» حُذف من المنتجات — لا يُرسل.', ['name' => $line->product_name]));
                }

                if ($qty > 0) {
                    BranchStock::ensureAllocated((int) $t->business_id, (int) $product->id, (int) $product->quantity);

                    if (! BranchStock::draw((int) $t->from_branch_id, (int) $product->id, $qty)) {
                        throw new TransferRefused(__('رصيد :branch من «:name» :n فقط — لا يُرسل أكثر منه.', [
                            'branch' => $t->from_branch_name,
                            'name' => $line->product_name,
                            'n' => BranchStock::bookOf((int) $t->business_id, (int) $product->id, (int) $t->from_branch_id),
                        ]));
                    }

                    $product->decrement('quantity', $qty);
                    self::movement($t, (int) $t->from_branch_id, $product, -$qty, self::MOVE_OUT, $user);
                }

                $line->update([
                    'sent_qty' => $qty,
                    'unit_cost' => $product ? round((float) $product->cost, 3) : null,
                ]);
            }

            $t->update([
                'status' => BranchTransfer::IN_TRANSIT,
                'dispatched_by' => $user->id,
                'dispatched_by_name' => $user->name,
                'dispatched_at' => now(),
            ]);

            return null;
        });
    }

    /**
     * تأكيدُ الاستلام — ما وصل يدخل رفَّ المستلم، وما نقص يبقى فرقًا معلّقًا.
     *
     * والمستلمُ لا يزيد على المرسل: بضاعةٌ لم تخرج من المصدر لا تدخل رفَّ
     * المستلم من سندٍ لا يحملها.
     *
     * @param  array<int, int>  $received  [معرّف السطر => الكميّة]
     */
    public static function receive(BranchTransfer $transfer, User $user, array $received, ?string $note = null): void
    {
        $note = self::text($note);

        self::transition($transfer, $user, 'receive', function (BranchTransfer $t) use ($user, $received, $note) {
            $products = self::lockProducts($t);
            $short = false;

            foreach ($t->lines as $line) {
                $sent = (int) $line->sent_qty;
                if (! array_key_exists($line->id, $received)) {
                    throw new TransferRefused(__('اكتب الكمية التي وصلت من «:name» — ولو صفرًا.', ['name' => $line->product_name]));
                }
                $qty = (int) $received[$line->id];
                if ($qty < 0 || $qty > $sent) {
                    throw new TransferRefused(__('الكمية المستلمة من «:name» بين صفر و:max.', ['name' => $line->product_name, 'max' => $sent]));
                }

                $product = $products->get($line->product_id);
                if ($qty > 0 && $product) {
                    self::shelve($t, (int) $t->to_branch_id, $product, $qty, self::MOVE_IN, $user);
                }

                $line->update(['received_qty' => $qty]);
                $short = $short || $qty < $sent;
            }

            $t->update([
                'status' => $short ? BranchTransfer::DISCREPANCY : BranchTransfer::RECEIVED,
                'received_by' => $user->id,
                'received_by_name' => $user->name,
                'received_at' => now(),
                'closed_at' => $short ? null : now(),
            ]);

            return $note;
        }, fn (BranchTransfer $t) => $t->status === BranchTransfer::DISCREPANCY ? 'discrepancy' : 'received');
    }

    /**
     * تسويةُ فرق الاستلام — بابٌ موثَّقٌ لكلّ قطعةٍ لم تصل.
     *
     *   فُقد أو تلف ← خسارةٌ بقيمتها يوم الإرسال في الدفترين (`StockLosses`)
     *                 على الفرع المصدر، ولا تعود إلى رفّ
     *   أُعيد        ← تعود إلى رفّ المصدر
     *   وصل متأخّرًا ← تدخل رفَّ المستلم
     *
     * ويُغلق السندُ حين لا يبقى في سطوره معلّق.
     */
    public static function resolve(BranchTransfer $transfer, User $user, int $lineId, string $kind, int $qty, string $reason): void
    {
        $reason = self::reason($reason);
        if (! in_array($kind, self::RESOLUTIONS, true)) {
            throw new TransferRefused(__('اختر كيف يُسوّى الفرق.'));
        }

        self::transition($transfer, $user, 'resolve', function (BranchTransfer $t) use ($user, $lineId, $kind, $qty, $reason) {
            $line = $t->lines->firstWhere('id', $lineId);
            if (! $line) {
                throw new TransferRefused(__('السطر ليس في هذا السند.'));
            }
            if ($qty < 1 || $qty > $line->outstanding()) {
                throw new TransferRefused(__('المعلّق من «:name» :n — لا يُسوّى أكثر منه.', ['name' => $line->product_name, 'n' => $line->outstanding()]));
            }

            $product = self::lockProducts($t)->get($line->product_id);

            if ($kind === self::RESOLVE_LOST) {
                $line->increment('lost_qty', $qty);
                $value = round((float) $line->unit_cost * $qty, 3);
                // وأمانةُ البوتيك ليست مالَ المحلّ — لا خسارةَ له فيها (`Boutiques::CONSIGNED_COST`)
                if ($product && ! $product->boutique_id) {
                    StockLosses::record(
                        (int) $t->business_id,
                        $value,
                        true,
                        __('فقد أثناء النقل :number — :name: :reason', ['number' => $t->number, 'name' => $line->product_name, 'reason' => $reason]),
                        now(),
                        $t->from_branch_id,
                        $user->id,
                        $user->name,
                        self::LOSS_TYPE,
                        $t,
                    );
                }
            } elseif ($kind === self::RESOLVE_RETURNED) {
                $line->increment('returned_qty', $qty);
                if ($product) {
                    self::shelve($t, (int) $t->from_branch_id, $product, $qty, self::MOVE_RETURN, $user);
                }
            } else {
                $line->increment('late_qty', $qty);
                if ($product) {
                    self::shelve($t, (int) $t->to_branch_id, $product, $qty, self::MOVE_LATE, $user);
                }
            }

            $open = $t->lines->sum(fn (BranchTransferLine $l) => $l->fresh()->outstanding());
            if ($open === 0) {
                $t->update(['status' => BranchTransfer::RECEIVED, 'closed_at' => now()]);
            }

            return $reason;
        }, null, fn () => ['line_id' => $lineId, 'kind' => $kind, 'quantity' => $qty]);
    }

    public static function cancel(BranchTransfer $transfer, User $user, string $reason): void
    {
        $reason = self::reason($reason);

        self::transition($transfer, $user, 'cancel', function (BranchTransfer $t) use ($reason) {
            $t->update(['status' => BranchTransfer::CANCELLED, 'decision_reason' => $reason, 'closed_at' => now()]);

            return $reason;
        });
    }

    // ═══ قيدَ النقل ═══

    /**
     * سطورُ ما خرج من مصدره ولم يُغلق — والمعلّقُ في كلٍّ منها.
     *
     * @return Collection<int, BranchTransferLine>
     */
    public static function movingLines(int $businessId): Collection
    {
        return BranchTransferLine::where('business_id', $businessId)
            ->whereHas('transfer', fn ($q) => $q->whereIn('status', BranchTransfer::MOVING))
            ->with(['transfer:id,number,status,from_branch_id,to_branch_id,from_branch_name,to_branch_name,dispatched_at', 'product:id,boutique_id'])
            ->get()
            ->filter(fn (BranchTransferLine $l) => $l->outstanding() > 0)
            ->values();
    }

    /**
     * ما قيدَ النقل — كميّتُه وقيمتُه، للنشاط كلّه ولكلّ صنف.
     *
     * والقيمةُ بتكلفة يوم الإرسال، وأمانةُ البوتيك بلا قيمة كما على الرفّ
     * (`Demo::inventory`). فقيمةُ مخزون النشاط = ما على الرفوف + هذه.
     *
     * @return array{quantity: int, value: float, transfers: int, products: array<int, int>}
     */
    public static function inTransit(int $businessId): array
    {
        $lines = self::movingLines($businessId);

        return [
            'quantity' => (int) $lines->sum(fn ($l) => $l->outstanding()),
            'value' => round((float) $lines->sum(fn ($l) => $l->product?->boutique_id ? 0 : (float) $l->unit_cost * $l->outstanding()), 3),
            'transfers' => $lines->pluck('branch_transfer_id')->unique()->count(),
            'products' => $lines->groupBy('product_id')->map(fn ($g) => (int) $g->sum(fn ($l) => $l->outstanding()))->all(),
        ];
    }

    // ═══ ما تحت المراحل ═══

    /**
     * انتقالٌ واحد: قفلٌ، ثمّ فحصٌ بعد القفل، ثمّ كتابة، ثمّ سطرُ سجلّ.
     *
     * @param  callable(BranchTransfer): ?string  $apply  يُرجع ملاحظةَ السجلّ
     * @param  ?callable(BranchTransfer): string  $action  اسمُ السطر بعد الكتابة
     * @param  ?callable(): array  $data  بياناتٌ تُحفظ مع السطر
     */
    private static function transition(BranchTransfer $transfer, User $user, string $step, callable $apply, ?callable $action = null, ?callable $data = null): void
    {
        DB::transaction(function () use ($transfer, $user, $step, $apply, $action, $data) {
            $locked = BranchTransfer::whereKey($transfer->id)
                ->where('business_id', $user->business_id)
                ->lockForUpdate()->first();

            if (! $locked) {
                throw new TransferRefused(__('هذا السند ليس من نشاطك.'));
            }

            self::authorize($user, $step, $locked);
            $locked->load('lines');

            $before = $locked->status;
            $note = $apply($locked);
            $locked->refresh();

            $name = $action ? $action($locked) : match ($step) {
                'approve' => 'approved',
                'reject' => 'rejected',
                'prepare' => 'preparing',
                'dispatch' => 'dispatched',
                'resolve' => 'resolved',
                'cancel' => 'cancelled',
                default => $step,
            };

            self::log($locked, $user, $name, $before, $locked->status, $note, $data ? $data() : null);
        });

        $transfer->refresh();
    }

    /** يُدخل كميّةً رفَّ فرعٍ والإجماليَّ معًا، ويُقيّد الحركة */
    private static function shelve(BranchTransfer $t, int $branchId, Product $product, int $qty, string $type, User $user): void
    {
        BranchStock::ensureAllocated((int) $t->business_id, (int) $product->id, (int) $product->quantity);
        BranchStock::adjust((int) $t->business_id, $branchId, (int) $product->id, $qty);
        $product->increment('quantity', $qty);
        self::movement($t, $branchId, $product, $qty, $type, $user);
    }

    private static function movement(BranchTransfer $t, int $branchId, Product $product, int $delta, string $type, User $user): void
    {
        InventoryMovement::create([
            'business_id' => $t->business_id,
            'branch_id' => $branchId,
            'product_id' => $product->id,
            'product_name' => $product->name,
            'sku' => $product->sku,
            'type' => $type,
            'reference' => $t->number,
            'quantity' => ($delta > 0 ? '+' : '').$delta,
            'employee_name' => $user->name,
            'note' => $t->from_branch_name.' ← '.$t->to_branch_name,
        ]);
    }

    /**
     * أصنافُ السند مقفلةً بترتيب المعرّف — كما في `StockLedger::move`.
     *
     * قفلُ الصفوف بترتيبٍ مختلف بين عمليتين متزامنتين يُنتج تعارضًا دائريًّا.
     */
    private static function lockProducts(BranchTransfer $t): Collection
    {
        $ids = $t->lines->pluck('product_id')->filter()->unique()->sort()->values()->all();

        return Product::where('business_id', $t->business_id)->whereIn('id', $ids)
            ->orderBy('id')->lockForUpdate()->get()->keyBy('id');
    }

    /** @return array{0: Branch, 1: Branch} */
    private static function branches(int $bid, int $fromId, int $toId): array
    {
        if ($fromId === $toId) {
            throw new TransferRefused(__('الفرع المصدر والفرع المستلم واحد — اختر فرعين مختلفين.'));
        }

        $branches = Branch::where('business_id', $bid)->whereIn('id', [$fromId, $toId])->get()->keyBy('id');
        if (! $branches->has($fromId) || ! $branches->has($toId)) {
            throw new TransferRefused(__('أحد الفرعين ليس من نشاطك.'));
        }

        return [$branches[$fromId], $branches[$toId]];
    }

    /**
     * أسطرُ السند — أصنافٌ من النشاط على رفّ، والصنفُ المكرّر يُجمع.
     *
     * والخدمةُ والصنفُ بوصفةٍ لا يُنقلان: الأولى لا رفَّ لها، والثاني تُعدّ
     * مكوّناتُه لا هو.
     *
     * @return list<array{product: Product, quantity: int}>
     */
    public static function lines(int $bid, array $raw): array
    {
        $wanted = [];
        foreach ($raw as $row) {
            $pid = (int) ($row['product_id'] ?? 0);
            $qty = (int) ($row['quantity'] ?? 0);
            if ($pid < 1 || $qty < 1) {
                throw new TransferRefused(__('لكلّ سطرٍ صنفٌ وكميةٌ موجبة.'));
            }
            $wanted[$pid] = ($wanted[$pid] ?? 0) + $qty;
        }

        if (! $wanted) {
            throw new TransferRefused(__('أضف صنفًا واحدًا على الأقل.'));
        }

        $products = Product::where('business_id', $bid)->whereIn('id', array_keys($wanted))
            ->withCount('recipeItems')->get()->keyBy('id');

        $out = [];
        foreach ($wanted as $pid => $qty) {
            $product = $products->get($pid);
            if (! $product) {
                throw new TransferRefused(__('أحد الأصناف ليس من نشاطك.'));
            }
            if (! $product->tracksStock()) {
                throw new TransferRefused(__('«:name» غير مرتبط بالمخزون — لا رفَّ له ليُنقل.', ['name' => $product->name]));
            }
            if ($product->recipe_items_count > 0) {
                throw new TransferRefused(__('«:name» يُصنع من وصفة — انقل مكوّناته.', ['name' => $product->name]));
            }
            $out[] = ['product' => $product, 'quantity' => $qty];
        }

        return $out;
    }

    private static function responsible(int $bid, $id): ?User
    {
        if (! $id) {
            return null;
        }

        $user = User::where('business_id', $bid)->whereKey((int) $id)->first();
        if (! $user) {
            throw new TransferRefused(__('الموظف المسؤول ليس من نشاطك.'));
        }

        return $user;
    }

    /**
     * رقمُ السند — يتّصل بأرقام سِجلّ النقل القديم ولا يكرّرها.
     *
     * حركاتُ المخزون القديمة تحمل `TRF-…` في `reference`، والجديدةُ كذلك. فلو
     * بدأ العدُّ من جديد لَحمل سندان رقمًا واحدًا في «سجل المخزون».
     */
    public static function nextNumber(int $businessId): string
    {
        $n = max(
            self::tail(BranchTransfer::where('business_id', $businessId)->orderByDesc('id')->value('number')),
            self::tail(StockTransfer::where('business_id', $businessId)->orderByDesc('id')->value('number')),
        ) + 1;

        return 'TRF-'.str_pad((string) $n, 6, '0', STR_PAD_LEFT);
    }

    private static function tail(?string $number): int
    {
        return $number ? (int) substr($number, 4) : 0;
    }

    public static function log(BranchTransfer $t, ?User $user, string $action, ?string $from, ?string $to, ?string $note = null, ?array $data = null): void
    {
        BranchOperationEvent::create([
            'business_id' => $t->business_id,
            'subject_type' => BranchOperationEvent::TRANSFER,
            'subject_id' => $t->id,
            'action' => $action,
            'from_status' => $from,
            'to_status' => $to,
            'user_id' => $user?->id,
            'user_name' => $user?->name,
            'note' => $note,
            'data' => $data,
            'created_at' => now(),
        ]);
    }

    private static function reason(string $reason): string
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 3) {
            throw new TransferRefused(__('اكتب السبب — ثلاثة أحرف على الأقل.'));
        }

        return mb_substr($reason, 0, 500);
    }

    private static function text(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : mb_substr($value, 0, 1000);
    }

    private static function clientKey(?string $key): ?string
    {
        $key = trim((string) $key);

        return $key === '' ? null : mb_substr($key, 0, 64);
    }
}
