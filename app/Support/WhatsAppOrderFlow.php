<?php

namespace App\Support;

use App\Models\Business;
use App\Models\Customer;
use App\Models\Product;
use App\Models\WhatsAppOrderSession;
use App\Support\Store\CardIdentity;
use App\Support\Store\CheckoutFields;
use App\Support\Store\DeliverySchedule;
use App\Support\Store\EnglishCheckout;
use App\Support\Store\GiftCardProduct;
use App\Support\Store\GiftOrders;
use App\Support\Store\OrderWindow;
use App\Support\Store\ShopClock;
use App\Support\Store\StoreBranches;
use App\Support\Store\WebCheckout;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * خطواتُ الطلب في المحادثة — سؤالٌ واحدٌ في كلّ مرّة، وجوابُه زرٌّ أو نصٌّ يُتحقَّق منه.
 *
 * ═══ ولا فهمَ حرًّا لما يكتبه الزبون ═══
 *
 * ما يُختار من الكتالوج والمواعيد والفروع يُختار بزرٍّ يحمل معرّفه من القاعدة،
 * لا بنصٍّ يُخمَّن معناه. والنصُّ يُقبل حيث يُسأل عن نصٍّ وحده: الاسم، والهاتف،
 * والعنوان، ونصُّ البطاقة — ويُتحقَّق منه بقاعدة الموقع نفسِها. فلا يُبنى مبلغٌ
 * على كلمةٍ أُسيء فهمُها.
 *
 * ═══ وزرٌّ من رسالةٍ أقدم ═══
 *
 * كلُّ سؤالٍ يحمل رقمَه (`seq`) في معرّفات أزراره. فزرٌّ ضُغط من قائمةٍ سابقة —
 * أو من طلبٍ سابق — لا يُطبَّق على سؤالٍ غيره: يُقال للزبون ويُعاد السؤال.
 *
 * ═══ وما يخرج من هنا ═══
 *
 * رسائلُ تُرسَل بعد أن تُحفظ الحال (`WhatsAppOrders::receive`)، وعلامةُ
 * `issue` حين وافق الزبونُ على الملخّص: الفاتورةُ والرابطُ يُكتبان بعدها
 * خارج قفل المحادثة (`IssueWhatsAppOrderPayment`). ولا شيءَ ماليٌّ يُكتب هنا.
 */
final class WhatsAppOrderFlow
{
    public const MAX_QTY = 50;

    public const MAX_LINES = 20;

    public const MAX_ADDON_QTY = 20;

    public const NAME_MAX = 120;

    public const ADDRESS_MAX = 400;

    /** كلماتٌ تُقبل في أيّ خطوة — بعد تطبيع النصّ (`normalize`) */
    public const CANCEL_WORDS = ['الغاء', 'الغي', 'cancel', 'stop'];

    public const STAFF_WORDS = ['موظف', 'خدمه العملاء', 'staff', 'agent', 'human', 'support'];

    public const RESTART_WORDS = ['ابدا من جديد', 'من جديد', 'restart', 'start over'];

    /** @var list<array<string, mixed>> */
    private array $out = [];

    private bool $issue = false;

    private string $lang;

    private int $bid;

    public function __construct(private Business $business, private WhatsAppOrderSession $s)
    {
        $this->bid = (int) $business->id;
        $this->lang = $s->language === 'en' ? 'en' : 'ar';
    }

    /**
     * جوابُ الزبون على السؤال القائم.
     *
     * @param  array{id: ?string, text: string}  $input
     * @return array{messages: list<array<string, mixed>>, issue: bool}
     */
    public function handle(array $input): array
    {
        [$value, $stale] = $this->value($input['id']);
        $text = trim((string) $input['text']);
        $word = WhatsAppOrders::normalize($text);

        if ($stale) {
            $this->say('stale');
            $this->ask();

            return $this->result();
        }

        if ($value === null && $this->language($word)) {
            $this->ask();

            return $this->result();
        }

        if ($value === 'staff' || ($value === null && in_array($word, self::STAFF_WORDS, true))) {
            $this->handoff(WhatsAppOrders::REASON_ASKED);

            return $this->result();
        }

        if ($this->s->state === WhatsAppOrderSession::AWAITING_PAYMENT) {
            $this->awaiting($value === 'cancel' || ($value === null && in_array($word, self::CANCEL_WORDS, true)));

            return $this->result();
        }

        if ($value === 'cancel' || ($value === null && in_array($word, self::CANCEL_WORDS, true))) {
            $this->close(WhatsAppOrderSession::CANCELLED);
            $this->say('cancelled');

            return $this->result();
        }

        if ($value === null && in_array($word, self::RESTART_WORDS, true)) {
            $this->restart();

            return $this->result();
        }

        $this->answer((string) $this->s->step, $value, $text);

        return $this->result();
    }

    /** أوّلُ سؤال في طلبٍ جديد */
    public function start(): array
    {
        $this->s->step = 'category';
        $this->ask(greet: true);

        return $this->result();
    }

    /**
     * تغيّر المبلغُ بعد الموافقة وقبل أن تُكتب الفاتورة — يُعاد الملخّصُ بمبلغه ويُسأل ثانيةً.
     *
     * @param  array{quote: array<string, mixed>, totals: array<string, mixed>}  $preview
     */
    public function reconfirm(array $preview): array
    {
        $this->say('price_changed');
        $this->summary($preview);

        return $this->result();
    }

    /** @return array{messages: list<array<string, mixed>>, issue: bool} */
    private function result(): array
    {
        return ['messages' => $this->out, 'issue' => $this->issue];
    }

    /* ═══════════ قراءةُ الجواب ═══════════ */

    /**
     * قيمةُ الزرّ المضغوط — أو `null` لنصٍّ مكتوب. والثاني: أهو زرٌّ من سؤالٍ سابق؟
     *
     * @return array{0: ?string, 1: bool}
     */
    private function value(?string $id): array
    {
        if ($id === null || $id === '') {
            return [null, false];
        }

        if (in_array($id, [WhatsAppOrders::START_ID, WhatsAppOrders::STAFF_ID], true)) {
            return [$id === WhatsAppOrders::STAFF_ID ? 'staff' : null, false];
        }

        $parts = explode(':', $id, 4);

        if (count($parts) !== 4 || $parts[0] !== 'o') {
            return [null, false];
        }

        if ((int) $parts[1] !== (int) $this->s->id || (int) $parts[2] !== (int) $this->s->seq) {
            return [null, true];
        }

        return [$parts[3], false];
    }

    private function id(string $value): string
    {
        return 'o:'.$this->s->id.':'.$this->s->seq.':'.$value;
    }

