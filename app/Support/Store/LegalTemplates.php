<?php

namespace App\Support\Store;

/**
 * القوالبُ العامّة للصفحات القانونيّة — نقطةُ بدءٍ يراجعها صاحبُ المتجر ثمّ ينشرها.
 *
 * ═══ ما يُكتب فيها وما لا يُكتب ═══
 *
 * لا اسمَ متجرٍ ولا رقمَ ولا عنوانَ هنا: كلُّ ما يخصّ متجرًا رمزٌ بين قوسين
 * مزدوجين (`{{legal_name}}`) يُملأ من بياناته حين يُنشر (`StoreLegal::resolve`).
 * فالقالبُ واحدٌ للمتجر الأوّل والعاشر ألفًا، ولا تقع بياناتُ متجرٍ في صفحة غيره.
 *
 * وما لا يعرفه النظامُ ولا يصحّ أن يُخمَّن — سياسةُ الاسترجاع مثلًا — يُترك
 * بين `[[ ]]`، والنشرُ يُردّ ما بقي منه شيء. فلا يُنشر نصٌّ يدّعي التزامًا
 * لم يكتبه صاحبُه.
 *
 * ═══ وما يُذكر يتبع ما يعمل ═══
 *
 * الدفعُ بالبطاقة يُذكر لمن فُتحت له بوّابتُه، والطلبُ من الموقع لمن له سلّة،
 * وواتساب لمن له رقم، وأداةُ القياس لمن ربطها (`StoreLegal::features`).
 * والحذفُ يُوصف كما يقع في النظام: يدويًّا بيد صاحب المتجر، لا آليًّا.
 */
final class LegalTemplates
{
    /**
     * @param  array{orders: bool, card: bool, whatsapp: bool, analytics: bool}  $f
     */
    public static function for(string $kind, string $lang, array $f): string
    {
        $en = $lang === 'en';

        $lines = match ($kind) {
            StoreLegal::PRIVACY => $en ? self::privacyEn($f) : self::privacyAr($f),
            StoreLegal::DELETION => $en ? self::deletionEn($f) : self::deletionAr($f),
            StoreLegal::TERMS => $en ? self::termsEn($f) : self::termsAr($f),
        };

        return implode("\n", array_filter($lines, fn ($l) => $l !== null));
    }

    /** @return list<?string> */
    private static function privacyAr(array $f): array
    {
        return [
            'توضّح هذه السياسة كيف يجمع {{legal_name}}، صاحبُ متجر «{{store_name}}» ({{website}})، بياناتِك الشخصيّة ويستعملها ويحميها.',
            '',
            '## من المسؤول عن بياناتك',
            '- {{legal_name}} هو المسؤول عن بياناتك: يقرّر ما يُجمع ولماذا، ويتواصل معك بشأن طلباتك واستفساراتك.',
            '- يعمل الموقع على منصّة أبعاد (abaadapp.om)، وهي مزوّدُ الخدمة التقنيّة الذي يستضيف الموقع ويحفظ البيانات نيابةً عن المتجر، ولا يستعملها لأغراضه الخاصّة.',
            '',
            '## ما نجمعه',
            $f['orders'] ? '- بيانات الطلب التي تكتبها: الاسم، ورقم الهاتف، وعنوان التوصيل وموعده، والملاحظات أو رسائل الإهداء.' : null,
            '- ما تكتبه حين تتواصل معنا.',
            $f['whatsapp'] ? '- رقمك ورسائلك إن راسلتنا عبر واتساب.' : null,
            $f['analytics'] ? '- بيانات استخدامٍ عامّة عن زيارتك (الصفحات وطريقة الوصول إليها) عبر أداة قياس الزيارات Google Analytics.' : null,
            '- بيانات تقنيّة لازمة لتشغيل الموقع، مثل اللغة التي اخترتها ومحتوى السلّة المحفوظ في متصفّحك.',
            '',
            '## لماذا نستعملها',
            $f['orders'] ? '- لتنفيذ طلبك وتوصيله والتواصل معك بشأنه.' : null,
            '- للردّ على استفساراتك.',
            '- لإصدار الفواتير وحفظ السجلّات التي يلزمنا بها القانون.',
            'لا نبيع بياناتك الشخصيّة.',
            $f['card'] ? '' : null,
            $f['card'] ? '## الدفع الإلكترونيّ' : null,
            $f['card'] ? 'تتمّ المدفوعات بالبطاقة عبر بوّابة الدفع Paymob. تُدخل بيانات بطاقتك في صفحة البوّابة مباشرةً، ولا نطّلع على رقم بطاقتك ولا نحفظه، ويصلنا منها ما يثبت نجاح الدفع أو فشله.' : null,
            $f['whatsapp'] ? '' : null,
            $f['whatsapp'] ? '## واتساب' : null,
            $f['whatsapp'] ? 'إن راسلتنا عبر واتساب فإنّ رسائلك تمرّ عبر خدمة WhatsApp المملوكة لشركة Meta وتخضع أيضًا لسياسة الخصوصيّة الخاصّة بها. ونستعمل رقمك للردّ عليك وإرسال ما يخصّ طلبك.' : null,
            '',
            '## مع من نشاركها',
            '- منصّة أبعاد لاستضافة الموقع وتشغيله.',
            $f['card'] ? '- بوّابة الدفع Paymob لإتمام الدفع.' : null,
            $f['whatsapp'] ? '- خدمة WhatsApp (Meta) حين نتراسل عبرها.' : null,
            $f['orders'] ? '- من يوصل طلبك، بقدر ما يلزم للتوصيل.' : null,
            '- الجهات الرسميّة إن طلبها القانون.',
            '',
            '## مدّة الاحتفاظ',
            'نحتفظ ببيانات الطلبات والفواتير المدّةَ التي تفرضها الأنظمة المحاسبيّة والضريبيّة، وبما سواها ما دامت لازمةً للغرض الذي جُمعت له.',
            '',
            '## حقوقك',
            'لك أن تطلب الاطّلاع على بياناتك أو تصحيحها أو حذفها. طريقة طلب الحذف في صفحة «تعليمات حذف البيانات»: {{deletion_url}}',
            '',
            '## تواصل معنا',
            '{{contact}}',
        ];
    }

