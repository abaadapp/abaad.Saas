<?php

namespace App\Support\Addons;

use App\Models\AddonOrder;
use App\Models\Business;
use App\Models\BusinessAddon;
use App\Models\Invoice;
use App\Models\SubscriptionAddon;
use App\Models\User;
use App\Support\Activity;
use App\Support\Billing;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * شراءُ الإضافة وتجديدُها وفوترتُها — من الطلب إلى الاستحقاق.
 *
 * ═══ الطريق ═══
 *
 *   طلبٌ (التاجرُ من «إضافات متجرك») ← اعتمادٌ (صاحبُ المنصّة: يُصدر الفاتورة،
 *   ويُسعِّر ما كان «حسب الطلب») ← سدادٌ (`Billing::markPaid`) ← استحقاقٌ ساري.
 *
 * وهو طريقُ حزم رسائل واتساب نفسُه (`WhatsAppPacks`): لا تحصيلَ إلكترونيّ لفواتير
 * المنصّة، والسدادُ تحويلٌ يُراجعه صاحبُ المنصّة ويسجّله.
 *
 * ═══ والسعرُ يُثبَّت ═══
 *
 * يُنسخ سعرُ الوحدة في الطلب ساعةَ يُطلب، وفي الاستحقاق ساعةَ يُفعَّل، ويُجدَّد
 * به. فتعديلُ الكتالوج غدًا لا يُعيد تسعيرَ طلبٍ ولا فاتورةٍ صدرت ولا دورةٍ
 * اتُّفق عليها.
 *
 * ═══ ودورةُ الإضافة مستقلّة ═══
 *
 * تبدأ يومَ سدادها، شهرًا أو سنةً، ولا تُربط بدورة الباقة ولا تُجزَّأ بأيّامٍ
 * متبقّية: لا سياسةَ تقريبٍ ماليٍّ لم يقرّرها المالك. والتجديدُ يبدأ من نهاية
 * الدورة إن لم تمضِ، وإلّا من يوم السداد — كتجديد الباقة (`Billing::start`).
 *
 * ═══ ولا يتكرّر ═══
 *
 * طلبٌ مفتوحٌ واحدٌ للإضافة الواحدة في المتجر، وتجديدٌ مفتوحٌ واحدٌ للاستحقاق:
 * مفتاحٌ فريدٌ في القاعدة (`open_key`) لا فحصٌ يقرأ ثمّ يكتب. والتفعيلُ تحت قفل
 * الفاتورة في `markPaid`: سدادٌ مكرَّرٌ لا يمدّ مرّتين.
 */
final class AddonOrders
{
    public const REQUESTED = 'requested';

    public const INVOICED = 'invoiced';

    public const PAID = 'paid';

    public const CANCELLED = 'cancelled';

    public const OPEN = [self::REQUESTED, self::INVOICED];

    public const KIND_NEW = 'new';

    public const KIND_RENEWAL = 'renewal';

    /** موضعُ الإضافة في باقة المتجر: `eligible` أو `included` أو `null` */
    public static function modeFor(Business $business, SubscriptionAddon $addon): ?string
    {
        if (! $business->plan_id) {
            return null;
        }

        return DB::table('subscription_addon_plans')->where('subscription_addon_id', $addon->id)
            ->where('plan_id', $business->plan_id)->value('mode');
    }

    /** الاستحقاقُ الساري لهذه الإضافة في المتجر — إن كان */
    public static function activeFor(Business $business, SubscriptionAddon $addon): ?BusinessAddon
    {
        return BusinessAddon::where('business_id', $business->id)->where('subscription_addon_id', $addon->id)
            ->active()->orderByDesc('ends_at')->first();
    }