    /** «English» و«عربي» تبدّلان لغةَ الحوار في أيّ خطوة */
    private function language(string $word): bool
    {
        $to = match ($word) {
            'english', 'en' => 'en',
            'عربي', 'arabic', 'ar' => 'ar',
            default => null,
        };

        if ($to === null) {
            return false;
        }

        $this->lang = $to;
        $this->s->language = $to;

        return true;
    }

    /* ═══════════ الخطوات ═══════════ */

    private function answer(string $step, ?string $value, string $text): void
    {
        $ok = match ($step) {
            'category' => $this->onCategory($value),
            'product' => $this->onProduct($value),
            'variant' => $this->onVariant($value),
            'qty' => $this->onQty($value, $text),
            'card_text' => $this->onCardText($value, $text),
            'addons' => $this->onAddons($value),
            'cart' => $this->onCart($value),
            'edit' => $this->onEdit($value),
            'fulfil' => $this->onFulfil($value),
            'name' => $this->onName($text),
            'recipient' => $this->onRecipient($value),
            'recipient_name' => $this->onRecipientName($text),
            'recipient_phone' => $this->onRecipientPhone($text),
            'area' => $this->onArea($value, $text),
            'address' => $this->onAddress($value, $text),
            'date' => $this->onDate($value),
            'slot' => $this->onSlot($value),
            'branch' => $this->onBranch($value),
            'gift' => $this->onGift($value),
            'occasion' => $this->onOccasion($value),
            'card' => $this->onCard($value, $text),
            'hide' => $this->onHide($value),
            'card_confirm' => $this->onCardConfirm($value),
            'confirm' => $this->onConfirm($value),
            default => false,
        };

        if ($ok === false) {
            $this->say('invalid');
            $this->ask();
        }
    }

    private function onCategory(?string $value): bool
    {
        return match (true) {
            $value === 'checkout' && $this->cart() !== [] => $this->checkout(),
            $value === 'special' => $this->handoff(WhatsAppOrders::REASON_SPECIAL) || true,
            $value === 'more' => $this->page(+1) || true,
            $value !== null && str_starts_with($value, 'c:') => $this->pickCategory((int) substr($value, 2)),
            default => false,
        };
    }

    private function pickCategory(int $categoryId): bool
    {
        $names = array_column(WhatsAppOrderCatalog::categories($this->bid, $this->lang), 'id');

        if (! in_array($categoryId, $names, true)) {
            return false;
        }

        $this->context(['category_id' => $categoryId, 'page' => 0]);
        $this->go('product');

        return true;
    }

    private function onProduct(?string $value): bool
    {
        if ($value === 'back') {
            $this->context(['page' => 0]);
            $this->go('category');

            return true;
        }

        if ($value === 'more') {
            return $this->page(+1) || true;
        }

        if ($value === null || ! str_starts_with($value, 'p:')) {
            return false;
        }

        $product = WhatsAppOrderCatalog::product($this->bid, (int) substr($value, 2));

        if (! $product || (int) $product->category_id !== (int) ($this->ctx('category_id') ?? 0)) {
            return false;
        }

        $this->context(['draft' => ['product_id' => (int) $product->id, 'variant_id' => null, 'qty' => 1, 'addons' => [], 'note' => null], 'page' => 0]);

        $variants = WhatsAppOrderCatalog::variants($product, $this->lang);

        if ($variants !== []) {
            $this->go('variant');
        } elseif (WhatsAppOrderCatalog::isCard($this->bid, $product)) {
            $this->go('card_text');
        } else {
            $this->go('qty');
        }

        return true;
    }

    private function onVariant(?string $value): bool
    {
        $product = $this->draftProduct();

        if (! $product || $value === null || ! str_starts_with($value, 'v:')) {
            return false;
        }

        $id = (int) substr($value, 2);

        if (! in_array($id, array_column(WhatsAppOrderCatalog::variants($product, $this->lang), 'id'), true)) {
            return false;
        }

        $this->draft(['variant_id' => $id]);
        $this->go(WhatsAppOrderCatalog::isCard($this->bid, $product) ? 'card_text' : 'qty');

        return true;
    }

    private function onQty(?string $value, string $text): bool
    {
        $raw = $value !== null && str_starts_with($value, 'q:') ? substr($value, 2) : WhatsAppOrders::digits($text);

        if (! preg_match('/^\d{1,3}$/', (string) $raw) || (int) $raw < 1 || (int) $raw > self::MAX_QTY) {
            $this->say('bad_qty', ['max' => self::MAX_QTY]);
            $this->ask();

            return true;
        }

        $this->draft(['qty' => (int) $raw]);

        return $this->afterQuantity();
    }

    private function onCardText(?string $value, string $text): bool
    {
        if ($value !== null || $text === '') {
            return false;
        }

        if (mb_strlen($text) > GiftCardProduct::MAX) {
            $this->say('card_too_long', ['max' => GiftCardProduct::MAX]);
            $this->ask();

            return true;
        }

        $this->draft(['qty' => 1, 'note' => $text]);

        return $this->afterQuantity();
    }

    private function afterQuantity(): bool
    {
        $product = $this->draftProduct();

        if (! $product) {
            return false;
        }

        if (WhatsAppOrderCatalog::addons($this->bid, $product, $this->lang) !== []) {
            $this->go('addons');

            return true;
        }

        return $this->commitDraft();
    }

    private function onAddons(?string $value): bool
    {
        $product = $this->draftProduct();

        if (! $product || $value === null) {
            return false;
        }

        if ($value === 'done') {
            return $this->commitDraft();
        }

        if (! str_starts_with($value, 'a:')) {
            return false;
        }

        $id = (int) substr($value, 2);

        if (! in_array($id, array_column(WhatsAppOrderCatalog::addons($this->bid, $product, $this->lang), 'id'), true)) {
            return false;
        }

        $addons = (array) ($this->ctx('draft')['addons'] ?? []);
        $found = false;

        foreach ($addons as &$a) {
            if ((int) $a['id'] === $id) {
                $a['qty'] = min(self::MAX_ADDON_QTY, (int) $a['qty'] + 1);
                $found = true;
            }
        }
        unset($a);

        if (! $found && count($addons) < WebCheckout::MAX_ADDONS) {
            $addons[] = ['id' => $id, 'qty' => 1];
        }

        $this->draft(['addons' => array_values($addons)]);
        $this->ask();

        return true;
    }

