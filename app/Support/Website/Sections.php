<?php

namespace App\Support\Website;

/**
 * مكتبة الأقسام — كلّ ما يمكن أن يُبنى منه موقع، في جدولٍ واحد.
 *
 * القسم هنا وصفٌ لا كود: اسمُه، وأين يصلح، وما الحقول التي يملؤها التاجر،
 * ومن أين يأتي ما لا يملؤه بيده. والمحرّر يقرأ هذا الوصف فيرسم نموذجه، وقارئ
 * الموقع يقرؤه فيعرف ماذا يعرض — فإضافةُ قسمٍ جديد سطرٌ هنا لا شاشةٌ جديدة
 * ولا هجرةٌ ولا تعديلٌ في المحرّر.
 *
 * وهذا هو الفرق بين محرّك مواقع وبين شاشة إعدادات: الشاشة تعرف حقولها
 * مسبقًا، والمحرّك يعرف كيف يقرأ وصفَ أيّ حقل.
 *
 * وثلاثة قيود تحكم كلّ حقلٍ هنا:
 *
 * ١) **لكلّ حقلٍ قيمةٌ افتراضية صالحة.** التاجر لا يبدأ من فراغ: يُنشأ القسم
 *    مملوءًا بما يليق بنشاطه، ثمّ يغيّر ما يريد. وقسمٌ يُضاف فارغًا يجعل
 *    الموقع أسوأ بضغطة زر، فلا يُضاف مرّةً ثانية.
 *
 * ٢) **ما لا يحتاجه أحدٌ في اليوم الأوّل يُعلَّم `advanced`.** المحاذاةُ
 *    والارتفاع وعددُ الأعمدة تُطوى تحت «إعدادات متقدّمة»؛ العنوانُ والصورةُ
 *    والزرّ تُعرض. وخيارٌ يُعرض لأنّه موجودٌ يجعل نموذجًا من ثلاثة حقول
 *    يُقرأ كاستمارة.
 *
 * ٣) **ما يعرفه النظام لا يُسأل عنه.** `source` تقول من أين يأتي المحتوى —
 *    منتجاتٌ أو تصنيفاتٌ أو آراءُ عملاء — فلا يُطلب من التاجر أن ينسخ
 *    منتجاته في موقعه بيده. انظر `MerchantData`.
 */
class Sections
{
    /** أقسامٌ عامّة لا تخصّ صفحة — تُعرض في كلّ صفحة */
    public const HEADER = 'header';

    public const FOOTER = 'footer';

    public const SLOTS = [self::HEADER, self::FOOTER];

    /** مجموعات المكتبة كما تُعرض في «إضافة قسم» */
    public const GROUPS = ['محتوى', 'التجارة', 'التواصل'];

    /**
     * مصادر المحتوى التي يقرؤها القسم من النظام بدل أن يكتبها التاجر.
     *
     * والقسم الذي يقرأ منها لا يُعرض على من لا يملك ما يقرؤه: «التصنيفات»
     * في موقعٍ بلا تصنيفات شريطٌ فارغ يُقبّح الموقع ولا يقول لماذا.
     */
    public const SOURCES = ['products', 'categories', 'reviews'];