    /**
     * يطلب التاجرُ إضافة — ولا يُنشئ ثانيًا وله طلبٌ مفتوحٌ لها.
     *
     * والخادمُ يقرّر كلَّ شيء: السياسةُ والباقةُ والسعرُ والكمّيّةُ تُقرأ من
     * القاعدة. ما يصل من الطلب رمزُ الإضافة ودورتُها وعددُها، فلا يُفتح بتعديل
     * طلبٍ شيءٌ لم يُبَع.
     *
     * @return array{order: AddonOrder, created: bool}
     */
    public static function request(Business $business, SubscriptionAddon $addon, string $cycle, int $quantity = 1, ?User $by = null): array
    {
        if (! PricingPolicy::isAddons($business)) {
            throw new DomainException(__('متجرك على سياسة الاشتراك السابقة — ميزاتُه كما هي، والإضافاتُ للمتاجر الجديدة. تواصل مع أبعاد لأيّ تعديل.'));
        }
        if (! $addon->for_sale || ! $addon->for_new_merchants) {
            throw new DomainException(__('هذه الإضافة لا تُباع الآن.'));
        }

        $mode = self::modeFor($business, $addon);
        if ($mode === Catalog::INCLUDED) {
            throw new DomainException(__('«:n» مشمولةٌ في باقتك — لا تُشترى مرّتين.', ['n' => $addon->name]));
        }
        if ($mode !== Catalog::ELIGIBLE) {
            throw new DomainException(__('«:n» لا تُباع في باقتك.', ['n' => $addon->name]));
        }
        if (! in_array($cycle, Catalog::CYCLES, true)) {
            throw new DomainException(__('اختر دورةً شهريّةً أو سنويّة.'));
        }

        $quantity = Catalog::counted($addon->unit) ? $quantity : 1;
        if ($quantity < 1 || $quantity > Catalog::MAX_QUANTITY) {
            throw new DomainException(__('العدد بين 1 و:max.', ['max' => Catalog::MAX_QUANTITY]));
        }

        if (! Catalog::counted($addon->unit) && ($active = self::activeFor($business, $addon))) {
            throw new DomainException(__('«:n» مفعّلةٌ لمتجرك:until.', [
                'n' => $addon->name,
                'until' => $active->ends_at ? ' '.__('حتى :d', ['d' => $active->ends_at->format('Y-m-d')]) : '',
            ]));
        }

        $unit = match ($addon->pricing) {
            Catalog::QUOTE => null,
            Catalog::FREE => 0.0,
            default => (float) ($cycle === 'yearly' ? $addon->yearly_price : $addon->monthly_price),
        };

        if ($addon->pricing === Catalog::PAID && ! ($unit > 0)) {
            throw new DomainException(__('«:n» لم تُسعَّر بعد — تواصل مع أبعاد.', ['n' => $addon->name]));
        }

        $key = 'new:'.$business->id.':'.$addon->id;

        try {
            return DB::transaction(function () use ($business, $addon, $cycle, $quantity, $unit, $key, $by) {
                Business::whereKey($business->id)->lockForUpdate()->first();

                if ($open = AddonOrder::where('open_key', $key)->first()) {
                    return ['order' => $open, 'created' => false];
                }

                $order = AddonOrder::create([
                    'business_id' => $business->id,
                    'subscription_addon_id' => $addon->id,
                    'kind' => self::KIND_NEW,
                    'cycle' => $cycle,
                    'quantity' => $quantity,
                    'unit_price' => $unit,
                    'amount' => $unit === null ? null : round($unit * $quantity, 3),
                    'status' => self::REQUESTED,
                    'open_key' => $key,
                    'requested_by' => $by?->id,
                    'requested_by_name' => $by?->name,
                ]);

                Activity::log('created', 'طلب إضافة الاشتراك «'.$addon->name.'»'.($quantity > 1 ? ' × '.$quantity : '').' — '.($cycle === 'yearly' ? 'سنويًّا' : 'شهريًّا'), [
                    'business_id' => $business->id, 'subject_id' => $order->id, 'subject_type' => 'addon_order',
                ]);

                // والمجّانيّةُ لا فاتورةَ لها ولا اعتماد: تُفعَّل بطلبها
                if ($addon->pricing === Catalog::FREE) {
                    self::activate($order);
                }

                return ['order' => $order->fresh(), 'created' => true];
            });
        } catch (QueryException $e) {
            // طلبٌ آخرُ في اللحظة نفسها سبق إلى المفتاح الفريد — هو الطلب
            if ($open = AddonOrder::where('open_key', $key)->first()) {
                return ['order' => $open, 'created' => false];
            }

            throw $e;
        }
    }