    /**
     * يدخل البندُ السلّة — بعد أن يُسعَّر من القاعدة مع ما قبله.
     *
     * وما لا يُسعَّر (نفد رفُّه، أُوقف) يُقال ولا يُضاف: سلّةٌ لا تُسعَّر لا تصل الملخّص.
     */
    private function commitDraft(): bool
    {
        $draft = (array) $this->ctx('draft');
        $product = $this->draftProduct();

        if (! $product) {
            return false;
        }

        $cart = $this->cart();
        $cart[] = [
            'product_id' => (int) $draft['product_id'],
            'variant_id' => $draft['variant_id'] ?? null,
            'qty' => (int) $draft['qty'],
            'addons' => array_values((array) ($draft['addons'] ?? [])),
            'note' => $draft['note'] ?? null,
        ];
        $cart = array_slice($cart, -self::MAX_LINES);

        $this->context(['draft' => null]);

        try {
            WhatsAppOrderCheckout::quote($this->business, $cart, $this->details());
        } catch (ValidationException|RuntimeException) {
            $this->say('unavailable_line', ['product' => WhatsAppOrderCatalog::productName($product, $this->lang)]);
            $this->go('cart');

            return true;
        }

        $this->s->cart = $cart;
        $this->say('added');
        $this->go('cart');

        return true;
    }

    private function onCart(?string $value): bool
    {
        return match ($value) {
            'add' => $this->go('category') || true,
            'checkout' => $this->cart() !== [] ? $this->checkout() : ($this->go('category') || true),
            'empty' => $this->emptyCart(),
            default => false,
        };
    }

    private function onEdit(?string $value): bool
    {
        return match ($value) {
            'add' => $this->go('category') || true,
            'empty' => $this->emptyCart(),
            'details' => $this->resetDetails(),
            default => false,
        };
    }

    private function emptyCart(): bool
    {
        $this->s->cart = [];
        $this->s->total = null;
        $this->say('cart_emptied');
        $this->context(['page' => 0]);
        $this->go('category');

        return true;
    }

    private function resetDetails(): bool
    {
        $this->s->details = [];
        $this->s->total = null;

        return $this->checkout();
    }

    /* ═══ التسليم ═══ */

    private function onFulfil(?string $value): bool
    {
        if (! in_array($value, CheckoutFields::fulfilments($this->bid), true)) {
            return false;
        }

        // نوعٌ آخر يعني موعدًا وفرعًا من جديد: مواعيدُ الاستلام غيرُ مواعيد التوصيل
        $this->detail(['fulfil' => $value, 'date' => null, 'slot' => null, 'branch_id' => null]);

        return $this->checkout();
    }

    private function onName(string $text): bool
    {
        $name = $this->cleanName($text);

        if ($name === null) {
            return true;
        }

        $this->detail(['name' => $name]);

        return $this->checkout();
    }

    private function onRecipient(?string $value): bool
    {
        if (! in_array($value, ['me', 'other'], true)) {
            return false;
        }

        $this->detail($value === 'me'
            ? ['recipient_self' => true, 'recipient_name' => null, 'recipient_phone' => null]
            : ['recipient_self' => false]);

        return $this->checkout();
    }

    private function onRecipientName(string $text): bool
    {
        $name = $this->cleanName($text);

        if ($name === null) {
            return true;
        }

        $this->detail(['recipient_name' => $name]);

        return $this->checkout();
    }

    private function onRecipientPhone(string $text): bool
    {
        $phone = WhatsAppOrders::digits($text);

        if (! WhatsAppPhone::isValid($phone) || ! preg_match('/^[0-9+()\-\s]{8,32}$/', $phone)) {
            $this->say('bad_phone');
            $this->ask();

            return true;
        }

        $this->detail(['recipient_phone' => $phone]);

        return $this->checkout();
    }

    private function onArea(?string $value, string $text): bool
    {
        $areas = $this->areas();

        if ($areas !== []) {
            if ($value === 'more') {
                return $this->page(+1) || true;
            }
            if ($value === null || ! str_starts_with($value, 'ar:') || ! isset($areas[(int) substr($value, 3)])) {
                return false;
            }
            $this->detail(['area' => $areas[(int) substr($value, 3)]]);
            $this->context(['page' => 0]);

            return $this->checkout();
        }

        if ($value === 'skip' && ! CheckoutFields::requires($this->bid, 'area')) {
            $this->detail(['area' => '']);

            return $this->checkout();
        }

        if ($value !== null || mb_strlen($text) < 2) {
            return false;
        }

        $this->detail(['area' => mb_substr($text, 0, 60)]);

        return $this->checkout();
    }

    private function onAddress(?string $value, string $text): bool
    {
        if ($value === 'skip' && ! CheckoutFields::requires($this->bid, 'address')) {
            $this->detail(['address' => '']);

            return $this->checkout();
        }

        if ($value !== null) {
            return false;
        }

        if (mb_strlen($text) < 4) {
            $this->say('too_short');
            $this->ask();

            return true;
        }

        if (EnglishCheckout::on($this->bid) && ! preg_match(EnglishCheckout::ADDRESS, $text)) {
            $this->say('english_only');
            $this->ask();

            return true;
        }

        $this->detail(['address' => mb_substr($text, 0, self::ADDRESS_MAX)]);

        return $this->checkout();
    }

    private function onDate(?string $value): bool
    {
        if ($value === 'more') {
            return $this->page(+1) || true;
        }

        if ($value === null || ! str_starts_with($value, 'd:')) {
            return false;
        }

        $date = substr($value, 2);

        if (! in_array($date, array_column($this->dates(), 'date'), true)) {
            $this->say('slot_gone');
            $this->ask();

            return true;
        }

        $this->detail(['date' => $date, 'slot' => null]);
        $this->context(['page' => 0]);

        return $this->checkout();
    }

    private function onSlot(?string $value): bool
    {
        if ($value === null || ! str_starts_with($value, 's:')) {
            return false;
        }

        $slot = substr($value, 2);
        $date = (string) ($this->details()['date'] ?? '');

        if (DeliverySchedule::on($this->bid)) {
            if (DeliverySchedule::pick($this->bid, $this->fulfil(), $date, $slot) === null) {
                $this->say('slot_gone');
                $this->detail(['slot' => null]);
                $this->go('slot');

                return true;
            }
        } elseif (! isset(WebCheckout::settings($this->bid)['slots'][(int) $slot])) {
            return false;
        }

        $this->detail(['slot' => $slot]);

        return $this->checkout();
    }

    private function onBranch(?string $value): bool
    {
        if ($value === null || ! str_starts_with($value, 'b:')) {
            return false;
        }

        $id = (int) substr($value, 2);

        if (! StoreBranches::pickupChoices($this->bid)->contains(fn ($b) => (int) $b->id === $id)) {
            return false;
        }

        $this->detail(['branch_id' => $id, 'date' => null, 'slot' => null]);

        return $this->checkout();
    }

