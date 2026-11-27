<?php

/*
 * ═══ جدولُ المسارات في المتصفّح — لكلّ واجهةٍ ما تنادي به ═══
 *
 * كان `@routes` يكتب المساراتِ كلَّها (نحو ٨٠٠، ١٢٠ كيلوبايت قبل الضغط) في كلّ
 * صفحة: صفحةُ الدخول تحمل مسارات المنصّة، والصندوقُ يحمل مسارات اللوحة كلَّها.
 * فصار لكلّ مجلّدٍ من الصفحات مجموعتُه (`App\Support\RouteGroups`):
 *
 * - `guest` — صفحاتُ الدخول (`Pages/Auth`).
 * - `pos` — الصندوق (`Pages/Pos`): مساراتُه وما تنادي به شاشاتُه من غيرها.
 * - `merchant` — اللوحة والملفّ (`Pages/Admin`، `Pages/Profile`)، وفيها مجموعةُ
 *   الصندوق كلُّها: اللوحةُ تنتقل إليه بلا تحميل.
 * - ومدير المنصّة ومن دخل باسم تاجر: الجدولُ كلُّه كما كان.
 *
 * والانتقالُ بين واجهتين بلا تحميل (الصندوق → اللوحة، الدخول → اللوحة) يجلب
 * مجموعةَ الصفحة الجديدة ويضمّها قبل أن تُرسم (`lib/route-groups.ts`) — فلا
 * تُنادى `route()` باسمٍ غائب.
 *
 * ومن أضاف إلى صفحةٍ اسمَ مسارٍ ليس في مجموعتها يُسقطه حارسٌ يقرأ ما تصل إليه
 * كلُّ صفحةٍ من ملفّات (`EveryRouteAScreenCallsIsInItsGroupTest`) — فتُزاد هنا.
 */
return [
    'groups' => [
        'guest' => [
            'login', 'login.*', 'logout',
            'register', 'register.*',
            'password.*',
            'recovery.*',
            'device.forget',
            'language.guest',
            // ‏‏صفحةُ رابط ربط واتساب لصاحب المتجر (`ClientLinkController`)
            'clientLink.*',
        ],

        'pos' => [
            'pos.*',
            'logout',
            // ‏شريطُ «أنت داخلٌ باسم…» وزرُّ ملفّي
            'impersonate.stop',
            'profile.edit',
            // ‏أزرارُ شاشات الصندوق إلى اللوحة: الموظّفون، والتجهيز، وقيدُ الدرج من اللوحة
            'admin.employees.index',
            'admin.preparation.index',
            'admin.finance.store',
        ],

        'merchant' => [
            // بوابةُ عملاء RIBBON لمتجرٍ واحد: عناوينُها من الخادم لمن يملكها، ولا تُكتب في جدولٍ يصل كلَّ تاجر
            '!admin.customers.portal.*', '!admin.settings.portal.*',
            'admin.*',
            'pos.*',
            'profile.*',
            'login', 'logout',
            'impersonate.*',
            /*
             * ‏وقائمةُ المنصّة وفهرسُ البحث (`lib/nav.ts`، `lib/pages.ts`) — ملفّان
             * تقرؤهما اللوحة، ولا يُرسمان إلّا لمدير المنصّة (وهو يأخذ الجدولَ كلَّه).
             */
            'super-admin.dashboard', 'super-admin.search', 'super-admin.language.update',
            'super-admin.sectors.index', 'super-admin.sectors.show',
            'super-admin.businesses.index',
            'super-admin.subscriptions.index', 'super-admin.subscriptions.plans', 'super-admin.subscriptions.addons',
            'super-admin.users.index',
            'super-admin.documents.index', 'super-admin.documents.show', 'super-admin.documents.templates',
            'super-admin.conversations.index',
            'super-admin.crm.dashboard', 'super-admin.crm.leads.index', 'super-admin.crm.leads.show',
            'super-admin.crm.conversations', 'super-admin.crm.pipeline', 'super-admin.crm.tasks', 'super-admin.crm.reports',
            'super-admin.demo.index', 'super-admin.reports.index', 'super-admin.activity.index', 'super-admin.settings.index',
        ],
    ],
];
