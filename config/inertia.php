<?php

/**
 * مسار صفحات Inertia — مكتوبًا لا متروكًا للافتراضي.
 *
 * افتراضي الحزمة resource_path('js/pages') بحرفٍ صغير، ومجلّدنا Pages بحرفٍ
 * كبير. وmacOS لا يفرّق بين الحرفين فيمرّ محلّيًّا، ولينكس يفرّق فينكسر على
 * الخادم وفي CI: «Inertia page component file [Auth/Login] does not exist»
 * لملفٍّ عمره أشهر وموجودٍ أمام عينك.
 *
 * وmergeConfigFrom يدمج المفاتيح العليا وحدها، فمصفوفة pages تُستبدل كاملةً
 * ولا تُدمج — لذا تُكتب امتداداتها هنا معها ولا تُترك للحزمة.
 */
return [

    'pages' => [

        'ensure_pages_exist' => false,

        'paths' => [

            resource_path('js/Pages'),

        ],

        'extensions' => [

            'js',
            'jsx',
            'svelte',
            'ts',
            'tsx',
            'vue',

        ],

    ],

];