    private function onGift(?string $value): bool
    {
        if (! in_array($value, ['yes', 'no'], true)) {
            return false;
        }

        $this->detail($value === 'yes'
            ? ['is_gift' => true]
            : ['is_gift' => false, 'occasion' => null, 'card_message' => null, 'hide_sender' => false, 'card_identity_confirmed' => false]);

        return $this->checkout();
    }

    private function onOccasion(?string $value): bool
    {
        if ($value === 'none') {
            $this->detail(['occasion' => '']);

            return $this->checkout();
        }

        if ($value === null || ! str_starts_with($value, 'oc:') || ! in_array(substr($value, 3), FlowerOrder::OCCASIONS, true)) {
            return false;
        }

        $this->detail(['occasion' => substr($value, 3)]);

        return $this->checkout();
    }

    private function onCard(?string $value, string $text): bool
    {
        if ($value === 'none') {
            $this->detail(['card_message' => '', 'card_identity_confirmed' => false]);

            return $this->checkout();
        }

        if ($value !== null || $text === '') {
            return false;
        }

        if (mb_strlen($text) > FlowerOrder::CARD_MAX) {
            $this->say('card_too_long', ['max' => FlowerOrder::CARD_MAX]);
            $this->ask();

            return true;
        }

        $this->detail(['card_message' => $text, 'card_identity_confirmed' => false]);

        return $this->checkout();
    }

    private function onHide(?string $value): bool
    {
        if (! in_array($value, ['hide', 'show'], true)) {
            return false;
        }

        $this->detail(['hide_sender' => $value === 'hide', 'card_identity_confirmed' => false]);

        return $this->checkout();
    }

    private function onCardConfirm(?string $value): bool
    {
        return match ($value) {
            'keep' => ($this->detail(['card_identity_confirmed' => true]) || true) && $this->checkout(),
            'rewrite' => ($this->detail(['card_message' => null, 'card_identity_confirmed' => false]) || true) && $this->checkout(),
            default => false,
        };
    }

    /* ═══ الملخّص والتأكيد ═══ */

    private function onConfirm(?string $value): bool
    {
        return match ($value) {
            'yes' => $this->confirm(),
            'edit' => $this->go('edit') || true,
            default => false,
        };
    }

    /**
     * وافق الزبون — بعد أن يُعاد الحسابُ كلُّه من القاعدة.
     *
     * المبلغُ الذي وافق عليه هو ما يُكتب: ما تغيّر بين الملخّص والضغطة (سعرٌ،
     * رفٌّ، موعدٌ مضى) يُعرض من جديد ويُسأل عنه، ولا يُكتب بغير ما وافق عليه.
     */
    private function confirm(): bool
    {
        if (! OrderWindow::open($this->bid)) {
            $this->out[] = ['kind' => 'text', 'body' => OrderWindow::message($this->bid, $this->lang)];
            $this->go('cart');

            return true;
        }

        if (DeliverySchedule::on($this->bid) && filled($this->details()['slot'] ?? null)
            && DeliverySchedule::pick($this->bid, $this->fulfil(), (string) $this->details()['date'], (string) $this->details()['slot']) === null) {
            $this->say('slot_gone');
            $this->detail(['date' => null, 'slot' => null]);

            return $this->checkout();
        }

        try {
            $preview = WhatsAppOrderCheckout::preview($this->business, $this->s);
        } catch (ValidationException|RuntimeException $e) {
            $this->say('cart_problem', ['why' => WhatsAppOrderCheckout::reason($e)]);
            $this->go('cart');

            return true;
        }

        if ($this->s->total === null || abs((float) $this->s->total - (float) $preview['totals']['total']) > 0.0005) {
            $this->say('price_changed');
            $this->summary($preview);

            return true;
        }

        $this->s->state = WhatsAppOrderSession::AWAITING_PAYMENT;
        $this->s->step = null;
        $this->s->confirmed_at = now();
        $this->say('issuing');
        $this->issue = true;

        return true;
    }

    /**
     * أوّلُ ما ينقص قبل الملخّص — يُسأل عنه، أو الملخّصُ إن اكتمل كلُّه.
     *
     * والترتيبُ ترتيبُ صفحة الإتمام: التسليم، ثمّ من يستلم وأين ومتى، ثمّ الهديّة.
     * وما أطفأه صاحبُ المحلّ في «حقول الطلب» لا يُسأل عنه (`CheckoutFields`).
     */
    private function checkout(): bool
    {
        if ($this->cart() === []) {
            $this->go('category');

            return true;
        }

        if (! OrderWindow::open($this->bid)) {
            $this->out[] = ['kind' => 'text', 'body' => OrderWindow::message($this->bid, $this->lang)];
            $this->go('cart');

            return true;
        }

        $this->s->state = WhatsAppOrderSession::COLLECTING;
        $d = $this->details();
        $fulfilments = CheckoutFields::fulfilments($this->bid);

        if (! in_array($d['fulfil'] ?? null, $fulfilments, true)) {
            if (count($fulfilments) === 1) {
                $this->detail(['fulfil' => $fulfilments[0]]);

                return $this->checkout();
            }

            return $this->go('fulfil') || true;
        }

        if (blank($d['name'] ?? null)) {
            $known = $this->knownName();

            if ($known !== null) {
                $this->detail(['name' => $known]);

                return $this->checkout();
            }

            return $this->go('name') || true;
        }

        $delivery = $d['fulfil'] === FlowerOrder::DELIVERY;

        if (! $delivery && blank($d['branch_id'] ?? null)) {
            $choices = StoreBranches::pickupChoices($this->bid);

            if ($choices->count() > 1) {
                return $this->go('branch') || true;
            }

            $branch = StoreBranches::default($this->bid);

            if ($branch === null) {
                $this->handoff(WhatsAppOrders::REASON_NO_BRANCH);

                return true;
            }

            $this->detail(['branch_id' => (int) $branch->id]);
        }

        $wantsRecipient = ! $delivery || CheckoutFields::shows($this->bid, 'recipient') || ($d['is_gift'] ?? false);

        if ($wantsRecipient && ! array_key_exists('recipient_self', $d)) {
            return $this->go('recipient') || true;
        }

        if (($d['recipient_self'] ?? true) === false) {
            if (blank($d['recipient_name'] ?? null)) {
                return $this->go('recipient_name') || true;
            }
            if (blank($d['recipient_phone'] ?? null)) {
                return $this->go('recipient_phone') || true;
            }
        }

        if ($delivery) {
            $areas = $this->areas();

            if (! array_key_exists('area', $d) && ($areas !== [] || CheckoutFields::shows($this->bid, 'area'))) {
                return $this->go('area') || true;
            }

            if (! array_key_exists('address', $d) && CheckoutFields::shows($this->bid, 'address')) {
                return $this->go('address') || true;
            }
        }

        if ($this->asksDate()) {
            if (blank($d['date'] ?? null)) {
                if ($this->dates() === []) {
                    $this->out[] = ['kind' => 'text', 'body' => DeliverySchedule::unavailableSays($this->bid, $this->lang) ?? WhatsAppOrderTexts::say($this->lang, 'no_dates')];
                    $this->handoff(WhatsAppOrders::REASON_NO_DATES);

                    return true;
                }

                return $this->go('date') || true;
            }

            if ($this->slots() !== [] && blank($d['slot'] ?? null)) {
                return $this->go('slot') || true;
            }
        }

        if (GiftOrders::on($this->bid)) {
            if (! array_key_exists('is_gift', $d)) {
                return $this->go('gift') || true;
            }

            if ($d['is_gift']) {
                // والهديّةُ تحتاج مستلمًا باسمه ورقمه (`GiftOrders::after`) — ولو أطفأ المتجرُ حقلَه
                if (! array_key_exists('recipient_self', $d)) {
                    return $this->go('recipient') || true;
                }
                if (! array_key_exists('occasion', $d)) {
                    return $this->go('occasion') || true;
                }
                if (! array_key_exists('card_message', $d) || $d['card_message'] === null) {
                    return $this->go('card') || true;
                }
                if (! array_key_exists('hide_sender', $d)) {
                    return $this->go('hide') || true;
                }
                if ($this->cardReveals() && ! ($d['card_identity_confirmed'] ?? false)) {
                    return $this->go('card_confirm') || true;
                }
            }
        }

        try {
            $preview = WhatsAppOrderCheckout::preview($this->business, $this->s);
        } catch (ValidationException|RuntimeException $e) {
            $this->say('cart_problem', ['why' => WhatsAppOrderCheckout::reason($e)]);
            $this->go('cart');

            return true;
        }

        $this->summary($preview);

        return true;
    }

