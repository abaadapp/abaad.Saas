<?php

namespace App\Support;

use App\Models\Business;
use App\Models\Customer;
use App\Models\CustomerInvoice;
use App\Models\WhatsAppOrderSession;
use App\Support\Store\DeliverySchedule;
use App\Support\Store\GiftCardProduct;
use App\Support\Store\ShopClock;
use App\Support\Store\StoreBranches;
use App\Support\Store\WebCheckout;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

/**
 * ما يُكتب حين يوافق الزبون — بالمسارات القائمة وحدها، لا بنسخةٍ منها.
 *
 * - السعرُ: `WebCheckout::quote` — قاعدةُ الموقع نفسُها (منشورٌ، على رفّه شيء،
 *   إضافاتُه مفتوحةٌ للموقع، ورسمُ التوصيل). ثمّ المبلغُ من حساب فاتورة العميل
 *   نفسِه (`CustomerInvoices::compute`)، فما يُعرض في الملخّص هو ما تطلبه الفاتورة.
 * - الفاتورة: `InvoiceLineDetails::expand` ← `CustomerInvoices::create` ←
 *   `CustomerInvoicePreparations::save` ← `CustomerInvoices::issue` — في معاملةٍ
 *   واحدة كما يكتبها الموظّف من شاشتها (`CustomerInvoiceController::store`).
 * - الرابط: `PaymentLinks::forInvoice` بعد المعاملة لا داخلها.
 *
 * ═══ ومرّةً واحدة ═══
 *
 * الفاتورةُ تحمل رمزَ الطلب (`form_token`) وفهرسُه فريدٌ في المتجر: إعادةٌ أو
 * مهمّتان معًا لا تكتبان ورقتين. والرابطُ يعود هو نفسُه إن طُلب ثانيةً
 * (`forInvoice`). والتجهيزُ مهمّةٌ واحدةٌ للفاتورة (فهرسٌ فريد). فلا يُطلب مالٌ
 * مرّتين عن طلبٍ واحد.
 */
class WhatsAppOrderCheckout
{
    public const FORM_TOKEN = 'whatsapp-order:';

    /**
     * سلّةُ المحادثة بصيغة إتمام الموقع.
     *
     * @param  list<array<string, mixed>>  $cart
     * @param  array<string, mixed>  $details
     * @return array<string, mixed>
     */
    public static function payload(array $cart, array $details): array
    {
        return [
            'items' => array_map(fn ($l) => [
                'id' => (int) $l['product_id'],
                'variant_id' => $l['variant_id'] ?? null,
                'qty' => (int) $l['qty'],
                'note' => $l['note'] ?? null,
                'addons' => array_map(fn ($a) => ['id' => (int) $a['id'], 'qty' => (int) $a['qty']], array_values((array) ($l['addons'] ?? []))),
            ], array_values($cart)),
            'fulfil' => $details['fulfil'] ?? null,
            StoreBranches::FIELD => $details['branch_id'] ?? null,
        ];
    }

    /** تسعيرُ الموقع للسلّة — يرمي ما يرميه هناك (نفد، أُوقف، إضافةٌ مغلقة) */
    public static function quote(Business $business, array $cart, array $details): array
    {
        return WebCheckout::quote($business, self::payload($cart, $details));
    }

