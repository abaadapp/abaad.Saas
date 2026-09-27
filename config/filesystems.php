<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default filesystem disk that should be used
    | by the framework. The "local" disk, as well as a variety of cloud
    | based disks are available to your application for file storage.
    |
    */

    'default' => env('FILESYSTEM_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | Below you may configure as many filesystem disks as necessary, and you
    | may even configure multiple disks for the same driver. Examples for
    | most supported storage drivers are configured here for reference.
    |
    | Supported drivers: "local", "ftp", "sftp", "s3"
    |
    */

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => true,
            'throw' => false,
            'report' => false,
        ],

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => rtrim(env('APP_URL', 'http://localhost'), '/').'/storage',
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

        /*
         * قرصٌ مستقلٌّ عن هذا الخادم — لنسخِ أرشيفِ الحذف النهائيّ.
         *
         * DigitalOcean Spaces يتكلّم لغةَ S3، فالمحوّلُ نفسُه يخدمه. ومنطقتُه
         * تُكتب في `AWS_DEFAULT_REGION` (مثلًا `fra1`) ونقطتُه في
         * `BUSINESS_PURGE_SPACES_ENDPOINT`.
         *
         * ولا مفاتيحَ هنا: كلُّها من البيئة. ومَن لم يضبطها لا يعمل عنده
         * الحذفُ النهائيّ — وذاك مقصود، انظر `config/purge.php`.
         */
        /*
         * نسخةُ أرشيفِ الحذف على الخادم نفسِه — لمن اختار ذلك صراحةً.
         *
         * جذرُه خارج `storage/app` افتراضًا كي لا يقع في مجلّدٍ يحتوي
         * الأرشيفَ الأصليّ — و`Offsite` يرفض التداخل على كلّ حال. ومن له
         * قرصٌ آخرُ موصولٌ بالخادم يوجّهه إليه بـ`BUSINESS_PURGE_LOCAL_ROOT`:
         * ليس استقلالًا، لكنّه يَسلم من امتلاء قرصٍ أو حذفِ مجلّد.
         *
         * ولا يعمل بلا `BUSINESS_PURGE_ALLOW_LOCAL=true` — انظر `config/purge.php`.
         */
        'purge-copy' => [
            'driver' => 'local',
            'root' => env('BUSINESS_PURGE_LOCAL_ROOT', storage_path('purge-copies')),
            'visibility' => 'private',
            'throw' => true,
            'report' => false,
        ],

        'spaces' => [
            'driver' => 's3',
            'key' => env('BUSINESS_PURGE_SPACES_KEY'),
            'secret' => env('BUSINESS_PURGE_SPACES_SECRET'),
            'region' => env('BUSINESS_PURGE_SPACES_REGION', 'fra1'),
            'bucket' => env('BUSINESS_PURGE_SPACES_BUCKET'),
            'endpoint' => env('BUSINESS_PURGE_SPACES_ENDPOINT'),
            'use_path_style_endpoint' => false,
            'visibility' => 'private',
            'throw' => true,
            'report' => false,
        ],

        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'throw' => false,
            'report' => false,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Symbolic Links
    |--------------------------------------------------------------------------
    |
    | Here you may configure the symbolic links that will be created when the
    | `storage:link` Artisan command is executed. The array keys should be
    | the locations of the links and the values should be their targets.
    |
    */

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

];
