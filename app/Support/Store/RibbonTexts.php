<?php

namespace App\Support\Store;

/**
 * نصوصُ واجهة RIBBON — بلغتين، كما كُتبت في تصميم صاحبها.
 *
 * نصوصُ الواجهة لا نصوصُ المتجر: اسمُ المتجر ونبذتُه وهاتفُه وعنوانُه
 * وساعاتُه ورسومُه تُقرأ من إعداداته لا من هنا. وهذه أزرارٌ وعناوينُ
 * ثابتة، وما كان في التصميم عن «الخوير» و«ساعتين» صار إعدادًا يكتبه هو.
 */
final class RibbonTexts
{
    private const AR = [
        'search' => 'ابحث هنا', 'langBtn' => 'EN', 'cart' => 'السلة',
        'heroKicker' => 'RIBBON LOUNGE', 'heroTitle' => 'باقات ورد للتوصيل', 'heroSub' => 'اختر باقتك، حدّد الحجم وموعد التوصيل، وأتمّ الطلب في صفحة واحدة.',
        'shopNow' => 'تسوّق الآن', 'explore' => 'استكشف مجموعاتنا',
        'payingTitle' => 'نؤكّد دفعتك', 'payingWait' => 'خرجت دفعتُك من البنك ونحن ننتظر تأكيدها. لا تُعد الدفع — ستظهر فاتورتك هنا بعد قليل.', 'payingPaid' => 'وصلت دفعتُك، ونجهّز فاتورتك الآن.', 'payingNote' => 'إن طال الأمر أكثر من دقيقة فاتصل بنا ومعك وقتُ الدفع.',
        'catsTitle' => 'تسوّق حسب الفئة', 'viewAll' => 'عرض الكل', 'bestTitle' => 'الأكثر مبيعاً', 'pickedTitle' => 'مختاراتنا', 'newTitle' => 'وصل حديثاً',
        // اسمُ «اختيارات RIBBON» حين لا يكتب صاحبُه عنوانًا للّغة — انظر `RibbonPicks::title`
        'picksTitle' => 'اختيارات RIBBON',
        'bannerKicker' => 'المناسبات والهدايا', 'bannerTitle' => 'لكل مناسبة باقة تليق بها', 'bannerSub' => 'أعياد ميلاد، تخرّج، خطوبة، أو شكر بسيط. نجهّز الباقة مع كرت هدية بخط أنيق ونوصلها في الوقت الذي تحدده.', 'bannerBtn' => 'تسوّق الهدايا',
        'aboutKicker' => 'عن المتجر', 'reviewsTitle' => 'آراء عملائنا',
        // آراءُ الصنف على صفحته — انظر `ProductReviews`
        'productReviews' => 'تقييمات المنتج', 'reviewsCount' => 'عدد التقييمات: :n', 'ratingOutOf' => 'التقييم :avg من 5',
        'verifiedPurchase' => 'شراء موثّق', 'storeReply' => 'ردّ المتجر', 'starsLabel' => ':n نجوم',
        'shopTitle' => 'جميع المنتجات', 'shopSub' => 'اختر باقتك، حدّد الحجم وموعد التوصيل، وأتمّ الطلب في صفحة واحدة.', 'all' => 'الكل', 'noProducts' => 'لا منتجات هنا بعد.', 'noMatch' => 'لا توجد منتجات تطابق بحثك.', 'noInCategory' => 'لا توجد منتجات في هذا القسم.',
        'back' => '← الرجوع للمنتجات', 'size' => 'الحجم', 'add' => 'أضف إلى السلة', 'added' => 'تمت الإضافة ✓', 'soldOut' => 'نفد من المتجر', 'from' => 'من',
        'cartTitle' => 'السلة', 'cartEmpty' => 'سلتك فارغة', 'continueShopping' => 'متابعة التسوّق', 'remove' => 'حذف', 'subtotal' => 'المجموع الفرعي', 'toCheckout' => 'إتمام الطلب',
        'checkoutTitle' => 'إتمام الطلب', 's1' => 'بياناتك', 's2' => 'التوصيل', 's3' => 'بيانات المستلم', 's3card' => 'كرت الهدية', 's4' => 'الدفع',
        'fName' => 'الاسم الكامل', 'fPhone' => 'رقم الهاتف', 'fArea' => 'المنطقة', 'fAddress' => 'العنوان بالتفصيل (المنطقة، الشارع، رقم المنزل)',
        'delivery' => 'توصيل للعنوان', 'pickup' => 'استلام من المحل', 'pickupAddr' => 'الاستلام من المحل',
        'fCard' => 'رسالة تُكتب على كرت الهدية (اختياري)', 'cardHint' => 'حتى 500 حرف',
        // المستلِمُ غيرُ المشتري — وأكثرُ الطلبات تُشترى لغيرِ مشتريها
        'forOther' => 'الطلب هدية لشخصٍ آخر', 'fRecipient' => 'اسم المستلِم', 'fRecipientPhone' => 'هاتف المستلِم',
        // والكرتُ صنفٌ يُباع — فيُقال ثمنُه حيث يُختار، لا في الفاتورة وحدها
        'addCard' => 'أضف كرت هدية', 'cardAlign' => 'ترتيب النص', 'alignRight' => 'يمين', 'alignCenter' => 'وسط', 'alignLeft' => 'يسار',
        'cardWay' => 'كيف تريد الكرت؟', 'cardWayText' => 'أكتب الرسالة', 'cardWayFile' => 'أرفع ملفًّا',
        'cardFile' => 'أرفق ملفًّا', 'cardFileHint' => 'صورة أو PDF · حتى 5 ميغابايت', 'cardFileWait' => 'يُرفع…', 'cardFileErr' => 'تعذّر رفع الملف',
        'cardPreview' => 'كما يظهر على الكرت', 'remove' => 'إزالة',
        'payCod' => 'الدفع عند الاستلام', 'payCodNote' => 'نقدًا عند التسليم', 'payBank' => 'تحويل بنكي', 'payBankNote' => 'تصلك بيانات الحساب',
        'payCard' => 'الدفع بالبطاقة', 'payCardNote' => 'فيزا أو ماستركارد',
        // إيصالُ الدفع في صفحة الشكر — انظر `PaidReceipt`
        'viewReceipt' => 'عرض الفاتورة',
        'bankNote' => 'بعد تأكيد الطلب تظهر لك بيانات الحساب البنكي، ويُجهَّز الطلب بعد استلام التحويل.',
        'summary' => 'ملخص الطلب', 'promo' => 'كود الخصم', 'apply' => 'تطبيق', 'shipping' => 'التوصيل', 'discount' => 'الخصم', 'tax' => 'الضريبة', 'total' => 'الإجمالي', 'free' => 'مجاني', 'freeOver' => 'مجاني فوق :amount',
        'place' => 'تأكيد الطلب', 'date' => 'الموعد', 'slot' => 'وقت التسليم', 'errReq' => 'يرجى إكمال الحقول المحددة', 'errEmpty' => 'السلة فارغة', 'errBusy' => 'محاولاتٌ كثيرة — انتظر دقيقةً ثمّ أعد المحاولة.', 'errStale' => 'انتهت جلستُك — أعد تحميل الصفحة ثمّ أكّد طلبك.', 'errServer' => 'تعذّر إتمام الطلب الآن — أعد المحاولة، أو تواصل معنا.', 'closed' => 'المتجر لا يستقبل طلبات من الموقع الآن — تواصل معنا.',
        /*
         * ═══ ونصُّ الموافقة الصريحة على سعرٍ تبدّل ═══
         *
         * الكودُ قد ينتهي بين لحظةِ التسعير ولحظةِ التأكيد — فيُقال السببُ
         * والسعرُ الجديد، ويُطلب إقرارٌ بضغطةٍ لا بإعادةِ إرسالٍ صامتة. ومن
         * لم يوافق لا يُنشأ له طلبٌ بالسعر الجديد.
         */
        'priceChanged' => 'تغيّر إجمالي طلبك', 'agreeNew' => 'أوافق وأكمل الطلب', 'keepBrowsing' => 'تراجَع',
        'thanks' => 'شكراً لك، تم استلام طلبك', 'orderNo' => 'رقم الطلب', 'bankDetails' => 'بيانات الحساب البنكي', 'pay' => 'الدفع',
        'footShop' => 'تسوّق', 'footContact' => 'تواصل معنا', 'footHours' => 'ساعات العمل',
        'footPages' => 'الموقع',
        // أسماءُ الصفحات في القائمة — انظر `StoreNav::LABELS`
        'navHome' => 'الرئيسية', 'navShop' => 'المتجر', 'navAbout' => 'من نحن', 'navContact' => 'تواصل معنا',
        // صفُّ خيارات المتجر في الترويسة — انظر `StoreHeader::shopOptions`
        'allProducts' => 'كل المنتجات', 'bestSellers' => 'الأكثر مبيعًا', 'shopOptions' => 'تصفّح المتجر', 'noBest' => 'لا توجد منتجات مباعة بعد.',
        'aboutTitle' => 'من نحن', 'contactTitle' => 'تواصل معنا',
        'contactSub' => 'نردّ على رسائلك في ساعات العمل — اختر ما يناسبك.',
        'cPhone' => 'الهاتف', 'cWhatsapp' => 'واتساب', 'cEmail' => 'البريد الإلكتروني', 'cAddress' => 'العنوان', 'cHours' => 'ساعات العمل',
        'openMap' => 'افتح في الخرائط', 'callNow' => 'اتصل الآن', 'waNow' => 'راسلنا على واتساب',
        'newsTitle' => 'اشترك في عروضنا', 'qty' => 'الكمية',
        // «أضف مع طلبك» في صفحة الصنف — انظر `RibbonUpsells`
        'upsellTitle' => 'أضف مع طلبك', 'upsellChoose' => 'اختر خيارًا لهذه الإضافة قبل الإضافة إلى السلة',
        // رسالةُ الكرت بلا ثمن — انظر `GiftCard::messageOnly`
        'addCardMessage' => 'أضف رسالة على كرت الهدية', 'cardMessageHint' => 'اكتب رسالتك التي تريد إرفاقها مع الطلب',
        // معرضُ صفحة الصنف، وكرتُ الهدية صنفًا — انظر `GiftCardProduct`
        'photos' => 'صور المنتج', 'showPhoto' => 'اعرض الصورة :n من :total',
        // والرسالةُ اختياريّة: كرتٌ بلا رسالة يُشترى ويُحاسَب كما هو
        'giftCardMessage' => 'رسالة كرت الهدية — اختياري',
        'cartCardMessage' => 'رسالة الكرت:',
        // ميزةُ الإهداء — انظر `GiftOrders`
        'giftOrder' => 'هذا الطلب هدية', 'occasionOptional' => 'المناسبة — اختياري', 'enterOccasion' => 'اكتب المناسبة',
        'hideSender' => 'لا تذكر اسمي للمستلم',
        // وعنوانُ المستلِم يُكتب في «التوصيل» — سطرٌ يقوله لمن فتح الهديّة
        'giftAddressHint' => 'اكتب عنوان المستلم في قسم التوصيل أعلاه.',
        // زرُّ واتساب العائم — انظر `storefront.ribbon_floating_whatsapp_businesses`
        'waFloat' => 'تواصل معنا عبر واتساب', 'waFloatText' => 'السلام عليكم، أحتاج مساعدة بخصوص طلبي من متجر RIBBON.',
        // ملاحظةُ المنتج وملاحظاتُ الطلب — العنوانُ بلغة الموقع والنصُّ بالإنجليزيّة (`OrderExtras`)
        'productNote' => 'ملاحظة المنتج (اختياري)', 'productNotePh' => 'اكتب ملاحظتك بالإنجليزية — مثال: No plastic wrapping',
        'cartProductNote' => 'ملاحظة المنتج:',
        'orderNotes' => 'ملاحظات الطلب (اختياري)', 'orderNotesPh' => 'اكتب ملاحظتك بالإنجليزية — مثال: Please call before delivery',
        'noteEnglishOnly' => 'يرجى كتابة الملاحظة باللغة الإنجليزية فقط.', 'noteTooLong' => 'الملاحظة أطول من :max حرفًا.',
    ];