    /**
     * يعتمد صاحبُ المنصّة الطلبَ فتُصدر فاتورتُه — ولا استحقاقَ قبل سدادها.
     *
     * «حسب الطلب» يُسعَّر هنا وحده؛ والمدفوعةُ بسعرها المثبَّت، إلّا خصمًا يُكتب
     * سببُه. وما بلغ صفرًا (منحةٌ بالاعتماد) يُفعَّل بلا فاتورة.
     */
    public static function approve(AddonOrder $order, ?float $unitPrice = null, ?string $note = null): AddonOrder
    {
        return DB::transaction(function () use ($order, $unitPrice, $note) {
            $order = AddonOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();

            if ($order->status !== self::REQUESTED) {
                throw new DomainException(__('هذا الطلب ليس بانتظار الاعتماد.'));
            }

            $unit = $order->unit_price === null ? null : (float) $order->unit_price;

            if ($unitPrice !== null && ($unit === null || abs($unitPrice - $unit) > 0.0005)) {
                if ($unitPrice < 0) {
                    throw new DomainException(__('السعر لا يكون سالبًا.'));
                }
                if ($unit !== null && mb_strlen(trim((string) $note)) < 5) {
                    throw new DomainException(__('اكتب سبب تغيير السعر المثبَّت.'));
                }
                $unit = round($unitPrice, 3);
            }

            if ($unit === null) {
                throw new DomainException(__('«حسب الطلب» تحتاج سعرًا معتمدًا قبل إصدار فاتورتها.'));
            }

            $amount = round($unit * (int) $order->quantity, 3);
            $order->update([
                'unit_price' => $unit,
                'amount' => $amount,
                'note' => filled($note) ? mb_substr(trim((string) $note), 0, 500) : $order->note,
            ]);

            if ($amount <= 0) {
                self::activate($order);
            } else {
                $invoice = self::invoice($order, $amount);
                $order->update(['status' => self::INVOICED, 'invoice_id' => $invoice->id]);
            }

            Activity::log('updated', 'اعتمد طلب الإضافة «'.$order->addon->name.'» بمبلغ '.number_format($amount, 3), [
                'business_id' => null, 'subject_id' => $order->business_id, 'subject_type' => 'business',
            ]);

            return $order->fresh();
        });
    }

    /**
     * إلغاءُ طلبٍ لم يُسدَّد — وفاتورتُه المصدَّرة تُلغى معه وتبقى في دفترها.
     */
    public static function cancel(AddonOrder $order, ?string $why = null): AddonOrder
    {
        return DB::transaction(function () use ($order, $why) {
            $order = AddonOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();

            if (! in_array($order->status, self::OPEN, true)) {
                throw new DomainException(__('لا يُلغى إلّا طلبٌ لم يُسدَّد.'));
            }

            if ($order->invoice_id) {
                $invoice = Invoice::whereKey($order->invoice_id)->lockForUpdate()->first();
                if ($invoice && $invoice->status === Billing::PAID) {
                    throw new DomainException(__('سُدّدت فاتورة هذا الطلب — لا يُلغى.'));
                }
                $invoice?->update(['status' => Billing::CANCELLED]);
            }

            $order->update([
                'status' => self::CANCELLED,
                'open_key' => null,
                'decided_at' => now(),
                'note' => filled($why) ? mb_substr(trim((string) $why), 0, 500) : $order->note,
            ]);

            Activity::log('deleted', 'أُلغي طلب الإضافة «'.$order->addon->name.'»', [
                'business_id' => null, 'subject_id' => $order->business_id, 'subject_type' => 'business',
            ]);

            return $order->fresh();
        });
    }

    /**
     * سُجّل سدادُ فاتورة — فإن كانت فاتورةَ إضافةٍ فُعِّل استحقاقُها.
     *
     * تُنادى من `Billing::markPaid` وحدَها وداخل معاملتها وقفلِ فاتورتها: تقييدُ
     * السداد والتفعيلُ يقعان معًا أو لا يقع أحدُهما. والحارسُ `INVOICED`: نداءٌ
     * ثانٍ لا يمدّ الاستحقاقَ مرّتين.
     */
    public static function settle(Invoice $invoice): void
    {
        $order = AddonOrder::where('invoice_id', $invoice->id)->where('status', self::INVOICED)->lockForUpdate()->first();

        if (! $order) {
            return;
        }

        if ((int) $order->business_id !== (int) $invoice->business_id) {
            throw new DomainException(__('طلب هذه الفاتورة لمتجرٍ آخر، فلم يُسجَّل السداد.'));
        }

        self::activate($order);
    }