    /**
     * كلّ نوع قسم ووصفه.
     *
     * `goals` أين يصلح — و`null` تعني كلّ الوجهات. `unique` قسمٌ لا يتكرّر في
     * الصفحة الواحدة (ترويسةٌ ثانية لا معنى لها). `slot` قسمٌ عامّ لا يوضع في
     * صفحة.
     */
    public const CATALOGUE = [
        /* ------------------------------ عامّ ------------------------------ */
        self::HEADER => [
            'label' => 'الترويسة',
            'hint' => 'الشعار والقائمة وما يصل إليه الزائر من أعلى كلّ صفحة',
            'group' => 'محتوى',
            'slot' => true,
            'fields' => [
                'preset' => ['label' => 'الشكل', 'type' => 'select', 'default' => 'simple', 'options' => [
                    'simple' => 'بسيط — شعار وقائمة',
                    'centered' => 'الشعار في الوسط',
                    'full' => 'موسّع — بحثٌ وحساب وسلّة',
                ]],
                'show_search' => ['label' => 'إظهار البحث', 'type' => 'toggle', 'default' => false],
                'show_cart' => ['label' => 'إظهار السلّة', 'type' => 'toggle', 'default' => true, 'goals' => ['store']],
                'show_whatsapp' => ['label' => 'زر واتساب', 'type' => 'toggle', 'default' => true],
                'sticky' => ['label' => 'تثبيتها عند التمرير', 'type' => 'toggle', 'default' => true, 'advanced' => true],
                'links' => [
                    'label' => 'روابط القائمة', 'type' => 'list', 'default' => [],
                    'hint' => 'تُملأ من صفحاتك — أضف أو احذف ما تشاء',
                    'item' => [
                        'label' => ['label' => 'الاسم', 'type' => 'text', 'default' => ''],
                        'href' => ['label' => 'الوجهة', 'type' => 'link', 'default' => '/'],
                    ],
                ],
            ],
        ],
        self::FOOTER => [
            'label' => 'التذييل',
            'hint' => 'نبذةٌ وروابطُ ومعلومات التواصل وحقوق النشر',
            'group' => 'محتوى',
            'slot' => true,
            'fields' => [
                'about' => ['label' => 'نبذة قصيرة', 'type' => 'textarea', 'default' => '', 'max' => 400],
                'show_contact' => ['label' => 'معلومات التواصل', 'type' => 'toggle', 'default' => true],
                'show_social' => ['label' => 'حسابات التواصل', 'type' => 'toggle', 'default' => true],
                'show_payments' => ['label' => 'طرق الدفع', 'type' => 'toggle', 'default' => true, 'goals' => ['store']],
                'copyright' => ['label' => 'حقوق النشر', 'type' => 'text', 'default' => '', 'max' => 160],
                'links' => [
                    'label' => 'روابط', 'type' => 'list', 'default' => [],
                    'item' => [
                        'label' => ['label' => 'الاسم', 'type' => 'text', 'default' => ''],
                        'href' => ['label' => 'الوجهة', 'type' => 'link', 'default' => '/'],
                    ],
                ],
            ],
        ],

        /* ----------------------------- محتوى ----------------------------- */
        'hero' => [
            'label' => 'الواجهة الرئيسية',
            'hint' => 'أوّل ما يراه الزائر: جملةٌ تقول من أنت وزرٌّ يقول ماذا يفعل',
            'group' => 'محتوى',
            'unique' => true,
            'fields' => [
                'title' => ['label' => 'العنوان', 'type' => 'text', 'default' => '', 'max' => 120],
                'subtitle' => ['label' => 'الجملة تحته', 'type' => 'textarea', 'default' => '', 'max' => 300],
                'image' => ['label' => 'صورة الخلفية', 'type' => 'image', 'default' => ''],
                'cta_label' => ['label' => 'نصّ الزر', 'type' => 'text', 'default' => '', 'max' => 40],
                'cta_href' => ['label' => 'وجهة الزر', 'type' => 'link', 'default' => '/'],
                'align' => ['label' => 'المحاذاة', 'type' => 'select', 'default' => 'center', 'advanced' => true, 'options' => [
                    'start' => 'إلى اليمين', 'center' => 'في الوسط', 'end' => 'إلى اليسار',
                ]],
                'height' => ['label' => 'الارتفاع', 'type' => 'select', 'default' => 'medium', 'advanced' => true, 'options' => [
                    'small' => 'قصير', 'medium' => 'متوسط', 'large' => 'ملء الشاشة',
                ]],
                'overlay' => ['label' => 'تعتيم الصورة', 'type' => 'select', 'default' => 'medium', 'advanced' => true, 'options' => [
                    'none' => 'بلا', 'light' => 'خفيف', 'medium' => 'متوسط', 'strong' => 'قويّ',
                ]],
            ],
        ],
        'image_text' => [
            'label' => 'صورة ونصّ',
            'hint' => 'فقرةٌ إلى جانب صورة — للتعريف بالنشاط أو بخدمةٍ منه',
            'group' => 'محتوى',
            'fields' => [
                'title' => ['label' => 'العنوان', 'type' => 'text', 'default' => '', 'max' => 120],
                'body' => ['label' => 'النصّ', 'type' => 'textarea', 'default' => '', 'max' => 1500],
                'image' => ['label' => 'الصورة', 'type' => 'image', 'default' => ''],
                'cta_label' => ['label' => 'نصّ الزر', 'type' => 'text', 'default' => '', 'max' => 40],
                'cta_href' => ['label' => 'وجهة الزر', 'type' => 'link', 'default' => ''],
                'side' => ['label' => 'موضع الصورة', 'type' => 'select', 'default' => 'start', 'advanced' => true, 'options' => [
                    'start' => 'يمين النصّ', 'end' => 'يسار النصّ',
                ]],
            ],
        ],
        'banner' => [
            'label' => 'شريط إعلاني',
            'hint' => 'سطرٌ عريض لعرضٍ أو إعلانٍ مؤقّت',
            'group' => 'محتوى',
            'fields' => [
                'text' => ['label' => 'النصّ', 'type' => 'text', 'default' => '', 'max' => 160],
                'cta_label' => ['label' => 'نصّ الزر', 'type' => 'text', 'default' => '', 'max' => 40],
                'cta_href' => ['label' => 'وجهة الزر', 'type' => 'link', 'default' => ''],
                'tone' => ['label' => 'اللون', 'type' => 'select', 'default' => 'primary', 'advanced' => true, 'options' => [
                    'primary' => 'اللون الأساسي', 'dark' => 'داكن', 'soft' => 'فاتح',
                ]],
            ],
        ],
        'gallery' => [
            'label' => 'معرض صور',
            'hint' => 'صورٌ من المحلّ أو من أعمالك',
            'group' => 'محتوى',
            'fields' => [
                'title' => ['label' => 'العنوان', 'type' => 'text', 'default' => '', 'max' => 120],
                'images' => [
                    'label' => 'الصور', 'type' => 'list', 'default' => [],
                    'item' => [
                        'src' => ['label' => 'الصورة', 'type' => 'image', 'default' => ''],
                        'alt' => ['label' => 'وصفها', 'type' => 'text', 'default' => '', 'hint' => 'يُقرأ لمن لا يرى الصورة، ويقرؤه غوغل'],
                    ],
                ],
                'columns' => ['label' => 'عدد الأعمدة', 'type' => 'select', 'default' => '3', 'advanced' => true, 'options' => [
                    '2' => 'اثنان', '3' => 'ثلاثة', '4' => 'أربعة',
                ]],
            ],
        ],
        'video' => [
            'label' => 'فيديو',
            'hint' => 'مقطعٌ من يوتيوب أو غيره',
            'group' => 'محتوى',
            'fields' => [
                'title' => ['label' => 'العنوان', 'type' => 'text', 'default' => '', 'max' => 120],
                'url' => ['label' => 'رابط الفيديو', 'type' => 'link', 'default' => ''],
            ],
        ],
        'faq' => [
            'label' => 'أسئلة شائعة',
            'hint' => 'أسئلةٌ يسألها الزبائن — تختصر رسائل واتساب',
            'group' => 'محتوى',
            'fields' => [
                'title' => ['label' => 'العنوان', 'type' => 'text', 'default' => 'أسئلة شائعة', 'max' => 120],
                'items' => [
                    'label' => 'الأسئلة', 'type' => 'list', 'default' => [],
                    'item' => [
                        'q' => ['label' => 'السؤال', 'type' => 'text', 'default' => '', 'max' => 200],
                        'a' => ['label' => 'الجواب', 'type' => 'textarea', 'default' => '', 'max' => 1000],
                    ],
                ],
            ],
        ],
        'stats' => [
            'label' => 'أرقام',
            'hint' => 'ثلاثةُ أرقامٍ تقول خبرتك — سنواتٌ، زبائن، طلبات',
            'group' => 'محتوى',
            'fields' => [
                'items' => [
                    'label' => 'الأرقام', 'type' => 'list', 'default' => [],
                    'item' => [
                        'value' => ['label' => 'الرقم', 'type' => 'text', 'default' => '', 'max' => 20],
                        'label' => ['label' => 'ماذا يعني', 'type' => 'text', 'default' => '', 'max' => 60],
                    ],
                ],
            ],
        ],
        'benefits' => [
            'label' => 'لماذا نحن',
            'hint' => 'ثلاثُ مزايا — توصيلٌ سريع، ضمان، دفعٌ آمن',
            'group' => 'محتوى',
            'fields' => [
                'title' => ['label' => 'العنوان', 'type' => 'text', 'default' => '', 'max' => 120],
                'items' => [
                    'label' => 'المزايا', 'type' => 'list', 'default' => [],
                    'item' => [
                        'icon' => ['label' => 'الأيقونة', 'type' => 'text', 'default' => 'check', 'advanced' => true],
                        'title' => ['label' => 'العنوان', 'type' => 'text', 'default' => '', 'max' => 60],
                        'text' => ['label' => 'الشرح', 'type' => 'text', 'default' => '', 'max' => 160],
                    ],
                ],
            ],
        ],
        'testimonials' => [
            'label' => 'آراء العملاء',
            'hint' => 'التقييمات المنشورة في نظامك — تُقرأ من مكانها ولا تُكتب هنا',
            'group' => 'محتوى',
            'source' => 'reviews',
            'fields' => [
                'title' => ['label' => 'العنوان', 'type' => 'text', 'default' => 'ماذا يقول عملاؤنا', 'max' => 120],
                'limit' => ['label' => 'كم رأيًا يظهر', 'type' => 'number', 'default' => 6, 'min' => 1, 'max' => 24],
                'min_rating' => ['label' => 'أقلّ تقييم يُعرض', 'type' => 'select', 'default' => '4', 'advanced' => true, 'options' => [
                    '3' => 'ثلاث نجوم فأكثر', '4' => 'أربع نجوم فأكثر', '5' => 'خمس نجوم فقط',
                ]],
            ],
        ],

        /* ---------------------------- التجارة ---------------------------- */
        'featured_products' => [
            'label' => 'منتجات مختارة',
            'hint' => 'منتجاتٌ تختارها بنفسك لتظهر في الواجهة',
            'group' => 'التجارة',
            'goals' => ['store', 'catalog'],
            'source' => 'products',
            'fields' => [
                'title' => ['label' => 'العنوان', 'type' => 'text', 'default' => 'منتجات مختارة', 'max' => 120],
                'product_ids' => [
                    'label' => 'المنتجات', 'type' => 'products', 'default' => [],
                    'hint' => 'اتركها فارغة ليعرض أحدث منتجاتك تلقائيًّا',
                ],
                'limit' => ['label' => 'كم منتجًا يظهر', 'type' => 'number', 'default' => 8, 'min' => 2, 'max' => 24],
                'columns' => ['label' => 'عدد الأعمدة', 'type' => 'select', 'default' => '4', 'advanced' => true, 'options' => [
                    '2' => 'اثنان', '3' => 'ثلاثة', '4' => 'أربعة',
                ]],
            ],
        ],
        'latest_products' => [
            'label' => 'أحدث المنتجات',
            'hint' => 'يتحدّث وحده كلّما أضفت منتجًا',
            'group' => 'التجارة',
            'goals' => ['store', 'catalog'],
            'source' => 'products',
            'fields' => [
                'title' => ['label' => 'العنوان', 'type' => 'text', 'default' => 'وصل حديثًا', 'max' => 120],
                'limit' => ['label' => 'كم منتجًا يظهر', 'type' => 'number', 'default' => 8, 'min' => 2, 'max' => 24],
                'columns' => ['label' => 'عدد الأعمدة', 'type' => 'select', 'default' => '4', 'advanced' => true, 'options' => [
                    '2' => 'اثنان', '3' => 'ثلاثة', '4' => 'أربعة',
                ]],
            ],
        ],
        'best_sellers' => [
            'label' => 'الأكثر مبيعًا',
            'hint' => 'يُحسب من مبيعاتك الفعلية — لا تختاره بيدك',
            'group' => 'التجارة',
            'goals' => ['store', 'catalog'],
            'source' => 'products',
            'fields' => [
                'title' => ['label' => 'العنوان', 'type' => 'text', 'default' => 'الأكثر مبيعًا', 'max' => 120],
                'limit' => ['label' => 'كم منتجًا يظهر', 'type' => 'number', 'default' => 8, 'min' => 2, 'max' => 24],
                'days' => ['label' => 'خلال كم يومًا', 'type' => 'number', 'default' => 90, 'min' => 7, 'max' => 365, 'advanced' => true],
            ],
        ],
        'categories' => [
            'label' => 'التصنيفات',
            'hint' => 'أبواب متجرك — يدخل الزائر من الباب الذي يريده',
            'group' => 'التجارة',
            'goals' => ['store', 'catalog'],
            'source' => 'categories',
            'fields' => [
                'title' => ['label' => 'العنوان', 'type' => 'text', 'default' => 'تسوّق حسب القسم', 'max' => 120],
                'limit' => ['label' => 'كم تصنيفًا يظهر', 'type' => 'number', 'default' => 8, 'min' => 2, 'max' => 24],
                'style' => ['label' => 'الشكل', 'type' => 'select', 'default' => 'cards', 'advanced' => true, 'options' => [
                    'cards' => 'بطاقات', 'pills' => 'أزرار', 'grid' => 'شبكة صور',
                ]],
            ],
        ],
        'promo' => [
            'label' => 'عرض خاص',
            'hint' => 'خصمٌ أو عرضٌ محدّد بمدّة',
            'group' => 'التجارة',
            'goals' => ['store', 'catalog'],
            'fields' => [
                'title' => ['label' => 'العنوان', 'type' => 'text', 'default' => '', 'max' => 120],
                'text' => ['label' => 'الشرح', 'type' => 'textarea', 'default' => '', 'max' => 300],
                'image' => ['label' => 'الصورة', 'type' => 'image', 'default' => ''],
                'cta_label' => ['label' => 'نصّ الزر', 'type' => 'text', 'default' => 'تسوّق الآن', 'max' => 40],
                'cta_href' => ['label' => 'وجهة الزر', 'type' => 'link', 'default' => '/shop'],
                'ends_at' => ['label' => 'ينتهي في', 'type' => 'date', 'default' => '', 'advanced' => true],
            ],
        ],

        /* ---------------------------- التواصل ---------------------------- */
        'contact' => [
            'label' => 'تواصل معنا',
            'hint' => 'الهاتف والبريد والعنوان — تُملأ من بيانات نشاطك',
            'group' => 'التواصل',
            'unique' => true,
            'fields' => [
                'title' => ['label' => 'العنوان', 'type' => 'text', 'default' => 'تواصل معنا', 'max' => 120],
                'text' => ['label' => 'جملة تمهيدية', 'type' => 'textarea', 'default' => '', 'max' => 300],
                'phone' => ['label' => 'رقم الاتصال', 'type' => 'text', 'default' => '', 'max' => 30],
                'whatsapp' => ['label' => 'واتساب', 'type' => 'text', 'default' => '', 'max' => 30],
                'email' => ['label' => 'البريد', 'type' => 'text', 'default' => '', 'max' => 120],
                'address' => ['label' => 'العنوان', 'type' => 'textarea', 'default' => '', 'max' => 300],
                'show_form' => ['label' => 'نموذج رسالة', 'type' => 'toggle', 'default' => false, 'advanced' => true],
            ],
        ],
        'map' => [
            'label' => 'الخريطة',
            'hint' => 'موقع المحلّ على الخريطة — يصل إليه الزبون بلا أن يسأل',
            'group' => 'التواصل',
            'unique' => true,
            'fields' => [
                'title' => ['label' => 'العنوان', 'type' => 'text', 'default' => 'أين نحن', 'max' => 120],
                'address' => ['label' => 'العنوان المكتوب', 'type' => 'textarea', 'default' => '', 'max' => 300],
                'url' => [
                    'label' => 'رابط الموقع على خرائط غوغل', 'type' => 'link', 'default' => '',
                    'hint' => 'افتح خرائط غوغل، ابحث عن محلّك، اضغط «مشاركة» وانسخ الرابط',
                ],
                'height' => ['label' => 'الارتفاع', 'type' => 'select', 'default' => 'medium', 'advanced' => true, 'options' => [
                    'small' => 'قصير', 'medium' => 'متوسط', 'large' => 'طويل',
                ]],
            ],
        ],
        'social' => [
            'label' => 'حسابات التواصل',
            'hint' => 'إنستغرام وسناب وغيرها — أضف ما تملكه فقط',
            'group' => 'التواصل',
            'unique' => true,
            'fields' => [
                'title' => ['label' => 'العنوان', 'type' => 'text', 'default' => 'تابعنا', 'max' => 120],
                'accounts' => [
                    'label' => 'الحسابات', 'type' => 'social', 'default' => [],
                    'hint' => 'أضف الحساب الذي تملكه — ولا تُعرض خانةٌ لما لا تملك',
                ],
            ],
        ],
        'whatsapp' => [
            'label' => 'زر واتساب',
            'hint' => 'زرٌّ عائم يفتح محادثةً مباشرة',
            'group' => 'التواصل',
            'unique' => true,
            'fields' => [
                'number' => ['label' => 'الرقم', 'type' => 'text', 'default' => '', 'max' => 30, 'hint' => 'بصيغة دولية بلا + — مثل 96890000000'],
                'message' => ['label' => 'الرسالة الجاهزة', 'type' => 'text', 'default' => '', 'max' => 200, 'advanced' => true],
            ],
        ],
    ];