    private const EN = [
        'search' => 'Search', 'langBtn' => 'عربي', 'cart' => 'Cart',
        'heroKicker' => 'RIBBON LOUNGE', 'heroTitle' => 'Flower bouquets, delivered', 'heroSub' => 'Pick a bouquet, choose a size and delivery time, and order on a single page.',
        'shopNow' => 'Shop now', 'explore' => 'Explore collections',
        'payingTitle' => 'Confirming your payment', 'payingWait' => 'Your payment has left the bank and we are waiting for confirmation. Do not pay again — your receipt will appear here shortly.', 'payingPaid' => 'Your payment arrived, and we are preparing your receipt.', 'payingNote' => 'If this takes more than a minute, contact us with the time of payment.',
        'catsTitle' => 'Shop by category', 'viewAll' => 'View all', 'bestTitle' => 'Best sellers', 'pickedTitle' => 'Our picks', 'newTitle' => 'New arrivals',
        'picksTitle' => 'RIBBON picks',
        'bannerKicker' => 'OCCASIONS & GIFTS', 'bannerTitle' => 'A bouquet for every occasion', 'bannerSub' => 'Birthdays, graduations, engagements, or a simple thank-you. We prepare the bouquet with a hand-written card and deliver at the time you choose.', 'bannerBtn' => 'Shop gifts',
        'aboutKicker' => 'ABOUT US', 'reviewsTitle' => 'What customers say',
        'productReviews' => 'Product reviews', 'reviewsCount' => 'Reviews: :n', 'ratingOutOf' => 'Rated :avg out of 5',
        'verifiedPurchase' => 'Verified purchase', 'storeReply' => 'Store reply', 'starsLabel' => ':n stars',
        'shopTitle' => 'All products', 'shopSub' => 'Pick a bouquet, choose a size and delivery time, and order on a single page.', 'all' => 'All', 'noProducts' => 'No products here yet.', 'noMatch' => 'No products match your search.', 'noInCategory' => 'No products in this category.',
        'back' => '← Back to products', 'size' => 'Size', 'add' => 'Add to cart', 'added' => 'Added ✓', 'soldOut' => 'Sold out', 'from' => 'from',
        'cartTitle' => 'Your cart', 'cartEmpty' => 'Your cart is empty', 'continueShopping' => 'Continue shopping', 'remove' => 'Remove', 'subtotal' => 'Subtotal', 'toCheckout' => 'Checkout',
        'checkoutTitle' => 'Checkout', 's1' => 'Your details', 's2' => 'Delivery', 's3' => 'Recipient details', 's3card' => 'Gift card', 's4' => 'Payment',
        'fName' => 'Full name', 'fPhone' => 'Phone number', 'fArea' => 'Area', 'fAddress' => 'Full address (area, street, house no.)',
        'delivery' => 'Deliver to address', 'pickup' => 'Pick up in store', 'pickupAddr' => 'Pick up in store',
        'fCard' => 'Message for the gift card (optional)', 'cardHint' => 'Up to 500 characters',
        'forOther' => 'This order is a gift for someone else', 'fRecipient' => 'Recipient name', 'fRecipientPhone' => 'Recipient phone',
        'addCard' => 'Add a gift card', 'cardAlign' => 'Text alignment', 'alignRight' => 'Right', 'alignCenter' => 'Center', 'alignLeft' => 'Left',
        'cardWay' => 'How do you want the card?', 'cardWayText' => 'I will write the message', 'cardWayFile' => 'I will upload a file',
        'cardFile' => 'Attach a file', 'cardFileHint' => 'Image or PDF · up to 5 MB', 'cardFileWait' => 'Uploading…', 'cardFileErr' => 'Could not upload the file',
        'cardPreview' => 'As it appears on the card', 'remove' => 'Remove',
        'payCod' => 'Cash on delivery', 'payCodNote' => 'Pay when you receive it', 'payBank' => 'Bank transfer', 'payBankNote' => 'Account details shown to you',
        'payCard' => 'Pay by card', 'payCardNote' => 'Visa or Mastercard',
        'viewReceipt' => 'View invoice',
        'bankNote' => 'After confirming, the bank details are shown to you. The order is prepared once the transfer is received.',
        'summary' => 'Order summary', 'promo' => 'Promo code', 'apply' => 'Apply', 'shipping' => 'Delivery', 'discount' => 'Discount', 'tax' => 'VAT', 'total' => 'Total', 'free' => 'Free', 'freeOver' => 'Free over :amount',
        'place' => 'Place order', 'date' => 'Date', 'slot' => 'Delivery time', 'errReq' => 'Please complete the highlighted fields', 'errEmpty' => 'Cart is empty', 'errBusy' => 'Too many attempts — wait a minute and try again.', 'errStale' => 'Your session expired — reload the page, then confirm your order.', 'errServer' => 'We could not complete your order — try again, or contact us.', 'closed' => 'The store is not taking online orders right now — contact us.',
        'priceChanged' => 'Your order total has changed', 'agreeNew' => 'I agree — place the order', 'keepBrowsing' => 'Go back',
        'thanks' => 'Thank you, your order is received', 'orderNo' => 'Order no.', 'bankDetails' => 'Bank account details', 'pay' => 'Payment',
        'footShop' => 'Shop', 'footContact' => 'Contact', 'footHours' => 'Opening hours',
        'footPages' => 'Site',
        'navHome' => 'Home', 'navShop' => 'Shop', 'navAbout' => 'About', 'navContact' => 'Contact',
        'allProducts' => 'All Products', 'bestSellers' => 'Best Sellers', 'shopOptions' => 'Browse the shop', 'noBest' => 'No best sellers yet.',
        'aboutTitle' => 'About us', 'contactTitle' => 'Contact us',
        'contactSub' => 'We reply during opening hours — pick whichever suits you.',
        'cPhone' => 'Phone', 'cWhatsapp' => 'WhatsApp', 'cEmail' => 'Email', 'cAddress' => 'Address', 'cHours' => 'Opening hours',
        'openMap' => 'Open in Maps', 'callNow' => 'Call now', 'waNow' => 'Message us on WhatsApp',
        'newsTitle' => 'Join our offers list', 'qty' => 'Quantity',
        'upsellTitle' => 'Add to your order', 'upsellChoose' => 'Choose an option for this add-on before adding to cart',
        'addCardMessage' => 'Add a gift card message', 'cardMessageHint' => 'Write the message you want included with the order',
        'photos' => 'Product photos', 'showPhoto' => 'Show photo :n of :total',
        'giftCardMessage' => 'Gift card message — optional',
        'cartCardMessage' => 'Card message:',
        'giftOrder' => 'This order is a gift', 'occasionOptional' => 'Occasion — optional', 'enterOccasion' => 'Enter the occasion',
        'hideSender' => 'Do not reveal my name to the recipient',
        'giftAddressHint' => "Enter the recipient's address in the Delivery section above.",
        'waFloat' => 'Chat with us on WhatsApp', 'waFloatText' => 'Hello, I need help with my order from RIBBON.',
        'productNote' => 'Product note (optional)', 'productNotePh' => 'Write your note in English — e.g. No plastic wrapping',
        'cartProductNote' => 'Product note:',
        'orderNotes' => 'Order notes (optional)', 'orderNotesPh' => 'Write your note in English — e.g. Please call before delivery',
        'noteEnglishOnly' => 'Please enter your note in English only.', 'noteTooLong' => 'The note is longer than :max characters.',
    ];

