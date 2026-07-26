<?php

namespace App\Support;

/**
 * تحويل أسماء الأشخاص من الإنجليزية إلى العربية عبر قاموس أسماء شائعة.
 * فلسفة الميزة: ترجمة صحيحة للأسماء المعروفة فقط — وإن لم يُفهم الاسم يبقى كما هو بالإنجليزية.
 * لا يوجد تخمين صوتي (حتى لا تظهر أسماء عربية خاطئة).
 */
class NameTransliterator
{
    /** هل الاسم مُدخل بالإنجليزية (حروف لاتينية بلا حروف عربية)؟ */
    public static function isLatin(string $s): bool
    {
        return (bool) preg_match('/[a-zA-Z]/', $s) && ! preg_match('/[\x{0600}-\x{06FF}]/u', $s);
    }

    /**
     * يعيد النسخة العربية إن فُهم جزء من الاسم على الأقل، وإلا null.
     * يترجم كل كلمة معروفة، ويُبقي غير المعروفة كما هي.
     */
    public static function toArabic(string $name): ?string
    {
        $map = self::map();
        $tokens = preg_split('/\s+/u', trim($name)) ?: [];
        $out = [];
        $any = false;
        $prefix = '';

        foreach ($tokens as $token) {
            foreach (explode('-', $token) as $part) {
                $key = strtolower(preg_replace('/[^a-zA-Z]/', '', $part));
                if ($key === '') {
                    continue;
                }
                // أدوات تُلحق بما بعدها (لا تُعدّ وحدها «فهمًا» للاسم)
                if (in_array($key, ['al', 'el'], true)) {
                    $prefix = 'ال';
                    continue;
                }
                if (in_array($key, ['abdul', 'abd'], true)) {
                    $prefix = 'عبدال';
                    continue;
                }
                if (isset($map[$key])) {
                    $val = $map[$key];
                    // تفادي ازدواج «ال»: إن انتهى البادئة بـ«ال» وبدأت القيمة بـ«ال»
                    if ($prefix !== '' && str_ends_with($prefix, 'ال') && str_starts_with($val, 'ال')) {
                        $val = mb_substr($val, 2);
                    }
                    $out[] = $prefix . $val;
                    $any = true;
                } else {
                    // كلمة غير معروفة: تبقى كما أُدخلت (بلا بادئة)
                    $out[] = $part;
                }
                $prefix = '';
            }
        }

        if (! $any) {
            return null;
        }

        return trim(implode(' ', $out));
    }