    /** شبكات التواصل المعروفة — يُختار منها ولا تُعرض كلّها خاناتٍ فارغة */
    public const NETWORKS = [
        'instagram' => ['label' => 'إنستغرام', 'base' => 'https://instagram.com/'],
        'tiktok' => ['label' => 'تيك توك', 'base' => 'https://tiktok.com/@'],
        'snapchat' => ['label' => 'سناب شات', 'base' => 'https://snapchat.com/add/'],
        'x' => ['label' => 'إكس (تويتر)', 'base' => 'https://x.com/'],
        'facebook' => ['label' => 'فيسبوك', 'base' => 'https://facebook.com/'],
        'youtube' => ['label' => 'يوتيوب', 'base' => 'https://youtube.com/@'],
        'linkedin' => ['label' => 'لينكدإن', 'base' => 'https://linkedin.com/company/'],
        'threads' => ['label' => 'ثريدز', 'base' => 'https://threads.net/@'],
    ];

    public static function exists(string $type): bool
    {
        return isset(self::CATALOGUE[$type]);
    }

    /** هل هذا النوع قسمٌ عامّ (ترويسة أو تذييل) لا قسمَ صفحة؟ */
    public static function isSlot(string $type): bool
    {
        return (bool) (self::CATALOGUE[$type]['slot'] ?? false);
    }