    /** @return array<string, string> */
    public static function for(string $lang): array
    {
        return $lang === 'en' ? self::EN : self::AR;
    }

    /**
     * ما يُقال للزبون حين يُردّ بابٌ بحالةٍ ليست خطأَ حقلٍ في نموذجه.
     *
     * ═══ ولمَ تُبنى هنا لا في جافاسكربت الصفحة ═══
     *
     * لأنّ ما لا يُحرس ينكسر. وشرطٌ مكتوبٌ داخل وسمِ `‎<script>‎` في قالب
     * بليد لا يبلغه اختبار: يُحذف فرعٌ منه فلا يسقط شيء، ويبقى بابٌ يُغلق
     * في وجه الزبون بلا كلمة. فالقرارُ خريطةٌ يبنيها الخادم ويُسأل عنها،
     * والصفحةُ تقرأ منها بمفتاحٍ واحد.
     *
     * و٤١٩ أكثرُها وقوعًا: يفتح الزبون الإتمام، يذهب يسأل من يُهدي، يعود
     * بعد ساعةٍ وقد انتهت جلستُه. ولا يُقال له «أعد المحاولة» — تُعاد
     * المحاولةُ ألفًا ولا تنجح — بل «أعد تحميل الصفحة».
     *
     * @return array<string, string>
     */
    public static function doorSays(string $lang): array
    {
        $t = self::for($lang);

        return [
            // بابٌ بعدّاد: الانتظارُ وحده يُصلحه
            '429' => $t['errBusy'],
            // جلسةٌ انتهت أو رمزٌ بطَل: التحميلُ من جديد وحده يُصلحه
            '419' => $t['errStale'],
            '401' => $t['errStale'],
            '403' => $t['errStale'],
            // وما سوى ذلك — ٥٠٠ و٥٠٢ وانقطاعُ الشبكة وجوابٌ ليس JSON أصلًا
            '_' => $t['errServer'],
        ];
    }
}