    /** قاموس الأسماء الشائعة (تهجئات متعددة → العربية الصحيحة) */
    private static function map(): array
    {
        return [
            // أدوات ومركّبات
            'bin' => 'بن', 'ben' => 'بن', 'bint' => 'بنت', 'abu' => 'أبو', 'abo' => 'أبو',
            'umm' => 'أم', 'om' => 'أم', 'abd' => 'عبد', 'abdul' => 'عبد',
            'abdullah' => 'عبدالله', 'abdallah' => 'عبدالله', 'abdulrahman' => 'عبدالرحمن',
            'abdelrahman' => 'عبدالرحمن', 'abdulaziz' => 'عبدالعزيز', 'abdelaziz' => 'عبدالعزيز',
            'abdulkarim' => 'عبدالكريم', 'abdulmalik' => 'عبدالملك', 'abdulhamid' => 'عبدالحميد',
            'abdulqader' => 'عبدالقادر', 'abdulnasser' => 'عبدالناصر', 'abdulwahab' => 'عبدالوهاب',
            'abdulrazak' => 'عبدالرزاق', 'abdulsalam' => 'عبدالسلام', 'abduljabbar' => 'عبدالجبار',
            // أجزاء ثانية لأسماء «عبدال ...» حين تُكتب منفصلة
            'rahman' => 'رحمن', 'aziz' => 'عزيز', 'wahab' => 'وهاب', 'razak' => 'رزاق',
            'razzaq' => 'رزاق', 'salam' => 'سلام', 'jabbar' => 'جبار', 'qader' => 'قادر',
            'qadir' => 'قادر', 'latif' => 'لطيف', 'ghani' => 'غني', 'hakim' => 'حكيم',
            'samad' => 'صمد', 'sattar' => 'ستار', 'raheem' => 'رحيم', 'rahim' => 'رحيم',

            // أسماء رجال
            'ahmed' => 'أحمد', 'ahmad' => 'أحمد', 'mohammed' => 'محمد', 'mohamed' => 'محمد',
            'muhammad' => 'محمد', 'mohammad' => 'محمد', 'mahmoud' => 'محمود', 'mahmood' => 'محمود',
            'ali' => 'علي', 'omar' => 'عمر', 'umar' => 'عمر', 'khalid' => 'خالد', 'khaled' => 'خالد',
            'saeed' => 'سعيد', 'said' => 'سعيد', 'salem' => 'سالم', 'salim' => 'سالم',
            'sultan' => 'سلطان', 'nasser' => 'ناصر', 'nasir' => 'ناصر', 'hamad' => 'حمد',
            'hamed' => 'حامد', 'hamid' => 'حامد', 'yousef' => 'يوسف', 'yusuf' => 'يوسف',
            'youssef' => 'يوسف', 'ibrahim' => 'إبراهيم', 'ismail' => 'إسماعيل', 'hassan' => 'حسن',
            'hasan' => 'حسن', 'hussein' => 'حسين', 'hussain' => 'حسين', 'fahad' => 'فهد',
            'fahd' => 'فهد', 'majid' => 'ماجد', 'majed' => 'ماجد', 'rashid' => 'راشد',
            'rashed' => 'راشد', 'tariq' => 'طارق', 'tarek' => 'طارق', 'ziad' => 'زياد',
            'ziyad' => 'زياد', 'bader' => 'بدر', 'badr' => 'بدر', 'faisal' => 'فيصل',
            'sami' => 'سامي', 'waleed' => 'وليد', 'walid' => 'وليد', 'adel' => 'عادل',
            'adil' => 'عادل', 'ayman' => 'أيمن', 'bilal' => 'بلال', 'karim' => 'كريم',
            'kareem' => 'كريم', 'mustafa' => 'مصطفى', 'moustafa' => 'مصطفى', 'naif' => 'نايف',
            'nayef' => 'نايف', 'qasim' => 'قاسم', 'qassim' => 'قاسم', 'talal' => 'طلال',
            'yahya' => 'يحيى', 'zaid' => 'زيد', 'zayd' => 'زيد', 'anas' => 'أنس',
            'osama' => 'أسامة', 'usama' => 'أسامة', 'marwan' => 'مروان', 'nabil' => 'نبيل',
            'raed' => 'رائد', 'raid' => 'رائد', 'sameer' => 'سمير', 'samir' => 'سمير',
            'wael' => 'وائل', 'ammar' => 'عمار', 'hani' => 'هاني', 'jassim' => 'جاسم',
            'jasim' => 'جاسم', 'maher' => 'ماهر', 'nawaf' => 'نواف', 'saif' => 'سيف',
            'suhail' => 'سهيل', 'sohail' => 'سهيل', 'thamer' => 'ثامر', 'amjad' => 'أمجد',
            'basel' => 'باسل', 'basil' => 'باسل', 'firas' => 'فراس', 'ghassan' => 'غسان',
            'hatem' => 'حاتم', 'hatim' => 'حاتم', 'iyad' => 'إياد', 'jamal' => 'جمال',
            'kamal' => 'كمال', 'laith' => 'ليث', 'layth' => 'ليث', 'mazen' => 'مازن',
            'mazin' => 'مازن', 'munir' => 'منير', 'muneer' => 'منير', 'taha' => 'طه',
            'yasser' => 'ياسر', 'yaser' => 'ياسر', 'salman' => 'سلمان', 'turki' => 'تركي',
            'mansour' => 'منصور', 'mansoor' => 'منصور', 'saud' => 'سعود', 'fawaz' => 'فواز',
            'hamza' => 'حمزة', 'ashraf' => 'أشرف', 'emad' => 'عماد', 'imad' => 'عماد',
            'essam' => 'عصام', 'hisham' => 'هشام', 'hesham' => 'هشام', 'islam' => 'إسلام',
            'khalil' => 'خليل', 'nader' => 'نادر', 'nadir' => 'نادر', 'rami' => 'رامي',
            'ramy' => 'رامي', 'sharif' => 'شريف', 'sherif' => 'شريف', 'tamer' => 'تامر',
            'zain' => 'زين', 'zein' => 'زين', 'ayoub' => 'أيوب', 'ayub' => 'أيوب',
            'saad' => 'سعد', 'jaber' => 'جابر', 'jabir' => 'جابر', 'murad' => 'مراد',
            'nizar' => 'نزار', 'shadi' => 'شادي', 'ghaith' => 'غيث', 'yazan' => 'يزن',
            'zakaria' => 'زكريا', 'zakariya' => 'زكريا', 'idris' => 'إدريس', 'harith' => 'حارث',

            // أسماء نساء
            'sara' => 'سارة', 'sarah' => 'سارة', 'fatima' => 'فاطمة', 'fatema' => 'فاطمة',
            'aisha' => 'عائشة', 'aysha' => 'عائشة', 'mariam' => 'مريم', 'maryam' => 'مريم',
            'noor' => 'نور', 'nour' => 'نور', 'layla' => 'ليلى', 'laila' => 'ليلى',
            'huda' => 'هدى', 'hoda' => 'هدى', 'amal' => 'أمل', 'rania' => 'رانيا',
            'dana' => 'دانة', 'reem' => 'ريم', 'rim' => 'ريم', 'lina' => 'لينا',
            'salma' => 'سلمى', 'hana' => 'هناء', 'hanaa' => 'هناء', 'mona' => 'منى',
            'muna' => 'منى', 'nada' => 'ندى', 'rana' => 'رنا', 'yasmin' => 'ياسمين',
            'yasmeen' => 'ياسمين', 'jasmine' => 'ياسمين', 'zainab' => 'زينب', 'zaynab' => 'زينب',
            'amira' => 'أميرة', 'ameera' => 'أميرة', 'dalal' => 'دلال', 'ghada' => 'غادة',
            'hala' => 'هالة', 'iman' => 'إيمان', 'eman' => 'إيمان', 'jana' => 'جنى',
            'khadija' => 'خديجة', 'latifa' => 'لطيفة', 'manal' => 'منال', 'nadia' => 'نادية',
            'rasha' => 'رشا', 'shaikha' => 'شيخة', 'sheikha' => 'شيخة', 'wafa' => 'وفاء',
            'yara' => 'يارا', 'asma' => 'أسماء', 'bushra' => 'بشرى', 'dima' => 'ديمة',
            'farah' => 'فرح', 'hanan' => 'حنان', 'jumana' => 'جمانة', 'lama' => 'لمى',
            'maha' => 'مها', 'naima' => 'نعيمة', 'rawan' => 'روان', 'samira' => 'سميرة',
            'sameera' => 'سميرة', 'abeer' => 'عبير', 'alia' => 'عالية', 'aliya' => 'عالية',
            'batool' => 'بتول', 'dania' => 'دانيا', 'hind' => 'هند', 'lubna' => 'لبنى',
            'malak' => 'ملك', 'rahaf' => 'رهف', 'sahar' => 'سحر', 'zahra' => 'زهراء',
            'zahraa' => 'زهراء', 'aya' => 'آية', 'duaa' => 'دعاء', 'israa' => 'إسراء',
            'marwa' => 'مروة', 'shaima' => 'شيماء', 'shaimaa' => 'شيماء', 'ruba' => 'ربى',
            'raghad' => 'رغد', 'rimas' => 'ريماس', 'joud' => 'جود', 'lian' => 'ليان',
            'talia' => 'تالياء', 'tala' => 'تالا', 'wed' => 'وعد', 'widad' => 'وداد',

            // ألقاب عُمانية/خليجية شائعة
            'balushi' => 'البلوشي', 'harthy' => 'الحارثي', 'harthi' => 'الحارثي',
            'riyami' => 'الريامي', 'habsi' => 'الحبسي', 'hinai' => 'الهنائي',
            'busaidi' => 'البوسعيدي', 'maskari' => 'المسكري', 'zadjali' => 'الزدجالي',
            'kindi' => 'الكندي', 'siyabi' => 'السيابي', 'rawahi' => 'الرواحي',
            'ghafri' => 'الغافري', 'shukaili' => 'الشكيلي', 'battashi' => 'البطاشي',
        ];
    }
}