    /** @param  array{lines: list<array>, totals: array, quote: array}  $preview */
    private function summary(array $preview): void
    {
        $this->s->total = $preview['totals']['total'];
        $this->s->state = WhatsAppOrderSession::CONFIRMING;
        $this->out[] = ['kind' => 'text', 'body' => WhatsAppOrderCheckout::summary($this->business, $this->s, $preview, $this->lang)];
        $this->go('confirm');
    }

    /* ═══ الدفعُ المنتظَر ═══ */

    /**
     * رسالةٌ والطلبُ ينتظر المال — يُعاد الرابطُ إن بقي مفتوحًا، وإلّا فموظّف.
     *
     * والإلغاءُ هنا لا يُلغي فاتورةً ولا رابطًا آليًّا: الفاتورةُ صدرت، وإلغاؤها
     * قيدٌ عكسيّ يقرّره موظّف — فيُحوَّل إليه.
     */
    private function awaiting(bool $cancel): void
    {
        if ($cancel) {
            $this->say('cannot_cancel');
            $this->handoff(WhatsAppOrders::REASON_CANCEL_AFTER_LINK, quiet: true);

            return;
        }

        $link = $this->s->paymentLink;
        $invoice = $this->s->invoice;

        if ($link === null) {
            // الرابطُ يُكتب الآن (`IssueWhatsAppOrderPayment`) — أو تعذّر وحوّله موظّف
            $this->say(filled($this->s->error) ? 'failed_link' : 'issuing');

            return;
        }

        if (! $link->open()) {
            $this->handoff(WhatsAppOrders::REASON_LINK_CLOSED);

            return;
        }

        $this->out[] = [
            'kind' => 'cta',
            'body' => WhatsAppOrderTexts::say($this->lang, 'awaiting', ['number' => (string) $invoice?->number]),
            'label' => WhatsAppOrderTexts::say($this->lang, 'pay_now'),
            'url' => (string) $link->short_url,
        ];
    }

    /* ═══════════ الأسئلة ═══════════ */

    private function go(string $step): bool
    {
        if ($step !== $this->s->step) {
            $this->context(['page' => 0]);
        }

        $this->s->step = $step;
        $this->ask();

        return true;
    }

    private function page(int $delta): bool
    {
        $this->context(['page' => max(0, (int) ($this->ctx('page') ?? 0) + $delta)]);
        $this->ask();

        return true;
    }

    /** يطرح سؤالَ الخطوة القائمة برقمٍ جديد — فأزرارُ ما قبله تُعرف قديمة */
    private function ask(bool $greet = false): void
    {
        $this->s->seq = (int) $this->s->seq + 1;
        $step = (string) $this->s->step;

        match ($step) {
            'category' => $this->askCategory($greet),
            'product' => $this->askProduct(),
            'variant' => $this->askVariant(),
            'qty' => $this->askQty(),
            'card_text' => $this->text('card_text', ['max' => GiftCardProduct::MAX]),
            'addons' => $this->askAddons(),
            'cart' => $this->askCart(),
            'edit' => $this->buttons('edit_menu', [['add', 'add_more'], ['empty', 'empty_cart'], ['details', 'change_details']]),
            'fulfil' => $this->buttons('pick_fulfil', array_map(fn ($f) => [$f, $f], CheckoutFields::fulfilments($this->bid))),
            'name' => $this->text(EnglishCheckout::on($this->bid) ? 'ask_name_en' : 'ask_name'),
            'recipient' => $this->buttons($this->fulfil() === FlowerOrder::PICKUP ? 'ask_pickup_person' : 'ask_recipient', [['me', 'me'], ['other', 'someone_else']]),
            'recipient_name' => $this->text(EnglishCheckout::on($this->bid) ? 'ask_recipient_name_en' : 'ask_recipient_name'),
            'recipient_phone' => $this->text('ask_recipient_phone'),
            'area' => $this->askArea(),
            'address' => $this->askAddress(),
            'date' => $this->askDate(),
            'slot' => $this->askSlot(),
            'branch' => $this->askBranch(),
            'gift' => $this->buttons('ask_gift', [['yes', 'yes_gift'], ['no', 'no_gift']]),
            'occasion' => $this->askOccasion(),
            'card' => $this->out[] = [
                'kind' => 'buttons',
                'body' => WhatsAppOrderTexts::say($this->lang, 'ask_card', ['max' => FlowerOrder::CARD_MAX]),
                'buttons' => [[$this->id('none'), WhatsAppOrderTexts::say($this->lang, 'no_card')]],
            ],
            'hide' => $this->buttons('ask_hide', [['hide', 'hide'], ['show', 'show']]),
            'card_confirm' => $this->buttons('card_reveals', [['keep', 'print_as_is'], ['rewrite', 'rewrite']]),
            'confirm' => $this->buttons('ask_confirm', [['yes', 'confirm'], ['edit', 'edit'], ['cancel', 'cancel']], ['total' => $this->money((float) $this->s->total)]),
            default => null,
        };
    }