    /**
     * بنودُ الفاتورة ومبلغُها — قبل أن يُكتب شيء.
     *
     * @return array{quote: array<string, mixed>, items: list<array<string, mixed>>, totals: array<string, mixed>}
     */
    public static function preview(Business $business, WhatsAppOrderSession $session): array
    {
        $bid = (int) $business->id;
        $quote = self::quote($business, (array) $session->cart, (array) $session->details);
        $items = [];

        foreach ($quote['_lines'] as $line) {
            $product = $line['product'] ?? null;

            if ($product === null) {
                throw new RuntimeException(__('بندٌ لا صنفَ له في السلّة.'));
            }

            $variant = $line['variant'] ?? null;

            $items[] = [
                'product_id' => (int) $product->id,
                'variant_id' => $variant?->id,
                'description' => (string) $product->name.($variant ? ' — '.$variant->name : ''),
                'quantity' => (int) $line['qty'],
                'unit_price' => (float) $line['price'],
                // ضريبةُ الصنف نفسُها التي تقرؤها نقطةُ البيع لحظةَ البيع
                'tax_rate' => Vat::rateFor($product, $bid),
                'note' => $line['note'] ?? null,
                'addons' => array_map(fn ($a) => [
                    'addon_id' => (int) $a['addon']->id,
                    'quantity' => (int) $a['qty'],
                ], array_values($line['addons'] ?? [])),
            ];
        }

        /*
         * ورسمُ التوصيل سطرٌ بلا صنف — بلا ضريبة كما في إتمام الموقع.
         *
         * فاتورةُ العميل لا تعرف رسمًا في رأسها (ذاك `fromOrder` وحده)، فيُكتب
         * سطرًا يقرؤه الزبونُ في ورقته ويدخل مبلغَها.
         */
        if ((float) $quote['delivery'] > 0) {
            $items[] = [
                'description' => __('رسوم التوصيل'),
                'quantity' => 1,
                'unit_price' => (float) $quote['delivery'],
                'tax_rate' => 0.0,
            ];
        }

        $expanded = InvoiceLineDetails::expand($bid, $items);

        return ['quote' => $quote, 'items' => $expanded, 'totals' => CustomerInvoices::compute($bid, $expanded)];
    }

    /* ═══════════ الكتابة ═══════════ */

    /**
     * الفاتورةُ بتجهيزها، صادرةً — مرّةً واحدةً للطلب.
     *
     * والمبلغُ يُقارَن بما وافق عليه الزبون قبل أن يُكتب شيء، ثمّ بعد الإصدار
     * داخل المعاملة نفسِها: ورقةٌ بغير ما وافق عليه لا تُكتب أبدًا.
     *
     * @throws WhatsAppOrderPriceChanged حين تغيّر المبلغُ منذ الملخّص
     * @throws ValidationException|RuntimeException
     */
    public static function invoice(Business $business, WhatsAppOrderSession $session): CustomerInvoice
    {
        $bid = (int) $business->id;

        if ($session->customer_invoice_id !== null) {
            return CustomerInvoice::where('business_id', $bid)->findOrFail($session->customer_invoice_id);
        }

        $preview = self::preview($business, $session);

        if (abs((float) $preview['totals']['total'] - (float) $session->total) > 0.0005) {
            throw new WhatsAppOrderPriceChanged($preview);
        }

        $preparation = self::preparation($business, $session, $preview);

        try {
            return DB::transaction(function () use ($business, $session, $preview, $preparation, $bid) {
                $locked = WhatsAppOrderSession::whereKey($session->id)->lockForUpdate()->firstOrFail();

                if ($locked->customer_invoice_id !== null) {
                    return CustomerInvoice::where('business_id', $bid)->findOrFail($locked->customer_invoice_id);
                }

                $customer = self::customer($business, $locked);
                CustomerFlags::assertSellable($customer, CustomerFlags::settings($bid), null, null, 'customer_id');

                $invoice = CustomerInvoices::create($bid, $customer, [
                    'branch_id' => $preparation['branch_id'],
                    'notes' => __('طلب عبر واتساب'),
                ], $preview['items']);

                $invoice->forceFill(['form_token' => self::FORM_TOKEN.$locked->id])->save();

                CustomerInvoicePreparations::save($invoice, $preparation);

                $invoice = CustomerInvoices::issue($invoice);

                if (abs((float) $invoice->total - (float) $locked->total) > 0.0005) {
                    throw new RuntimeException(__('مبلغ الفاتورة لا يطابق ما وافق عليه العميل.'));
                }

                $locked->forceFill(['customer_invoice_id' => $invoice->id, 'customer_id' => $customer->id])->save();
                $session->forceFill(['customer_invoice_id' => $invoice->id, 'customer_id' => $customer->id]);

                return $invoice;
            });
        } catch (QueryException $e) {
            // ورقةٌ كُتبت بالرمز نفسه في اللحظة نفسها — فهي هي، لا ورقةٌ ثانية
            $twin = CustomerInvoice::where('business_id', $bid)->where('form_token', self::FORM_TOKEN.$session->id)->first();

            if ($twin === null) {
                throw $e;
            }

            WhatsAppOrderSession::whereKey($session->id)->update(['customer_invoice_id' => $twin->id]);
            $session->forceFill(['customer_invoice_id' => $twin->id]);

            return $twin;
        }
    }