    /**
     * يُصدر صاحبُ المنصّة فاتورةَ تجديد استحقاقٍ — بسعره المتّفق وكمّيّته ودورته.
     *
     * ولا يُجدَّد ما أوقف التاجرُ تجديدَه، ولا ما أُلغي، ولا منحةٌ بلا سعر (تُمدّ
     * المنحةُ بمنحة).
     */
    public static function renew(BusinessAddon $entitlement): AddonOrder
    {
        if ($entitlement->cancelled_at !== null) {
            throw new DomainException(__('أُلغيت هذه الإضافة — لا تُجدَّد.'));
        }
        if ($entitlement->source !== BusinessAddon::PURCHASE || ! $entitlement->cycle) {
            throw new DomainException(__('المنحة لا تُجدَّد بفاتورة — امنحها ثانيةً بمدّتها.'));
        }
        if (! $entitlement->renews) {
            throw new DomainException(__('أوقف التاجر تجديد هذه الإضافة.'));
        }

        $key = 'renew:'.$entitlement->id;

        try {
            return DB::transaction(function () use ($entitlement, $key) {
                Business::whereKey($entitlement->business_id)->lockForUpdate()->first();

                if (AddonOrder::where('open_key', $key)->exists()) {
                    throw new DomainException(__('لهذه الإضافة فاتورةُ تجديدٍ بانتظار السداد.'));
                }

                $amount = round((float) $entitlement->unit_price * (int) $entitlement->quantity, 3);
                $order = AddonOrder::create([
                    'business_id' => $entitlement->business_id,
                    'subscription_addon_id' => $entitlement->subscription_addon_id,
                    'business_addon_id' => $entitlement->id,
                    'kind' => self::KIND_RENEWAL,
                    'cycle' => $entitlement->cycle,
                    'quantity' => $entitlement->quantity,
                    'unit_price' => $entitlement->unit_price,
                    'amount' => $amount,
                    'status' => self::REQUESTED,
                    'open_key' => $key,
                ]);

                if ($amount <= 0) {
                    self::activate($order);
                } else {
                    $invoice = self::invoice($order, $amount);
                    $order->update(['status' => self::INVOICED, 'invoice_id' => $invoice->id]);
                }

                Activity::log('created', 'أصدر فاتورة تجديد الإضافة «'.$entitlement->addon->name.'»', [
                    'business_id' => null, 'subject_id' => $entitlement->business_id, 'subject_type' => 'business',
                ]);

                return $order->fresh();
            });
        } catch (QueryException $e) {
            if (AddonOrder::where('open_key', $key)->exists()) {
                throw new DomainException(__('لهذه الإضافة فاتورةُ تجديدٍ بانتظار السداد.'));
            }

            throw $e;
        }
    }

    /** إيقافُ التجديد أو إعادتُه — والاستحقاقُ ساريًا حتى نهاية دورته في الحالين */
    public static function setRenews(BusinessAddon $entitlement, bool $renews): BusinessAddon
    {
        if ($entitlement->cancelled_at !== null) {
            throw new DomainException(__('أُلغيت هذه الإضافة.'));
        }

        $entitlement->update(['renews' => $renews]);

        Activity::log('updated', ($renews ? 'أعاد تجديد' : 'أوقف تجديد').' الإضافة «'.$entitlement->addon->name.'» — سارية حتى '.($entitlement->ends_at?->format('Y-m-d') ?? '—'), [
            'business_id' => $entitlement->business_id, 'subject_id' => $entitlement->id, 'subject_type' => 'business_addon',
        ]);

        return $entitlement->fresh();
    }

    /**
     * منحةٌ يدويّة أو عرضٌ خاصّ — بسببٍ مكتوب، ومدّةٍ أو بلا نهاية.
     */
    public static function grant(Business $business, SubscriptionAddon $addon, int $quantity, ?string $endsAt, string $reason, ?User $by = null): BusinessAddon
    {
        if (mb_strlen(trim($reason)) < 5) {
            throw new DomainException(__('اكتب سبب المنحة.'));
        }

        $quantity = Catalog::counted($addon->unit) ? $quantity : 1;
        if ($quantity < 1 || $quantity > Catalog::MAX_QUANTITY) {
            throw new DomainException(__('العدد بين 1 و:max.', ['max' => Catalog::MAX_QUANTITY]));
        }

        $ends = filled($endsAt) ? Carbon::parse($endsAt)->startOfDay() : null;
        if ($ends && $ends->lt(now()->startOfDay())) {
            throw new DomainException(__('نهاية المنحة لا تكون قبل اليوم.'));
        }

        if (! Catalog::counted($addon->unit) && self::activeFor($business, $addon)) {
            throw new DomainException(__('«:n» مفعّلةٌ لهذا المتجر — أنهِها أوّلًا أو انتظر انقضاءها.', ['n' => $addon->name]));
        }

        $row = BusinessAddon::create([
            'business_id' => $business->id,
            'subscription_addon_id' => $addon->id,
            'quantity' => $quantity,
            'source' => BusinessAddon::GRANT,
            'cycle' => null,
            'unit_price' => 0,
            'starts_at' => now()->toDateString(),
            'ends_at' => $ends?->toDateString(),
            'renews' => false,
            'reason' => mb_substr(trim($reason), 0, 255),
            'created_by' => $by?->id,
        ]);
        Entitlements::flush();

        Activity::log('created', 'منح «'.$business->name.'» إضافة «'.$addon->name.'»'.($ends ? ' حتى '.$ends->format('Y-m-d') : ' بلا نهاية').' — '.mb_substr(trim($reason), 0, 120), [
            'business_id' => null, 'subject_id' => $business->id, 'subject_type' => 'business',
        ]);

        return $row;
    }