    public static function label(string $type): string
    {
        return __(self::CATALOGUE[$type]['label'] ?? 'قسم');
    }

    /** مصدر محتوى القسم من النظام — أو null إن كان يكتبه التاجر كلَّه */
    public static function source(string $type): ?string
    {
        return self::CATALOGUE[$type]['source'] ?? null;
    }

    /**
     * القيم الافتراضية لنوعٍ من الأقسام.
     *
     * وحقلٌ لا يصلح لوجهة الموقع لا قيمة له: من اختار «تعريفيّ» لا سلّة في
     * ترويسته، فلا يُكتب المفتاح أصلًا بدل أن يُكتب مطفأً.
     */
    public static function defaults(string $type, string $goal = 'store'): array
    {
        $out = [];

        foreach (self::CATALOGUE[$type]['fields'] ?? [] as $key => $field) {
            if (! self::fieldFitsGoal($field, $goal)) {
                continue;
            }
            $out[$key] = $field['default'];
        }

        return $out;
    }

    /**
     * ما تعرضه شاشة «إضافة قسم» — مترجَمًا، ومحدودًا بوجهة الموقع.
     *
     * `$available` مصادر البيانات الموجودة فعلًا: قسمٌ يقرأ التصنيفات لا
     * يُعرض على متجرٍ بلا تصنيفات — شريطٌ فارغ يُقبّح الموقع ولا يقول لماذا.
     *
     * @param  array<string, bool>  $available
     * @return array<int, array<string, mixed>>
     */
    public static function library(string $goal, array $available = []): array
    {
        $out = [];

        foreach (self::CATALOGUE as $type => $spec) {
            if (! empty($spec['slot'])) {
                continue;
            }
            if (! self::fitsGoal($spec, $goal)) {
                continue;
            }
            if (($source = $spec['source'] ?? null) && ($available[$source] ?? true) === false) {
                continue;
            }

            $out[] = [
                'type' => $type,
                'label' => __($spec['label']),
                'hint' => __($spec['hint']),
                'group' => __($spec['group']),
                'unique' => (bool) ($spec['unique'] ?? false),
                'source' => $source,
            ];
        }

        return $out;
    }