    private function askCategory(bool $greet): void
    {
        $categories = WhatsAppOrderCatalog::categories($this->bid, $this->lang);
        $cart = $this->cart();

        if ($categories === []) {
            $this->say('empty_catalog');
            $this->handoff(WhatsAppOrders::REASON_EMPTY_CATALOG, quiet: true);

            return;
        }

        // قسمٌ واحدٌ والسلّةُ فارغة: لا يُسأل عمّا لا خيارَ فيه
        if (count($categories) === 1 && $cart === []) {
            $this->context(['category_id' => $categories[0]['id']]);
            $this->s->step = 'product';

            if ($greet) {
                $this->say('greet', ['shop' => $this->shopName()]);
            }
            $this->askProduct();

            return;
        }

        $fixed = [];
        if ($cart !== []) {
            $fixed[] = [$this->id('checkout'), WhatsAppOrderTexts::say($this->lang, 'checkout_row'), WhatsAppOrderTexts::say($this->lang, 'checkout_row_hint', ['count' => count($cart)])];
        }
        $fixed[] = [$this->id('special'), WhatsAppOrderTexts::say($this->lang, 'special'), WhatsAppOrderTexts::say($this->lang, 'special_hint')];

        $rows = array_map(fn ($c) => [$this->id('c:'.$c['id']), $c['name'], null], $categories);

        $this->out[] = [
            'kind' => 'list',
            'body' => WhatsAppOrderTexts::say($this->lang, $greet ? 'greet' : 'pick_category', ['shop' => $this->shopName()])."\n".WhatsAppOrderTexts::say($this->lang, 'hint'),
            'button' => WhatsAppOrderTexts::say($this->lang, 'categories'),
            'rows' => $this->paged($rows, $fixed),
        ];
    }

    private function askProduct(): void
    {
        $category = (int) ($this->ctx('category_id') ?? 0);
        $products = WhatsAppOrderCatalog::products($this->bid, $category, $this->lang);

        $rows = array_map(fn ($p) => [
            $this->id('p:'.$p['id']),
            $p['name'],
            ($p['from'] ? ($this->lang === 'en' ? 'From ' : 'من ') : '').$this->money($p['price']),
        ], $products);

        $back = [[$this->id('back'), WhatsAppOrderTexts::say($this->lang, 'back_categories'), null]];

        $this->out[] = [
            'kind' => 'list',
            'body' => WhatsAppOrderTexts::say($this->lang, 'pick_product', ['category' => WhatsAppOrderCatalog::categoryName($this->bid, $category, $this->lang)]),
            'button' => WhatsAppOrderTexts::say($this->lang, 'products'),
            'rows' => $this->paged($rows, $back),
        ];
    }

    private function askVariant(): void
    {
        $product = $this->draftProduct();

        if (! $product) {
            $this->go('category');

            return;
        }

        $rows = array_map(fn ($v) => [$this->id('v:'.$v['id']), $v['name'], $this->money($v['price'])], WhatsAppOrderCatalog::variants($product, $this->lang));

        $this->out[] = [
            'kind' => 'list',
            'body' => WhatsAppOrderTexts::say($this->lang, 'pick_variant', ['product' => WhatsAppOrderCatalog::productName($product, $this->lang)]),
            'button' => WhatsAppOrderTexts::say($this->lang, 'variants'),
            'rows' => array_slice($rows, 0, WhatsAppOrderReplies::ROWS_MAX),
        ];
    }

    private function askQty(): void
    {
        $product = $this->draftProduct();

        $this->out[] = [
            'kind' => 'buttons',
            'body' => WhatsAppOrderTexts::say($this->lang, 'pick_qty', [
                'product' => $product ? WhatsAppOrderCatalog::productName($product, $this->lang) : '',
                'max' => self::MAX_QTY,
            ]),
            'buttons' => [[$this->id('q:1'), '1'], [$this->id('q:2'), '2'], [$this->id('q:3'), '3']],
        ];
    }

    private function askAddons(): void
    {
        $product = $this->draftProduct();

        if (! $product) {
            $this->go('category');

            return;
        }

        $addons = WhatsAppOrderCatalog::addons($this->bid, $product, $this->lang);
        $chosen = collect((array) ($this->ctx('draft')['addons'] ?? []))->keyBy('id');
        $names = collect($addons)->keyBy('id');

        $body = WhatsAppOrderTexts::say($this->lang, 'pick_addons', ['product' => WhatsAppOrderCatalog::productName($product, $this->lang)]);

        if ($chosen->isNotEmpty()) {
            $body .= "\n".WhatsAppOrderTexts::say($this->lang, 'addons_chosen', [
                'list' => $chosen->map(fn ($a) => ($names[$a['id']]['name'] ?? '').' ×'.$a['qty'])->implode('، '),
            ]);
        }

        $rows = array_map(fn ($a) => [$this->id('a:'.$a['id']), $a['name'], '+'.$this->money($a['price'])], $addons);

        $this->out[] = [
            'kind' => 'list',
            'body' => $body,
            'button' => WhatsAppOrderTexts::say($this->lang, 'addons'),
            'rows' => array_merge(
                [[$this->id('done'), WhatsAppOrderTexts::say($this->lang, 'addons_done'), WhatsAppOrderTexts::say($this->lang, 'addons_done_hint')]],
                array_slice($rows, 0, WhatsAppOrderReplies::ROWS_MAX - 1),
            ),
        ];
    }

    private function askCart(): void
    {
        $body = WhatsAppOrderCheckout::cartText($this->business, $this->cart(), $this->lang);

        $this->out[] = [
            'kind' => 'buttons',
            'body' => $body,
            'buttons' => $this->cart() === []
                ? [[$this->id('add'), WhatsAppOrderTexts::say($this->lang, 'add_more')]]
                : [
                    [$this->id('add'), WhatsAppOrderTexts::say($this->lang, 'add_more')],
                    [$this->id('checkout'), WhatsAppOrderTexts::say($this->lang, 'to_checkout')],
                    [$this->id('empty'), WhatsAppOrderTexts::say($this->lang, 'empty_cart')],
                ],
        ];
    }