    /** @return list<?string> */
    private static function privacyEn(array $f): array
    {
        return [
            'This policy explains how {{legal_name}}, the owner of the "{{store_name}}" store ({{website}}), collects, uses and protects your personal data.',
            '',
            '## Who is responsible for your data',
            '- {{legal_name}} is responsible for your data: it decides what is collected and why, and contacts you about your orders and enquiries.',
            '- The website runs on the Abaad platform (abaadapp.om), the technical service provider that hosts the website and stores data on the store\'s behalf. Abaad does not use it for its own purposes.',
            '',
            '## What we collect',
            $f['orders'] ? '- The order details you enter: name, phone number, delivery address and time, and any notes or gift messages.' : null,
            '- What you write when you contact us.',
            $f['whatsapp'] ? '- Your number and messages if you contact us on WhatsApp.' : null,
            $f['analytics'] ? '- General usage data about your visit (pages viewed and how you arrived) through the Google Analytics measurement tool.' : null,
            '- Technical data needed to run the website, such as your chosen language and the cart saved in your browser.',
            '',
            '## Why we use it',
            $f['orders'] ? '- To fulfil and deliver your order and contact you about it.' : null,
            '- To answer your enquiries.',
            '- To issue invoices and keep the records the law requires.',
            'We do not sell your personal data.',
            $f['card'] ? '' : null,
            $f['card'] ? '## Online payment' : null,
            $f['card'] ? 'Card payments are processed by the Paymob payment gateway. You enter your card details on the gateway\'s own page; we never see or store your card number, and we only receive confirmation of whether the payment succeeded.' : null,
            $f['whatsapp'] ? '' : null,
            $f['whatsapp'] ? '## WhatsApp' : null,
            $f['whatsapp'] ? 'If you message us on WhatsApp, your messages pass through the WhatsApp service owned by Meta and are also subject to its privacy policy. We use your number to reply to you and to send you updates about your order.' : null,
            '',
            '## Who we share it with',
            '- The Abaad platform, to host and run the website.',
            $f['card'] ? '- The Paymob payment gateway, to complete payment.' : null,
            $f['whatsapp'] ? '- WhatsApp (Meta), when we message each other there.' : null,
            $f['orders'] ? '- Whoever delivers your order, only as far as delivery requires.' : null,
            '- Public authorities, where the law requires it.',
            '',
            '## How long we keep it',
            'We keep order and invoice data for as long as accounting and tax rules require, and other data only while it is needed for the purpose it was collected for.',
            '',
            '## Your rights',
            'You may ask to access, correct or delete your data. How to request deletion is explained on our "Data Deletion Instructions" page: {{deletion_url}}',
            '',
            '## Contact us',
            '{{contact}}',
        ];
    }

    /** @return list<?string> */
    private static function deletionAr(array $f): array
    {
        return [
            'يمكنك أن تطلب حذف بياناتك الشخصيّة التي يحفظها متجر «{{store_name}}» ({{website}}) في أيّ وقت.',
            '',
            '## كيف تطلب الحذف',
            '- راسلنا من الرقم أو البريد الذي استعملته في طلباتك على وسيلة التواصل أدناه.',
            '- اكتب: «أطلب حذف بياناتي»، ومعها اسمك ورقم هاتفك.',
            '{{contact}}',
            '',
            '## ماذا يحدث بعد طلبك',
            '- نتحقّق أنّ الطلب صادرٌ منك قبل أيّ حذف.',
            '- يتمّ الحذف يدويًّا من قِبَلنا وليس آليًّا، ونؤكّد لك حين يتمّ.',
            '- نحذف بياناتك التي لا يلزم الاحتفاظ بها، مثل عناوينك وملاحظاتك.',
            '- قد نحتفظ بما يلزمنا القانون بحفظه، مثل الفواتير والسجلّات المحاسبيّة، للمدّة التي يفرضها.',
            $f['whatsapp'] ? '' : null,
            $f['whatsapp'] ? '## رسائل واتساب وخدمات Meta' : null,
            $f['whatsapp'] ? '- إن وصلتنا بياناتك عبر واتساب أو غيرها من خدمات Meta، فاطلب حذفها بالطريقة نفسها أعلاه.' : null,
            $f['whatsapp'] ? '- حذفُ بياناتك من سجلّاتنا لا يحذف المحادثة من تطبيق واتساب على هاتفك؛ يمكنك حذفها من التطبيق نفسه.' : null,
            '',
            'وتجد تفاصيل ما نجمعه ولماذا في سياسة الخصوصيّة: {{privacy_url}}',
        ];
    }