    /**
     * قسمُ «التجهيز والتسليم» كما تقبله الفاتورة — ويُتحقَّق منه بقواعدها هي.
     *
     * @param  array{quote: array<string, mixed>}  $preview
     * @return array<string, mixed>
     */
    public static function preparation(Business $business, WhatsAppOrderSession $session, array $preview): array
    {
        $bid = (int) $business->id;
        $d = (array) $session->details;
        $fulfil = (string) ($d['fulfil'] ?? FlowerOrder::DELIVERY);
        $delivery = $fulfil === FlowerOrder::DELIVERY;
        $self = ($d['recipient_self'] ?? true) !== false;
        $buyerPhone = WhatsAppOrders::localPhone((string) $session->customer_phone);

        $branch = $delivery
            ? StoreBranches::resolve($bid, $fulfil, null, strict: false)
            : StoreBranches::resolve($bid, $fulfil, $d['branch_id'] ?? null, strict: false);
        $branch ??= StoreBranches::default($bid);

        if ($branch === null) {
            throw new RuntimeException(__('لا فرعَ يُجهَّز فيه الطلب.'));
        }

        [$when, $label] = self::when($bid, $fulfil, $d);

        $address = trim(implode(' — ', array_filter([trim((string) ($d['area'] ?? '')), trim((string) ($d['address'] ?? ''))])));

        $card = filled($d['card_message'] ?? null) ? (string) $d['card_message'] : GiftCardProduct::orderMessage($preview['quote']['_lines'] ?? []);

        $input = [
            'enabled' => true,
            'fulfillment_type' => $fulfil,
            'branch_id' => (int) $branch->id,
            'scheduled_for' => $when->format('Y-m-d H:i:s'),
            'recipient_name' => $self ? (string) ($d['name'] ?? '') : (string) ($d['recipient_name'] ?? ''),
            'recipient_phone' => $self ? $buyerPhone : (string) ($d['recipient_phone'] ?? ''),
            'delivery_address' => $delivery ? ($address !== '' ? $address : __('يُؤكَّد العنوان مع العميل')) : null,
            'delivery_notes' => $label,
            'internal_notes' => __('طلب عبر واتساب — من :phone', ['phone' => $buyerPhone]),
            'is_gift' => (bool) ($d['is_gift'] ?? false),
            'occasion_type' => filled($d['occasion'] ?? null) ? (string) $d['occasion'] : null,
            'card_message' => filled($card) ? $card : null,
            'sender_name' => (string) ($d['name'] ?? ''),
            'hide_sender' => (bool) ($d['hide_sender'] ?? false),
            'card_identity_confirmed' => (bool) ($d['card_identity_confirmed'] ?? false),
        ];

        return Validator::make($input, CustomerInvoicePreparations::rules($bid, ''), [], CustomerInvoicePreparations::attributes(''))->validate() + ['enabled' => true];
    }

    /**
     * متى يُسلَّم — وموعدُه كما يُقرأ على ورقة التجهيز.
     *
     * من جدول المواعيد إن فُتح (بدايةُ الفترة)، وإلّا اليومُ وساعةُ الفترة
     * المكتوبة كما يقرؤها إتمامُ الموقع، وإلّا الآن: متجرٌ لا يسأل عن موعدٍ يبيع
     * لما هو جاهز.
     *
     * @return array{0: Carbon, 1: ?string}
     */
    public static function when(int $bid, string $fulfil, array $d): array
    {
        $date = (string) ($d['date'] ?? '');
        $slot = (string) ($d['slot'] ?? '');

        if ($date !== '' && $slot !== '' && DeliverySchedule::on($bid)) {
            $snapshot = DeliverySchedule::lookup($bid, $fulfil, $date, $slot);

            if ($snapshot !== null) {
                return [Carbon::instance(DeliverySchedule::bounds($snapshot)[0]->toDateTime()), DeliverySchedule::label($snapshot, 'ar')];
            }
        }

        if ($date !== '') {
            $label = $slot !== '' ? (string) (WebCheckout::settings($bid)['slots'][(int) $slot] ?? '') : '';
            $day = Carbon::parse($date, ShopClock::zone())->startOfDay();

            if (preg_match('/(\d{1,2})\s*(am|pm|ص|م)?/iu', $label, $m)) {
                $h = (int) $m[1];
                if (in_array(mb_strtolower($m[2] ?? ''), ['pm', 'م'], true) && $h < 12) {
                    $h += 12;
                }

                return [$day->setTime(min(23, $h), 0), $label !== '' ? $label : null];
            }

            return [$day->setTime(9, 0), $label !== '' ? $label : null];
        }

        return [Carbon::instance(ShopClock::now()->toDateTime()), null];
    }