    private function askArea(): void
    {
        $areas = $this->areas();

        if ($areas === []) {
            $this->out[] = CheckoutFields::requires($this->bid, 'area')
                ? ['kind' => 'text', 'body' => WhatsAppOrderTexts::say($this->lang, 'ask_area')]
                : ['kind' => 'buttons', 'body' => WhatsAppOrderTexts::say($this->lang, 'ask_area'), 'buttons' => [[$this->id('skip'), WhatsAppOrderTexts::say($this->lang, 'skip')]]];

            return;
        }

        $rows = [];
        foreach ($areas as $i => $area) {
            $rows[] = [$this->id('ar:'.$i), $area, null];
        }

        $this->out[] = [
            'kind' => 'list',
            'body' => WhatsAppOrderTexts::say($this->lang, 'pick_area'),
            'button' => WhatsAppOrderTexts::say($this->lang, 'areas'),
            'rows' => $this->paged($rows, []),
        ];
    }

    private function askAddress(): void
    {
        $key = EnglishCheckout::on($this->bid) ? 'ask_address_en' : 'ask_address';

        $this->out[] = CheckoutFields::requires($this->bid, 'address')
            ? ['kind' => 'text', 'body' => WhatsAppOrderTexts::say($this->lang, $key)]
            : ['kind' => 'buttons', 'body' => WhatsAppOrderTexts::say($this->lang, $key), 'buttons' => [[$this->id('skip'), WhatsAppOrderTexts::say($this->lang, 'skip')]]];
    }

    private function askDate(): void
    {
        $rows = array_map(fn ($d) => [$this->id('d:'.$d['date']), $d['label'], null], $this->dates());

        $this->out[] = [
            'kind' => 'list',
            'body' => WhatsAppOrderTexts::say($this->lang, 'pick_date'),
            'button' => WhatsAppOrderTexts::say($this->lang, 'dates'),
            'rows' => $this->paged($rows, []),
        ];
    }

    private function askSlot(): void
    {
        $slots = $this->slots();

        if ($slots === []) {
            $this->detail(['date' => null]);
            $this->go('date');

            return;
        }

        $date = (string) ($this->details()['date'] ?? '');
        $rows = array_map(fn ($s) => [$this->id('s:'.$s['id']), $s['label'], $s['hint'] ?? null], $slots);

        $this->out[] = [
            'kind' => 'list',
            'body' => WhatsAppOrderTexts::say($this->lang, 'pick_slot', ['date' => $date !== '' ? ShopClock::day(ShopClock::date($date), $this->lang) : '']),
            'button' => WhatsAppOrderTexts::say($this->lang, 'slots'),
            'rows' => array_slice($rows, 0, WhatsAppOrderReplies::ROWS_MAX),
        ];
    }

    private function askBranch(): void
    {
        $rows = StoreBranches::pickupChoices($this->bid)
            ->map(fn ($b) => [$this->id('b:'.$b->id), (string) $b->name, filled($b->address ?? null) ? (string) $b->address : null])
            ->values()->all();

        $this->out[] = [
            'kind' => 'list',
            'body' => WhatsAppOrderTexts::say($this->lang, 'pick_branch'),
            'button' => WhatsAppOrderTexts::say($this->lang, 'branches'),
            'rows' => array_slice($rows, 0, WhatsAppOrderReplies::ROWS_MAX),
        ];
    }

    private function askOccasion(): void
    {
        $rows = array_map(fn ($o) => [$this->id('oc:'.$o['value']), __((string) FlowerOrder::OCCASION_LABELS[$o['value']], [], $this->lang), null], GiftOrders::occasionOptions());

        $this->out[] = [
            'kind' => 'list',
            'body' => WhatsAppOrderTexts::say($this->lang, 'pick_occasion'),
            'button' => WhatsAppOrderTexts::say($this->lang, 'occasions'),
            'rows' => array_merge(
                [[$this->id('none'), WhatsAppOrderTexts::say($this->lang, 'no_occasion'), null]],
                array_slice($rows, 0, WhatsAppOrderReplies::ROWS_MAX - 1),
            ),
        ];
    }

    /* ═══════════ مساعدات ═══════════ */

    /**
     * صفحةٌ من قائمةٍ أطولَ من عشرة — والثابتُ (رجوع، إتمام) في أعلاها دائمًا.
     *
     * @param  list<array>  $rows
     * @param  list<array>  $fixed
     * @return list<array>
     */
    private function paged(array $rows, array $fixed): array
    {
        $room = WhatsAppOrderReplies::ROWS_MAX - count($fixed);
        $page = (int) ($this->ctx('page') ?? 0);

        // وصفحةٌ تحتاج «المزيد» تخسر صفًّا له
        $per = count($rows) > $room ? $room - 1 : $room;
        $pages = max(1, (int) ceil(count($rows) / max(1, $per)));
        $page = $page % $pages;
        $this->context(['page' => $page]);

        $slice = array_slice($rows, $page * $per, $per);

        if ($pages > 1) {
            $slice[] = [$this->id('more'), WhatsAppOrderTexts::say($this->lang, 'more'), WhatsAppOrderTexts::say($this->lang, 'more_hint').' ('.($page + 1).'/'.$pages.')'];
        }

        return array_merge($fixed, $slice);
    }

    private function text(string $key, array $replace = []): void
    {
        $this->out[] = ['kind' => 'text', 'body' => WhatsAppOrderTexts::say($this->lang, $key, $replace)];
    }

    /** @param  list<array{0: string, 1: string}>  $buttons  [القيمة، مفتاح النصّ] */
    private function buttons(string $key, array $buttons, array $replace = []): void
    {
        $this->out[] = [
            'kind' => 'buttons',
            'body' => WhatsAppOrderTexts::say($this->lang, $key, $replace),
            'buttons' => array_map(fn ($b) => [$this->id($b[0]), WhatsAppOrderTexts::say($this->lang, $b[1])], $buttons),
        ];
    }

    private function say(string $key, array $replace = []): void
    {
        $this->text($key, $replace);
    }

    /**
     * يحوّل المحادثةَ لموظّف — ويسكت الآليُّ فيها حتى يُنهى الطلب.
     *
     * و`quiet` لمن قيل له السببُ قبلها (نفد الكتالوج، طلبٌ ينتظر الدفع): لا جملتان بمعنًى واحد.
     */
    private function handoff(string $reason, bool $quiet = false): bool
    {
        $this->s->state = WhatsAppOrderSession::NEEDS_HUMAN;
        $this->s->handoff_reason = $reason;
        $this->s->step = null;

        if (! $quiet) {
            $this->say('handoff');
        }

        return true;
    }