    /** @return list<?string> */
    private static function deletionEn(array $f): array
    {
        return [
            'You can ask the "{{store_name}}" store ({{website}}) to delete the personal data it holds about you at any time.',
            '',
            '## How to request deletion',
            '- Contact us, from the number or email you used for your orders, through the contact details below.',
            '- Write: "Please delete my data", together with your name and phone number.',
            '{{contact}}',
            '',
            '## What happens next',
            '- We confirm that the request comes from you before deleting anything.',
            '- Deletion is carried out manually by us, not automatically, and we confirm to you once it is done.',
            '- We delete the data we are not required to keep, such as your addresses and notes.',
            '- We may keep what the law requires us to keep, such as invoices and accounting records, for the period it requires.',
            $f['whatsapp'] ? '' : null,
            $f['whatsapp'] ? '## WhatsApp messages and Meta services' : null,
            $f['whatsapp'] ? '- If your data reached us through WhatsApp or another Meta service, request its deletion in the same way.' : null,
            $f['whatsapp'] ? '- Deleting your data from our records does not delete the chat from the WhatsApp app on your phone; you can delete it in the app itself.' : null,
            '',
            'Details of what we collect and why are in our privacy policy: {{privacy_url}}',
        ];
    }

    /** @return list<?string> */
    private static function termsAr(array $f): array
    {
        return [
            'تنظّم هذه الشروطُ استعمالَك لموقع «{{store_name}}» ({{website}}) الذي يديره {{legal_name}}. باستعمالك الموقع أو طلبك منه فأنت توافق عليها.',
            '',
            $f['orders'] ? '## الطلبات والأسعار' : '## المنتجات والأسعار',
            '- الأسعار المعروضة هي أسعار البيع، وما يُضاف إليها من رسومٍ يظهر قبل تأكيد الطلب.',
            $f['orders'] ? '- يُعدّ الطلب مقبولًا حين نؤكّده لك. وقد نعتذر عن طلبٍ لنفاد صنفٍ أو تعذّر توصيله، ونردّ ما دُفع عنه.' : '- الطلب يتمّ بالتواصل معنا، ويُعدّ مقبولًا حين نؤكّده لك.',
            '- صور المنتجات للتوضيح، وقد يختلف المنتج المسلَّم عنها اختلافًا يسيرًا.',
            '',
            '## الدفع والتوصيل',
            $f['card'] ? '- الدفع بالبطاقة يتمّ عبر بوّابة الدفع Paymob، ولا نحفظ بيانات بطاقتك.' : null,
            '- طرق الدفع ومواعيد التوصيل ومناطقه ورسومه تُعرض قبل تأكيد الطلب.',
            '',
            '## الإلغاء والاسترجاع',
            '[[اكتب هنا سياسة الإلغاء والاسترجاع والاستبدال الخاصّة بمتجرك]]',
            '',
            '## الخصوصيّة',
            'نتعامل مع بياناتك وفق سياسة الخصوصيّة: {{privacy_url}}',
            '',
            '## تواصل معنا',
            '{{contact}}',
        ];
    }

    /** @return list<?string> */
    private static function termsEn(array $f): array
    {
        return [
            'These terms govern your use of the "{{store_name}}" website ({{website}}), operated by {{legal_name}}. By using the website or ordering from it, you agree to them.',
            '',
            $f['orders'] ? '## Orders and prices' : '## Products and prices',
            '- Prices shown are selling prices; any added fees are shown before you confirm your order.',
            $f['orders'] ? '- An order is accepted once we confirm it. We may decline an order if an item is out of stock or cannot be delivered, and refund what was paid for it.' : '- Orders are placed by contacting us and are accepted once we confirm them.',
            '- Product photos are for illustration; the delivered product may differ slightly.',
            '',
            '## Payment and delivery',
            $f['card'] ? '- Card payments are processed by the Paymob payment gateway; we do not store your card details.' : null,
            '- Payment methods, delivery times, areas and fees are shown before you confirm your order.',
            '',
            '## Cancellations and returns',
            '[[Write your store\'s cancellation, return and exchange policy here]]',
            '',
            '## Privacy',
            'We handle your data according to our privacy policy: {{privacy_url}}',
            '',
            '## Contact us',
            '{{contact}}',
        ];
    }
}