    /**
     * زبونُ الرقم — ملفُّه إن عُرف بالرقم نفسه، وإلّا يُكتب بما كتبه في المحادثة.
     *
     * والمطابقةُ بالرقم مطبَّعًا كإتمام الموقع (`WebCheckout::existingCustomer`).
     */
    public static function customer(Business $business, WhatsAppOrderSession $session): Customer
    {
        $bid = (int) $business->id;
        $phone = (string) $session->customer_phone;

        if ($session->customer_id) {
            $known = Customer::where('business_id', $bid)->find($session->customer_id);
            if ($known) {
                return $known;
            }
        }

        $found = Customer::where('business_id', $bid)
            ->whereNotNull('phone')->where('phone', '!=', '')
            ->orderBy('id')
            ->get(['id', 'phone'])
            ->first(fn ($c) => WhatsAppPhone::normalize($c->phone) === $phone);

        if ($found) {
            return Customer::find($found->id);
        }

        $d = (array) $session->details;

        return Customer::create(Customers::asEntered([
            'business_id' => $bid,
            'name' => trim((string) ($d['name'] ?? '')) ?: WhatsAppOrders::localPhone($phone),
            'phone' => WhatsAppOrders::localPhone($phone),
            'language' => $session->language === 'en' ? 'en' : 'ar',
        ]));
    }

    /* ═══════════ ما يُقرأ للزبون ═══════════ */

    /** السلّةُ في رسالة — بأسماء الأصناف في لغة الزبون وأسعار القاعدة */
    public static function cartText(Business $business, array $cart, string $lang): string
    {
        if ($cart === []) {
            return WhatsAppOrderTexts::say($lang, 'cart_emptied');
        }

        $currency = Storefront::currency($business);
        $lines = [WhatsAppOrderTexts::say($lang, 'cart_head')];

        try {
            $quote = self::quote($business, $cart, []);
        } catch (Throwable) {
            return WhatsAppOrderTexts::say($lang, 'cart_head');
        }

        foreach ($quote['lines'] as $line) {
            $lines[] = self::lineText($line, $lang, $currency);
        }

        $lines[] = WhatsAppOrderTexts::say($lang, 'cart_total', ['total' => Money::format((float) $quote['subtotal'] + (float) $quote['tax'], $currency)]);

        return implode("\n", $lines);
    }