    private function close(string $state): void
    {
        $this->s->state = $state;
        $this->s->step = null;
        $this->s->open_key = null;
        $this->s->closed_at = now();
    }

    private function restart(): void
    {
        $this->s->cart = [];
        $this->s->details = [];
        $this->s->total = null;
        $this->s->state = WhatsAppOrderSession::COLLECTING;
        $this->context(['draft' => null, 'page' => 0, 'category_id' => null]);
        $this->s->step = 'category';
        $this->ask(greet: true);
    }

    /** @return list<array<string, mixed>> */
    private function cart(): array
    {
        return array_values((array) ($this->s->cart ?? []));
    }

    /** @return array<string, mixed> */
    private function details(): array
    {
        return (array) ($this->s->details ?? []);
    }

    private function detail(array $values): void
    {
        $this->s->details = array_merge($this->details(), $values);
    }

    private function ctx(string $key): mixed
    {
        return ((array) ($this->s->context ?? []))[$key] ?? null;
    }

    private function context(array $values): void
    {
        $this->s->context = array_merge((array) ($this->s->context ?? []), $values);
    }

    private function draft(array $values): void
    {
        $this->context(['draft' => array_merge((array) ($this->ctx('draft') ?? []), $values)]);
    }

    private function draftProduct(): ?Product
    {
        $id = (int) ($this->ctx('draft')['product_id'] ?? 0);

        return $id > 0 ? WhatsAppOrderCatalog::product($this->bid, $id) : null;
    }

    private function fulfil(): string
    {
        return (string) ($this->details()['fulfil'] ?? FlowerOrder::DELIVERY);
    }

    /** @return list<string> */
    private function areas(): array
    {
        return array_values(array_filter(array_map('trim', (array) (WebCheckout::settings($this->bid)['areas'] ?? []))));
    }

    /** أيُسأل عن يوم؟ — جدولُ المواعيد إن فُتح، وإلّا حقلُ الموعد كما ضبطه المتجر */
    private function asksDate(): bool
    {
        return DeliverySchedule::on($this->bid) || CheckoutFields::shows($this->bid, 'date');
    }

    /**
     * الأيّامُ المتاحة — من جدول المواعيد بقواعده (الإغلاق، المهلة، آخرُ وقتٍ للحجز)،
     * وإلّا الأيّامُ من اليوم حتى أقصى ما يقبله المتجر.
     *
     * @return list<array{date: string, label: string}>
     */
    private function dates(): array
    {
        if (DeliverySchedule::on($this->bid)) {
            return array_map(fn ($d) => ['date' => $d['date'], 'label' => $d['label']], DeliverySchedule::forPage($this->bid, [$this->fulfil()], $this->lang)[$this->fulfil()] ?? []);
        }

        $today = ShopClock::now()->startOfDay();
        $out = [];

        for ($i = 0; $i <= CheckoutFields::maxDays($this->bid) && $i < 30; $i++) {
            $day = $today->addDays($i);
            $out[] = ['date' => $day->format('Y-m-d'), 'label' => ShopClock::day($day, $this->lang)];
        }

        return $out;
    }

    /** @return list<array{id: string, label: string, hint?: ?string}> */
    private function slots(): array
    {
        $date = (string) ($this->details()['date'] ?? '');

        if (DeliverySchedule::on($this->bid)) {
            foreach (DeliverySchedule::forPage($this->bid, [$this->fulfil()], $this->lang)[$this->fulfil()] ?? [] as $day) {
                if ($day['date'] === $date) {
                    return array_map(fn ($s) => ['id' => (string) $s['id'], 'label' => (string) $s['label'], 'hint' => mb_strlen((string) $s['label']) > WhatsAppOrderReplies::ROW_TITLE_MAX ? (string) $s['label'] : null], $day['slots']);
                }
            }

            return [];
        }

        if (! CheckoutFields::shows($this->bid, 'slot')) {
            return [];
        }

        $out = [];
        foreach ((array) (WebCheckout::settings($this->bid)['slots'] ?? []) as $i => $slot) {
            if (filled($slot)) {
                $out[] = ['id' => (string) $i, 'label' => (string) $slot, 'hint' => null];
            }
        }

        return $out;
    }

    /** اسمُ المشتري من ملفّه — إن كان ملفًّا واحدًا بعينه، وبالإنجليزيّة لمتجرٍ يطلبها */
    private function knownName(): ?string
    {
        $customer = $this->s->customer_id ? Customer::where('business_id', $this->bid)->find($this->s->customer_id) : null;
        $name = trim((string) ($customer?->name ?? ''));

        if ($name === '' || mb_strlen($name) < 2) {
            return null;
        }

        if (EnglishCheckout::on($this->bid) && ! preg_match(EnglishCheckout::NAME, $name)) {
            $english = trim((string) ($customer?->name_en ?? ''));

            return $english !== '' && preg_match(EnglishCheckout::NAME, $english) ? $english : null;
        }

        return mb_substr($name, 0, self::NAME_MAX);
    }

    /** اسمٌ مكتوب — أو `null` وقد قيل للزبون ما ينقصه */
    private function cleanName(string $text): ?string
    {
        $name = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);

        if (mb_strlen($name) < 2) {
            $this->say('too_short');
            $this->ask();

            return null;
        }

        if (EnglishCheckout::on($this->bid) && ! preg_match(EnglishCheckout::NAME, $name)) {
            $this->say('english_only');
            $this->ask();

            return null;
        }

        return mb_substr($name, 0, self::NAME_MAX);
    }

    /** أتكشف رسالةُ البطاقة اسمَ من أخفاه أو رقمَه؟ — قاعدةُ التجهيز نفسُها (`CardIdentity::reveals`) */
    private function cardReveals(): bool
    {
        $d = $this->details();

        if (! ($d['hide_sender'] ?? false) || blank($d['card_message'] ?? null)) {
            return false;
        }

        return CardIdentity::reveals((string) $d['card_message'], (string) ($d['name'] ?? ''), (string) $this->s->customer_phone, (string) ($d['recipient_name'] ?? ''))
            || CardIdentity::reveals((string) $d['card_message'], '', WhatsAppOrders::localPhone((string) $this->s->customer_phone));
    }

    private function money(float $value): string
    {
        return Money::format($value, Storefront::currency($this->business));
    }

    private function shopName(): string
    {
        return (string) ($this->lang === 'en' && filled($this->business->name_en ?? null) ? $this->business->name_en : $this->business->name);
    }
}
