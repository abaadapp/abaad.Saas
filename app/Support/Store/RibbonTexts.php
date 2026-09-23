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
        'bannerKicker' => 'المناسبات والهدايا', 'bannerTitle' => 'لكل مناسبة باقة تليق بها', 'bannerSub' => 'أعياد ميلاد، تخرّج، خطوبة، أو شكر بسيط. نجهّز الباقة مع كرت هدية بخط أنيق ونوصلها في الوقت الذي تحدده.', 'bannerBtn' => 'تسوّق الهدايا',
        'aboutKicker' => 'عن المتجر', 'reviewsTitle' => 'آراء عملائنا',
        'shopTitle' => 'جميع المنتجات', 'shopSub' => 'اختر باقتك، حدّد الحجم وموعد التوصيل، وأتمّ الطلب في صفحة واحدة.', 'all' => 'الكل', 'noProducts' => 'لا منتجات هنا بعد.',
        'back' => '← الرجوع للمنتجات', 'size' => 'الحجم', 'add' => 'أضف إلى السلة', 'added' => 'تمت الإضافة ✓', 'soldOut' => 'نفد من المتجر', 'from' => 'من',
        'cartTitle' => 'السلة', 'cartEmpty' => 'سلتك فارغة', 'continueShopping' => 'متابعة التسوّق', 'remove' => 'حذف', 'subtotal' => 'المجموع الفرعي', 'toCheckout' => 'إتمام الطلب',
        'checkoutTitle' => 'إتمام الطلب', 's1' => 'بياناتك', 's2' => 'التوصيل', 's3' => 'كرت الهدية', 's4' => 'الدفع',
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
        'bankNote' => 'بعد تأكيد الطلب تظهر لك بيانات الحساب البنكي، ويُجهَّز الطلب بعد استلام التحويل.',
        'summary' => 'ملخص الطلب', 'promo' => 'كود الخصم', 'apply' => 'تطبيق', 'shipping' => 'التوصيل', 'discount' => 'الخصم', 'tax' => 'الضريبة', 'total' => 'الإجمالي', 'free' => 'مجاني', 'freeOver' => 'مجاني فوق :amount',
        'place' => 'تأكيد الطلب', 'date' => 'الموعد', 'slot' => 'وقت التسليم', 'errReq' => 'يرجى إكمال الحقول المحددة', 'errEmpty' => 'السلة فارغة', 'closed' => 'المتجر لا يستقبل طلبات من الموقع الآن — تواصل معنا.',
        'thanks' => 'شكراً لك، تم استلام طلبك', 'orderNo' => 'رقم الطلب', 'bankDetails' => 'بيانات الحساب البنكي', 'pay' => 'الدفع',
        'footShop' => 'تسوّق', 'footContact' => 'تواصل معنا', 'footHours' => 'ساعات العمل',
        'newsTitle' => 'اشترك في عروضنا', 'qty' => 'الكمية',
    ];

    private const EN = [
        'search' => 'Search', 'langBtn' => 'عربي', 'cart' => 'Cart',
        'heroKicker' => 'RIBBON LOUNGE', 'heroTitle' => 'Flower bouquets, delivered', 'heroSub' => 'Pick a bouquet, choose a size and delivery time, and order on a single page.',
        'shopNow' => 'Shop now', 'explore' => 'Explore collections',
        'payingTitle' => 'Confirming your payment', 'payingWait' => 'Your payment has left the bank and we are waiting for confirmation. Do not pay again — your receipt will appear here shortly.', 'payingPaid' => 'Your payment arrived, and we are preparing your receipt.', 'payingNote' => 'If this takes more than a minute, contact us with the time of payment.',
        'catsTitle' => 'Shop by category', 'viewAll' => 'View all', 'bestTitle' => 'Best sellers', 'pickedTitle' => 'Our picks', 'newTitle' => 'New arrivals',
        'bannerKicker' => 'OCCASIONS & GIFTS', 'bannerTitle' => 'A bouquet for every occasion', 'bannerSub' => 'Birthdays, graduations, engagements, or a simple thank-you. We prepare the bouquet with a hand-written card and deliver at the time you choose.', 'bannerBtn' => 'Shop gifts',
        'aboutKicker' => 'ABOUT US', 'reviewsTitle' => 'What customers say',
        'shopTitle' => 'All products', 'shopSub' => 'Pick a bouquet, choose a size and delivery time, and order on a single page.', 'all' => 'All', 'noProducts' => 'No products here yet.',
        'back' => '← Back to products', 'size' => 'Size', 'add' => 'Add to cart', 'added' => 'Added ✓', 'soldOut' => 'Sold out', 'from' => 'from',
        'cartTitle' => 'Your cart', 'cartEmpty' => 'Your cart is empty', 'continueShopping' => 'Continue shopping', 'remove' => 'Remove', 'subtotal' => 'Subtotal', 'toCheckout' => 'Checkout',
        'checkoutTitle' => 'Checkout', 's1' => 'Your details', 's2' => 'Delivery', 's3' => 'Gift card', 's4' => 'Payment',
        'fName' => 'Full name', 'fPhone' => 'Phone number', 'fArea' => 'Area', 'fAddress' => 'Full address (area, street, house no.)',
        'delivery' => 'Deliver to address', 'pickup' => 'Pick up in store', 'pickupAddr' => 'Pick up in store',
        'fCard' => 'Message for the gift card (optional)', 'cardHint' => 'Up to 500 characters',
        'forOther' => 'This order is a gift for someone else', 'fRecipient' => 'Recipient name', 'fRecipientPhone' => 'Recipient phone',
        'addCard' => 'Add a gift card', 'cardAlign' => 'Text alignment', 'alignRight' => 'Right', 'alignCenter' => 'Center', 'alignLeft' => 'Left',
        'cardWay' => 'How do you want the card?', 'cardWayText' => 'I will write the message', 'cardWayFile' => 'I will upload a file',
        'cardFile' => 'Attach a file', 'cardFileHint' => 'Image or PDF · up to 5 MB', 'cardFileWait' => 'Uploading…', 'cardFileErr' => 'Could not upload the file',
        'cardPreview' => 'As it appears on the card', 'remove' => 'Remove',
        'payCod' => 'Cash on delivery', 'payCodNote' => 'Pay when you receive it', 'payBank' => 'Bank transfer', 'payBankNote' => 'Account details shown to you',
        'bankNote' => 'After confirming, the bank details are shown to you. The order is prepared once the transfer is received.',
        'summary' => 'Order summary', 'promo' => 'Promo code', 'apply' => 'Apply', 'shipping' => 'Delivery', 'discount' => 'Discount', 'tax' => 'VAT', 'total' => 'Total', 'free' => 'Free', 'freeOver' => 'Free over :amount',
        'place' => 'Place order', 'date' => 'Date', 'slot' => 'Delivery time', 'errReq' => 'Please complete the highlighted fields', 'errEmpty' => 'Cart is empty', 'closed' => 'The store is not taking online orders right now — contact us.',
        'thanks' => 'Thank you, your order is received', 'orderNo' => 'Order no.', 'bankDetails' => 'Bank account details', 'pay' => 'Payment',
        'footShop' => 'Shop', 'footContact' => 'Contact', 'footHours' => 'Opening hours',
        'newsTitle' => 'Join our offers list', 'qty' => 'Quantity',
    ];

    /** @return array<string, string> */
    public static function for(string $lang): array
    {
        return $lang === 'en' ? self::EN : self::AR;
    }
}