    /**
     * إنهاءُ استحقاقٍ الآن — بسبب. لا يُحذف صفٌّ ولا يُمحى ممّا بناه المتجر شيء.
     */
    public static function end(BusinessAddon $entitlement, string $reason): BusinessAddon
    {
        if (mb_strlen(trim($reason)) < 5) {
            throw new DomainException(__('اكتب سبب الإنهاء.'));
        }
        if ($entitlement->cancelled_at !== null) {
            return $entitlement;
        }

        $entitlement->update(['cancelled_at' => now(), 'renews' => false, 'reason' => mb_substr(trim($reason), 0, 255)]);
        Entitlements::flush();

        Activity::log('deleted', 'أنهى إضافة «'.$entitlement->addon->name.'» — '.mb_substr(trim($reason), 0, 120), [
            'business_id' => null, 'subject_id' => $entitlement->business_id, 'subject_type' => 'business',
        ]);

        return $entitlement->fresh();
    }

    /** يفعّل الطلبَ المحسوم — تحت قفله ومعاملته */
    private static function activate(AddonOrder $order): BusinessAddon
    {
        $today = now()->startOfDay();

        if ($order->kind === self::KIND_RENEWAL && $order->business_addon_id) {
            $row = BusinessAddon::whereKey($order->business_addon_id)->lockForUpdate()->firstOrFail();

            if ($row->cancelled_at !== null) {
                throw new DomainException(__('أُلغيت الإضافة قبل سداد تجديدها — لا تُمدّ.'));
            }

            $from = ($row->ends_at && $row->ends_at->isFuture()) ? $row->ends_at->copy() : $today;
            $row->update([
                'ends_at' => Billing::extend($from, $order->cycle)->toDateString(),
                'unit_price' => $order->unit_price,
            ]);
        } else {
            $addon = SubscriptionAddon::findOrFail($order->subscription_addon_id);
            $free = $addon->pricing === Catalog::FREE;

            $row = BusinessAddon::create([
                'business_id' => $order->business_id,
                'subscription_addon_id' => $order->subscription_addon_id,
                'quantity' => $order->quantity,
                'source' => BusinessAddon::PURCHASE,
                'cycle' => $free ? null : $order->cycle,
                'unit_price' => (float) ($order->unit_price ?? 0),
                'starts_at' => $today->toDateString(),
                // والمجّانيّةُ بلا نهاية — لا دورةَ تُفوتَر
                'ends_at' => $free ? null : Billing::extend($today, $order->cycle)->toDateString(),
                'renews' => ! $free,
                'created_by' => $order->requested_by,
            ]);
        }

        $order->update([
            'status' => self::PAID,
            'open_key' => null,
            'decided_at' => now(),
            'business_addon_id' => $row->id,
        ]);
        Entitlements::flush();

        Activity::log('updated', 'فُعّلت إضافة «'.$order->addon->name.'»'.($row->ends_at ? ' حتى '.$row->ends_at->format('Y-m-d') : ''), [
            'business_id' => null, 'subject_id' => $order->business_id, 'subject_type' => 'business',
        ]);

        return $row;
    }

    /** فاتورةُ الإضافة — نوعُها «إضافة»، بلا باقة، وسطرُها يقول ما بيع */
    private static function invoice(AddonOrder $order, float $amount): Invoice
    {
        $addon = $order->addon;

        return Invoice::create([
            'number' => Billing::nextNumber(),
            'business_id' => $order->business_id,
            'plan_id' => null,
            'amount' => $amount,
            'issued_at' => now(),
            'status' => Billing::UNPAID,
            'kind' => Billing::KIND_ADDON,
            'note' => mb_substr($addon->name.((int) $order->quantity > 1 ? ' × '.$order->quantity : '')
                .' — '.($order->cycle === 'yearly' ? 'سنويًّا' : 'شهريًّا')
                .($order->kind === self::KIND_RENEWAL ? ' (تجديد)' : ''), 0, 160),
        ]);
    }
}