    /**
     * وصف حقول نوعٍ من الأقسام — يرسم المحرّر نموذجه منه.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function schema(string $type, string $goal = 'store'): array
    {
        $out = [];

        foreach (self::CATALOGUE[$type]['fields'] ?? [] as $key => $field) {
            if (! self::fieldFitsGoal($field, $goal)) {
                continue;
            }

            $out[] = [
                'key' => $key,
                'label' => __($field['label']),
                'type' => $field['type'],
                'hint' => isset($field['hint']) ? __($field['hint']) : null,
                'advanced' => (bool) ($field['advanced'] ?? false),
                'options' => isset($field['options'])
                    ? collect($field['options'])->map(fn ($l, $v) => ['value' => (string) $v, 'label' => __($l)])->values()->all()
                    : null,
                'min' => $field['min'] ?? null,
                'max' => $field['max'] ?? null,
                'item' => isset($field['item']) ? self::itemSchema($field['item']) : null,
            ];
        }

        return $out;
    }

    /** @param array<string, mixed> $item */
    private static function itemSchema(array $item): array
    {
        return collect($item)->map(fn ($f, $k) => [
            'key' => $k,
            'label' => __($f['label']),
            'type' => $f['type'],
            'hint' => isset($f['hint']) ? __($f['hint']) : null,
            'advanced' => (bool) ($f['advanced'] ?? false),
            'max' => $f['max'] ?? null,
        ])->values()->all();
    }

    private static function fitsGoal(array $spec, string $goal): bool
    {
        return ! isset($spec['goals']) || in_array($goal, $spec['goals'], true);
    }

    private static function fieldFitsGoal(array $field, string $goal): bool
    {
        return ! isset($field['goals']) || in_array($goal, $field['goals'], true);
    }
}