    /**
     * الملخّصُ قبل الموافقة — البنودُ والمبلغُ وكلُّ ما سيُطبع على ورقة التجهيز.
     *
     * @param  array{quote: array<string, mixed>, totals: array<string, mixed>}  $preview
     */
    public static function summary(Business $business, WhatsAppOrderSession $session, array $preview, string $lang): string
    {
        $currency = Storefront::currency($business);
        $m = fn (float $v) => Money::format($v, $currency);
        $d = (array) $session->details;
        $quote = $preview['quote'];
        $totals = $preview['totals'];

        $out = [WhatsAppOrderTexts::say($lang, 'summary_head')];

        foreach ($quote['lines'] as $line) {
            $out[] = self::lineText($line, $lang, $currency);
        }

        $out[] = '';
        $out[] = WhatsAppOrderTexts::say($lang, 'summary_delivery', [
            'fee' => (float) $quote['delivery'] > 0 ? $m((float) $quote['delivery']) : WhatsAppOrderTexts::say($lang, 'summary_free'),
        ]);

        if ((float) $totals['tax'] > 0) {
            $out[] = WhatsAppOrderTexts::say($lang, 'summary_tax', ['tax' => $m((float) $totals['tax'])]);
        }

        $out[] = '*'.WhatsAppOrderTexts::say($lang, 'summary_total', ['total' => $m((float) $totals['total'])]).'*';
        $out[] = '';

        $fulfil = (string) ($d['fulfil'] ?? FlowerOrder::DELIVERY);
        $out[] = WhatsAppOrderTexts::say($lang, 'summary_to', ['how' => WhatsAppOrderTexts::say($lang, $fulfil)]);

        $when = self::whenText((int) $business->id, $fulfil, $d, $lang);
        if ($when !== null) {
            $out[] = WhatsAppOrderTexts::say($lang, 'summary_when', ['when' => $when]);
        }

        $self = ($d['recipient_self'] ?? true) !== false;
        $out[] = WhatsAppOrderTexts::say($lang, 'summary_recipient', [
            'name' => $self ? (string) ($d['name'] ?? '') : (string) ($d['recipient_name'] ?? ''),
            'phone' => $self ? WhatsAppOrders::localPhone((string) $session->customer_phone) : (string) ($d['recipient_phone'] ?? ''),
        ]);

        if ($fulfil === FlowerOrder::DELIVERY) {
            $address = trim(implode(' — ', array_filter([trim((string) ($d['area'] ?? '')), trim((string) ($d['address'] ?? ''))])));
            if ($address !== '') {
                $out[] = WhatsAppOrderTexts::say($lang, 'summary_address', ['address' => $address]);
            }
        } elseif (filled($d['branch_id'] ?? null)) {
            $branch = StoreBranches::live((int) $business->id)->firstWhere('id', (int) $d['branch_id']);
            if ($branch) {
                $out[] = WhatsAppOrderTexts::say($lang, 'summary_branch', ['branch' => (string) $branch->name]);
            }
        }

        if ($d['is_gift'] ?? false) {
            $out[] = WhatsAppOrderTexts::say($lang, 'summary_gift');
            if (filled($d['card_message'] ?? null)) {
                $out[] = WhatsAppOrderTexts::say($lang, 'summary_card', ['card' => (string) $d['card_message']]);
            }
            if ($d['hide_sender'] ?? false) {
                $out[] = WhatsAppOrderTexts::say($lang, 'summary_hidden');
            }
        }

        return mb_substr(implode("\n", $out), 0, WhatsAppOrderReplies::TEXT_MAX);
    }

    /** الموعدُ بلغة الزبون — أو `null` لمتجرٍ لا يسأل عنه */
    public static function whenText(int $bid, string $fulfil, array $d, string $lang): ?string
    {
        $date = (string) ($d['date'] ?? '');

        if ($date === '') {
            return null;
        }

        $day = ShopClock::day(ShopClock::date($date), $lang);
        $slot = (string) ($d['slot'] ?? '');

        if ($slot === '') {
            return $day;
        }

        if (DeliverySchedule::on($bid)) {
            $snapshot = DeliverySchedule::lookup($bid, $fulfil, $date, $slot);

            return $snapshot ? $day.' — '.DeliverySchedule::label($snapshot, $lang) : $day;
        }

        $label = (string) (WebCheckout::settings($bid)['slots'][(int) $slot] ?? '');

        return $label !== '' ? $day.' — '.$label : $day;
    }

    /** @param  array<string, mixed>  $line  سطرٌ من `quote()['lines']` */
    private static function lineText(array $line, string $lang, array $currency): string
    {
        $name = ProductName::display((string) $line['name'], $line['name_en'] ?? null, $lang);
        $variant = $lang === 'en' && filled($line['variant_en'] ?? null) ? $line['variant_en'] : ($line['variant'] ?? null);
        $text = '• '.$line['qty'].' × '.$name.($variant ? ' ('.$variant.')' : '').' — '.Money::format((float) $line['line'], $currency);

        foreach ($line['addons'] ?? [] as $a) {
            $text .= "\n   + ".ProductName::display((string) $a['name'], $a['name_en'] ?? null, $lang).' ×'.$a['qty'];
        }

        if (($line['gift_card'] ?? false) || filled($line['note'] ?? null)) {
            $text .= "\n   «".mb_substr((string) $line['note'], 0, 120).'»';
        }

        return $text;
    }

    /** سببٌ يُقرأ للزبون من خطأ التسعير أو الكتابة */
    public static function reason(Throwable $e): string
    {
        if ($e instanceof ValidationException) {
            return (string) (collect($e->errors())->flatten()->first() ?? $e->getMessage());
        }

        return $e->getMessage();
    }
}
