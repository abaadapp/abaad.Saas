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
     * يعيد النسخة العربية إن فُهمت كل كلمات الاسم، وإلا null.
     *
     * الكل أو لا شيء عن قصد: «Ahmed Al Shamsi» كان يعطي «أحمد Shamsi» —
     * اسم نصفه عربي ونصفه لاتيني، وهو أسوأ من الإنجليزي كاملًا. فما دامت
     * كلمة واحدة خارج القاموس نُعيد null ويبقى الاسم كما أُدخل.
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
                } elseif ($prefix === '' && ($glued = self::gluedPrefix($key, $map)) !== null) {
                    // بادئة ملتصقة داخل كلمة واحدة: Abdulrahim → عبدالرحيم، Alharthi → الحارثي
                    $out[] = $glued;
                    $any = true;
                } else {
                    // كلمة واحدة مجهولة تُسقط الاسم كله — لا خليط
                    return null;
                }
                $prefix = '';
            }
        }

        // بادئة معلّقة بلا اسم بعدها («Ahmed Al») — ناقص، فلا نُخرجه
        if (! $any || $prefix !== '') {
            return null;
        }

        return trim(implode(' ', $out));
    }

    /**
     * يحاول فكّ بادئة ملتصقة (عبدال…/ال…) داخل كلمة واحدة ثم يترجم الباقي إن كان معروفًا.
     * لا يُفعّل إلا حين يفشل التطابق المباشر — فلا يمسّ أسماءً كاملة مثل «Ali» أو «Alaa».
     */
    private static function gluedPrefix(string $key, array $map): ?string
    {
        // الأطول أولًا حتى لا يُلتقط «abd» قبل «abdul»
        $prefixes = ['abdul' => 'عبدال', 'abdel' => 'عبدال', 'abdal' => 'عبدال', 'al' => 'ال', 'el' => 'ال'];
        foreach ($prefixes as $pfx => $ar) {
            if (str_starts_with($key, $pfx) && strlen($key) > strlen($pfx)) {
                $rest = substr($key, strlen($pfx));
                if (isset($map[$rest])) {
                    $val = $map[$rest];
                    if (str_ends_with($ar, 'ال') && str_starts_with($val, 'ال')) {
                        $val = mb_substr($val, 2);
                    }
                    return $ar . $val;
                }
            }
        }

        return null;
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

            // ===== توسعة كبيرة: أسماء رجال إضافية =====
            'abbas' => 'عباس', 'abdo' => 'عبده', 'adnan' => 'عدنان', 'akram' => 'أكرم',
            'alaa' => 'علاء', 'amin' => 'أمين', 'ameen' => 'أمين', 'amir' => 'أمير',
            'ameer' => 'أمير', 'anwar' => 'أنور', 'arif' => 'عارف', 'aref' => 'عارف',
            'asad' => 'أسد', 'assad' => 'أسد', 'atif' => 'عاطف', 'atef' => 'عاطف',
            'awad' => 'عوض', 'ayham' => 'أيهم', 'azzam' => 'عزام', 'baha' => 'بهاء',
            'bahaa' => 'بهاء', 'bakr' => 'بكر', 'bandar' => 'بندر', 'bashar' => 'بشار',
            'basem' => 'باسم', 'bassem' => 'باسم', 'bassam' => 'بسام', 'burhan' => 'برهان',
            'dawood' => 'داود', 'dawoud' => 'داود', 'daoud' => 'داود', 'diaa' => 'ضياء',
            'diya' => 'ضياء', 'elias' => 'إلياس', 'ilyas' => 'إلياس', 'fadi' => 'فادي',
            'fadel' => 'فاضل', 'fadl' => 'فضل', 'fahim' => 'فهيم', 'fares' => 'فارس',
            'faris' => 'فارس', 'farhan' => 'فرحان', 'farid' => 'فريد', 'fareed' => 'فريد',
            'fathi' => 'فتحي', 'fawzi' => 'فوزي', 'fayez' => 'فايز', 'fayiz' => 'فايز',
            'fouad' => 'فؤاد', 'fuad' => 'فؤاد', 'ghazi' => 'غازي', 'habib' => 'حبيب',
            'hadi' => 'هادي', 'haidar' => 'حيدر', 'haider' => 'حيدر', 'haitham' => 'هيثم',
            'haytham' => 'هيثم', 'halim' => 'حليم', 'hamdan' => 'حمدان', 'hamdi' => 'حمدي',
            'hammad' => 'حماد', 'hamoud' => 'حمود', 'harb' => 'حرب', 'haroun' => 'هارون',
            'harun' => 'هارون', 'hazem' => 'حازم', 'hazim' => 'حازم', 'hilal' => 'هلال',
            'humaid' => 'حميد', 'husam' => 'حسام', 'hussam' => 'حسام', 'ihab' => 'إيهاب',
            'imran' => 'عمران', 'omran' => 'عمران', 'isa' => 'عيسى', 'issa' => 'عيسى',
            'jad' => 'جاد', 'jalal' => 'جلال', 'jamil' => 'جميل', 'jameel' => 'جميل',
            'jawad' => 'جواد', 'jibril' => 'جبريل', 'kadhim' => 'كاظم', 'kamil' => 'كامل',
            'khairi' => 'خيري', 'khairy' => 'خيري', 'khaldoun' => 'خلدون', 'labib' => 'لبيب',
            'loai' => 'لؤي', 'louay' => 'لؤي', 'luay' => 'لؤي', 'lutfi' => 'لطفي',
            'maan' => 'معن', 'mahdi' => 'مهدي', 'makki' => 'مكي', 'malek' => 'مالك',
            'malik' => 'مالك', 'mamdouh' => 'ممدوح', 'marzouq' => 'مرزوق', 'masoud' => 'مسعود',
            'mesbah' => 'مصباح', 'miqdad' => 'مقداد', 'moaz' => 'معاذ', 'muath' => 'معاذ',
            'mohsen' => 'محسن', 'mohsin' => 'محسن', 'mokhtar' => 'مختار', 'mukhtar' => 'مختار',
            'montaser' => 'منتصر', 'muntasir' => 'منتصر', 'morad' => 'مراد', 'mourad' => 'مراد',
            'murad' => 'مراد', 'mubarak' => 'مبارك', 'muhannad' => 'مهند', 'mohannad' => 'مهند',
            'mujahid' => 'مجاهد', 'mundhir' => 'منذر', 'munther' => 'منذر', 'musa' => 'موسى',
            'mousa' => 'موسى', 'mutaz' => 'معتز', 'moutaz' => 'معتز', 'muayad' => 'مؤيد',
            'muwaffaq' => 'موفق', 'nabhan' => 'نبهان', 'naeem' => 'نعيم', 'naim' => 'نعيم',
            'naji' => 'ناجي', 'najeeb' => 'نجيب', 'najib' => 'نجيب', 'nashat' => 'نشأت',
            'nazih' => 'نزيه', 'nazeeh' => 'نزيه', 'nazim' => 'ناظم', 'nazem' => 'ناظم',
            'nidal' => 'نضال', 'noman' => 'نعمان', 'numan' => 'نعمان', 'nouh' => 'نوح',
            'nuh' => 'نوح', 'othman' => 'عثمان', 'uthman' => 'عثمان', 'qais' => 'قيس',
            'qusai' => 'قصي', 'qusay' => 'قصي', 'rabie' => 'ربيع', 'rabih' => 'ربيع',
            'rafat' => 'رأفت', 'rafeeq' => 'رفيق', 'rafiq' => 'رفيق', 'ragheb' => 'راغب',
            'raghib' => 'راغب', 'rakan' => 'ركان', 'ramadan' => 'رمضان', 'ramez' => 'رامز',
            'ramiz' => 'رامز', 'rateb' => 'راتب', 'rauf' => 'رؤوف', 'raouf' => 'رؤوف',
            'rayan' => 'ريان', 'rayyan' => 'ريان', 'redwan' => 'رضوان', 'ridwan' => 'رضوان',
            'rizq' => 'رزق', 'saber' => 'صابر', 'sabir' => 'صابر', 'sabri' => 'صبري',
            'sadeq' => 'صادق', 'sadiq' => 'صادق', 'sadek' => 'صادق', 'safwan' => 'صفوان',
            'sahl' => 'سهل', 'sajid' => 'ساجد', 'saqr' => 'صقر', 'saker' => 'صقر',
            'salah' => 'صلاح', 'saleh' => 'صالح', 'salih' => 'صالح', 'samih' => 'سميح',
            'sameeh' => 'سميح', 'sanad' => 'سند', 'sari' => 'ساري', 'sarmad' => 'سرمد',
            'shaaban' => 'شعبان', 'shaban' => 'شعبان', 'shady' => 'شادي', 'shaker' => 'شاكر',
            'shakir' => 'شاكر', 'sharaf' => 'شرف', 'shawqi' => 'شوقي', 'shihab' => 'شهاب',
            'sohaib' => 'صهيب', 'suhaib' => 'صهيب', 'sufyan' => 'سفيان', 'sofyan' => 'سفيان',
            'suleiman' => 'سليمان', 'sulaiman' => 'سليمان', 'tahir' => 'طاهر', 'taher' => 'طاهر',
            'talaat' => 'طلعت', 'talat' => 'طلعت', 'tamim' => 'تميم', 'tammam' => 'تمام',
            'taufiq' => 'توفيق', 'tawfiq' => 'توفيق', 'tawfik' => 'توفيق', 'thabit' => 'ثابت',
            'thaer' => 'ثائر', 'ubada' => 'عبادة', 'ubaida' => 'عبيدة', 'obaida' => 'عبيدة',
            'wadih' => 'وديع', 'wadie' => 'وديع', 'wahid' => 'وحيد', 'waheed' => 'وحيد',
            'wajdi' => 'وجدي', 'wajih' => 'وجيه', 'wasim' => 'وسيم', 'waseem' => 'وسيم',
            'wisam' => 'وسام', 'wissam' => 'وسام', 'yaman' => 'يمن', 'yamen' => 'يامن',
            'yaqoub' => 'يعقوب', 'yacoub' => 'يعقوب', 'yaqub' => 'يعقوب', 'yasin' => 'ياسين',
            'yaseen' => 'ياسين', 'younes' => 'يونس', 'younis' => 'يونس', 'yunus' => 'يونس',
            'zafer' => 'ظافر', 'zaher' => 'زاهر', 'zahir' => 'زاهر', 'zaki' => 'زكي',
            'zohair' => 'زهير', 'zuhair' => 'زهير', 'zubair' => 'زبير', 'zubeir' => 'زبير',
            'ayoob' => 'أيوب', 'ghaith' => 'غيث', 'yazan' => 'يزن', 'harith' => 'حارث',
            'muntasser' => 'منتصر', 'moataz' => 'معتز', 'nashaat' => 'نشأت', 'wael' => 'وائل',

            // ===== توسعة كبيرة: أسماء نساء إضافية =====
            'abrar' => 'أبرار', 'afaf' => 'عفاف', 'afnan' => 'أفنان', 'amani' => 'أماني',
            'amna' => 'آمنة', 'amina' => 'آمنة', 'aminah' => 'آمنة', 'anfal' => 'أنفال',
            'areej' => 'أريج', 'arwa' => 'أروى', 'aseel' => 'أسيل', 'asil' => 'أسيل',
            'athar' => 'أثير', 'atheer' => 'أثير', 'ayah' => 'آية', 'azza' => 'عزة',
            'balqis' => 'بلقيس', 'bayan' => 'بيان', 'basma' => 'بسمة', 'basima' => 'باسمة',
            'buthaina' => 'بثينة', 'buthayna' => 'بثينة', 'dalia' => 'داليا', 'daliah' => 'داليا',
            'danah' => 'دانة', 'deema' => 'ديمة', 'diana' => 'ديانا', 'doaa' => 'دعاء',
            'dua' => 'دعاء', 'duha' => 'ضحى', 'elham' => 'إلهام', 'ilham' => 'إلهام',
            'enas' => 'إيناس', 'inas' => 'إيناس', 'esraa' => 'إسراء', 'fadwa' => 'فدوى',
            'fadia' => 'فادية', 'fadya' => 'فادية', 'fairuz' => 'فيروز', 'fayrouz' => 'فيروز',
            'fatin' => 'فاتن', 'faten' => 'فاتن', 'fida' => 'فداء', 'firdaus' => 'فردوس',
            'ghaida' => 'غيداء', 'ghaydaa' => 'غيداء', 'ghina' => 'غنى', 'habiba' => 'حبيبة',
            'hadeel' => 'هديل', 'hadil' => 'هديل', 'hajar' => 'هاجر', 'hajer' => 'هاجر',
            'halima' => 'حليمة', 'hasna' => 'حسناء', 'hessa' => 'حصة', 'hessah' => 'حصة',
            'hiba' => 'هبة', 'hibah' => 'هبة', 'ibtihal' => 'ابتهال', 'jamila' => 'جميلة',
            'jameela' => 'جميلة', 'jawaher' => 'جواهر', 'jawahir' => 'جواهر', 'jenan' => 'جنان',
            'jinan' => 'جنان', 'juman' => 'جمان', 'jumanah' => 'جمانة', 'kawthar' => 'كوثر',
            'kholoud' => 'خلود', 'khuloud' => 'خلود', 'lamees' => 'لميس', 'lamis' => 'لميس',
            'lamia' => 'لمياء', 'lamya' => 'لمياء', 'lana' => 'لانا', 'lara' => 'لارا',
            'leen' => 'لين', 'leena' => 'لينا', 'lujain' => 'لجين', 'lujayn' => 'لجين',
            'madiha' => 'مديحة', 'maisa' => 'ميساء', 'maysa' => 'ميساء', 'maysoon' => 'ميسون',
            'maysun' => 'ميسون', 'malika' => 'مليكة', 'manar' => 'منار', 'maram' => 'مرام',
            'mayada' => 'ميادة', 'mayar' => 'ميار', 'maysam' => 'ميسم', 'mervat' => 'ميرفت',
            'mirvat' => 'ميرفت', 'munira' => 'منيرة', 'muneera' => 'منيرة', 'nabila' => 'نبيلة',
            'nadine' => 'نادين', 'nagham' => 'نغم', 'nahla' => 'نهلة', 'najat' => 'نجاة',
            'najla' => 'نجلاء', 'najwa' => 'نجوى', 'nawal' => 'نوال', 'nawar' => 'نوار',
            'nazik' => 'نازك', 'nesreen' => 'نسرين', 'nisreen' => 'نسرين', 'nihad' => 'نهاد',
            'noha' => 'نهى', 'nuha' => 'نهى', 'nojoud' => 'نجود', 'nujoud' => 'نجود',
            'ola' => 'علا', 'ula' => 'علا', 'rabab' => 'رباب', 'radwa' => 'رضوى',
            'raheeq' => 'رحيق', 'raneem' => 'رنيم', 'raneen' => 'رنين', 'rawda' => 'روضة',
            'razan' => 'رزان', 'reema' => 'ريما', 'rima' => 'ريما', 'reham' => 'ريهام',
            'riham' => 'ريهام', 'rehab' => 'رحاب', 'rihab' => 'رحاب', 'rita' => 'ريتا',
            'rola' => 'رولا', 'roula' => 'رولا', 'rowaida' => 'رويدة', 'ruwaida' => 'رويدة',
            'rua' => 'رؤى', 'ruaa' => 'رؤى', 'rukaya' => 'رقية', 'ruqaya' => 'رقية',
            'ruqayya' => 'رقية', 'saba' => 'صبا', 'sabah' => 'صباح', 'sadaf' => 'صدف',
            'safa' => 'صفا', 'safaa' => 'صفاء', 'saja' => 'سجى', 'sajida' => 'ساجدة',
            'salsabil' => 'سلسبيل', 'samah' => 'سماح', 'samar' => 'سمر', 'sana' => 'سناء',
            'sanaa' => 'سناء', 'sandra' => 'ساندرا', 'sawsan' => 'سوسن', 'shada' => 'شذى',
            'shaza' => 'شذى', 'shatha' => 'شذى', 'shahd' => 'شهد', 'shahad' => 'شهد',
            'sherine' => 'شيرين', 'shireen' => 'شيرين', 'shirin' => 'شيرين', 'sireen' => 'سيرين',
            'sondos' => 'سندس', 'sundus' => 'سندس', 'suad' => 'سعاد', 'souad' => 'سعاد',
            'suha' => 'سهى', 'suhad' => 'سهاد', 'sumaya' => 'سمية', 'sumayya' => 'سمية',
            'tahani' => 'تهاني', 'taghreed' => 'تغريد', 'taghrid' => 'تغريد', 'tasneem' => 'تسنيم',
            'tasnim' => 'تسنيم', 'thanaa' => 'ثناء', 'thuraya' => 'ثريا', 'wadad' => 'وداد',
            'wafaa' => 'وفاء', 'warda' => 'وردة', 'wardah' => 'وردة', 'wijdan' => 'وجدان',
            'yasmine' => 'ياسمين', 'zaina' => 'زينة', 'zeina' => 'زينة', 'zayna' => 'زينة',
            'zeinab' => 'زينب', 'zeena' => 'زينة', 'lian' => 'ليان', 'lien' => 'ليان',
            'talin' => 'تالين', 'taleen' => 'تالين', 'jori' => 'جوري', 'joury' => 'جوري',
            'retaj' => 'ريتاج', 'sedra' => 'سدرة', 'sidra' => 'سدرة', 'watan' => 'وطن',

            // ===== توسعة ثالثة: رجال =====
            'aws' => 'أوس', 'ayser' => 'أيسر', 'bishr' => 'بشر', 'dirar' => 'ضرار',
            'ehab' => 'إيهاب', 'fayadh' => 'فياض', 'ghaleb' => 'غالب', 'ghalib' => 'غالب',
            'ghanim' => 'غانم', 'ghannam' => 'غنام', 'hamdoon' => 'حمدون', 'harbi' => 'حربي',
            'hashim' => 'هاشم', 'hashem' => 'هاشم', 'hassaan' => 'حسان', 'hayyan' => 'حيان',
            'hilmi' => 'حلمي', 'hudhaifa' => 'حذيفة', 'hudhayfah' => 'حذيفة', 'ihsan' => 'إحسان',
            'iyas' => 'إياس', 'jabr' => 'جبر', 'jalil' => 'جليل', 'jarir' => 'جرير',
            'jawdat' => 'جودت', 'jihad' => 'جهاد', 'kanaan' => 'كنعان', 'kanan' => 'كنعان',
            'kayed' => 'كايد', 'khader' => 'خضر', 'khadr' => 'خضر', 'khidr' => 'خضر',
            'khattab' => 'خطاب', 'mamoun' => 'مأمون', 'maamoun' => 'مأمون', 'mahfouz' => 'محفوظ',
            'mahjoub' => 'محجوب', 'maisara' => 'ميسرة', 'maysara' => 'ميسرة', 'majd' => 'مجد',
            'majdi' => 'مجدي', 'maroof' => 'معروف', 'marouf' => 'معروف', 'masood' => 'مسعود',
            'matar' => 'مطر', 'maytham' => 'ميثم', 'maitham' => 'ميثم', 'miqdam' => 'مقدام',
            'mishari' => 'مشاري', 'mufeed' => 'مفيد', 'mufid' => 'مفيد', 'muin' => 'معين',
            'mueen' => 'معين', 'mulham' => 'ملهم', 'muntadhar' => 'منتظر', 'muntazar' => 'منتظر',
            'muqbil' => 'مقبل', 'musaab' => 'مصعب', 'musab' => 'مصعب', 'mussab' => 'مصعب',
            'musaid' => 'مساعد', 'musaed' => 'مساعد', 'muslim' => 'مسلم', 'mutasim' => 'معتصم',
            'mutasem' => 'معتصم', 'motasem' => 'معتصم', 'muthanna' => 'مثنى', 'nabeel' => 'نبيل',
            'nadeem' => 'نديم', 'nadim' => 'نديم', 'nael' => 'نائل', 'nail' => 'نائل',
            'nafie' => 'نافع', 'naseem' => 'نسيم', 'nasim' => 'نسيم', 'nawfal' => 'نوفل',
            'nazar' => 'نزار', 'nazmi' => 'نظمي', 'nibras' => 'نبراس', 'nimr' => 'نمر',
            'nusair' => 'نصير', 'obaidullah' => 'عبيدالله', 'omair' => 'عمير', 'omayr' => 'عمير',
            'osaid' => 'أسيد', 'qahtan' => 'قحطان', 'radi' => 'راضي', 'raef' => 'رائف',
            'rafi' => 'رافع', 'rafe' => 'رافع', 'ramzi' => 'رمزي', 'rashad' => 'رشاد',
            'rayhan' => 'ريحان', 'raihan' => 'ريحان', 'rifaat' => 'رفعت', 'refaat' => 'رفعت',
            'rifat' => 'رفعت', 'ruslan' => 'رسلان', 'saadi' => 'سعدي', 'sadun' => 'سعدون',
            'safwat' => 'صفوت', 'sajjad' => 'سجاد', 'salamah' => 'سلامة', 'salama' => 'سلامة',
            'saleem' => 'سليم', 'sameh' => 'سامح', 'sattam' => 'سطام', 'shaddad' => 'شداد',
            'shafiq' => 'شفيق', 'shafeeq' => 'شفيق', 'shaheen' => 'شاهين', 'shahin' => 'شاهين',
            'shakib' => 'شكيب', 'shamekh' => 'شامخ', 'shoaib' => 'شعيب', 'shuaib' => 'شعيب',
            'shukri' => 'شكري', 'sinan' => 'سنان', 'siraj' => 'سراج', 'subhi' => 'صبحي',
            'surur' => 'سرور', 'tahseen' => 'تحسين', 'tahsin' => 'تحسين', 'talha' => 'طلحة',
            'taqi' => 'تقي', 'thamir' => 'ثامر', 'uday' => 'عدي', 'oday' => 'عدي',
            'wahb' => 'وهب', 'watheq' => 'واثق', 'wathiq' => 'واثق', 'yaqzan' => 'يقظان',
            'yasar' => 'يسار', 'yazeed' => 'يزيد', 'yazid' => 'يزيد', 'zahran' => 'زهران',
            'zamil' => 'زامل', 'zayed' => 'زايد', 'zaydan' => 'زيدان', 'zaidan' => 'زيدان',
            'muayyad' => 'مؤيد', 'mudar' => 'مضر', 'saad' => 'سعد',

            // ===== توسعة ثالثة: نساء =====
            'aida' => 'عايدة', 'aleen' => 'ألين', 'aleena' => 'ألينا', 'alya' => 'علياء',
            'alyaa' => 'علياء', 'anaya' => 'أنايا', 'anhar' => 'أنهار', 'anisa' => 'أنيسة',
            'anoud' => 'العنود', 'anwaar' => 'أنوار', 'areen' => 'أرين', 'ashwaq' => 'أشواق',
            'asayel' => 'أصايل', 'asmaa' => 'أسماء', 'awatef' => 'عواطف', 'awatif' => 'عواطف',
            'ayesha' => 'عائشة', 'aysha' => 'عائشة', 'aziza' => 'عزيزة', 'badriya' => 'بدرية',
            'bahija' => 'بهيجة', 'baraa' => 'براءة', 'basmala' => 'بسملة', 'bashayer' => 'بشاير',
            'bashaer' => 'بشاير', 'batoul' => 'بتول', 'bidour' => 'بدور', 'budour' => 'بدور',
            'budur' => 'بدور', 'danya' => 'دانيا', 'dareen' => 'دارين', 'darin' => 'دارين',
            'dhikra' => 'ذكرى', 'dina' => 'دينا', 'doha' => 'ضحى', 'faiza' => 'فايزة',
            'fayza' => 'فايزة', 'fatoom' => 'فطوم', 'fawzia' => 'فوزية', 'ferial' => 'فريال',
            'feryal' => 'فريال', 'ghadeer' => 'غدير', 'ghalia' => 'غالية', 'ghalya' => 'غالية',
            'ghazal' => 'غزل', 'ghazlan' => 'غزلان', 'hadeer' => 'هدير', 'hadir' => 'هدير',
            'hafsa' => 'حفصة', 'haifa' => 'هيفاء', 'hayfa' => 'هيفاء', 'hanadi' => 'هنادي',
            'hawra' => 'حوراء', 'hawraa' => 'حوراء', 'hayat' => 'حياة', 'hazar' => 'هزار',
            'hoor' => 'حور', 'inaam' => 'إنعام', 'inam' => 'إنعام', 'intisar' => 'انتصار',
            'ithar' => 'إيثار', 'janat' => 'جنات', 'jannah' => 'جنة', 'jenna' => 'جنة',
            'jood' => 'جود', 'judy' => 'جودي', 'jouri' => 'جوري', 'julnar' => 'جلنار',
            'kamila' => 'كاملة', 'karima' => 'كريمة', 'kareema' => 'كريمة', 'khadra' => 'خضراء',
            'khawla' => 'خولة', 'khawlah' => 'خولة', 'layan' => 'ليان', 'layali' => 'ليالي',
            'loulwa' => 'لولوة', 'lulua' => 'لؤلؤة', 'lulu' => 'لولو', 'madeeha' => 'مديحة',
            'mahra' => 'مهرة', 'malath' => 'ملاذ', 'malaz' => 'ملاذ', 'manahil' => 'مناهل',
            'mai' => 'مي', 'maya' => 'مايا', 'meera' => 'ميرة', 'mira' => 'ميرة',
            'mazoon' => 'مزون', 'muzun' => 'مزون', 'mazyona' => 'مزيونة', 'miral' => 'ميرال',
            'mirna' => 'ميرنا', 'nariman' => 'ناريمان', 'nashwa' => 'نشوى', 'najah' => 'نجاح',
            'nawras' => 'نورس', 'nermeen' => 'نيرمين', 'nesma' => 'نسمة', 'nibal' => 'نبال',
            'nouf' => 'نوف', 'oroub' => 'عروب', 'rand' => 'رند', 'randa' => 'رندة',
            'rafif' => 'رفيف', 'rafeef' => 'رفيف', 'rahma' => 'رحمة', 'raja' => 'رجاء',
            'rajaa' => 'رجاء', 'rasheda' => 'رشيدة', 'raya' => 'رايا', 'ritaj' => 'ريتاج',
            'rudaina' => 'ردينة', 'safiya' => 'صفية', 'safiyah' => 'صفية', 'sakina' => 'سكينة',
            'salwa' => 'سلوى', 'samia' => 'سامية', 'samya' => 'سامية', 'seham' => 'سهام',
            'siham' => 'سهام', 'sujood' => 'سجود', 'suzan' => 'سوزان', 'tabarak' => 'تبارك',
            'tamara' => 'تمارا', 'taqwa' => 'تقوى', 'tarteel' => 'ترتيل', 'ulfat' => 'ألفت',
            'wajd' => 'وجد', 'wasan' => 'وسن', 'wisal' => 'وصال', 'wesal' => 'وصال',
            'yaqeen' => 'يقين', 'yosra' => 'يسرى', 'yusra' => 'يسرى', 'yumna' => 'يمنى',
            'zahia' => 'زاهية', 'zakia' => 'زكية', 'zakiya' => 'زكية', 'zeenat' => 'زينات',
            'zina' => 'زينة', 'ziyana' => 'زيانة', 'zumurud' => 'زمرد', 'wiam' => 'وئام',
            'reef' => 'ريف', 'sadeel' => 'سديل', 'raghdan' => 'رغدان', 'aleya' => 'عليا',

            // ألقاب عُمانية/خليجية شائعة
            'balushi' => 'البلوشي', 'harthy' => 'الحارثي', 'harthi' => 'الحارثي',
            'riyami' => 'الريامي', 'habsi' => 'الحبسي', 'hinai' => 'الهنائي',
            'busaidi' => 'البوسعيدي', 'maskari' => 'المسكري', 'zadjali' => 'الزدجالي',
            'kindi' => 'الكندي', 'siyabi' => 'السيابي', 'rawahi' => 'الرواحي',
            'ghafri' => 'الغافري', 'shukaili' => 'الشكيلي', 'battashi' => 'البطاشي',
            'amri' => 'العامري', 'farsi' => 'الفارسي', 'wahaibi' => 'الوهيبي',
            'ghaithi' => 'الغيثي', 'saadi' => 'السعدي', 'nabhani' => 'النبهاني',
            'mahrouqi' => 'المحروقي', 'rashdi' => 'الراشدي', 'hosni' => 'الحوسني',
            'alawi' => 'العلوي', 'maamari' => 'المعمري', 'lawati' => 'اللواتي',
            'ajmi' => 'العجمي', 'shanfari' => 'الشنفري', 'kharusi' => 'الخروصي',
            'rahbi' => 'الرحبي', 'abri' => 'العبري', 'nadabi' => 'الندابي',
            'toubi' => 'الطوبي', 'jabri' => 'الجابري', 'yahyai' => 'اليحيائي',
            'fazari' => 'الفزاري', 'salmi' => 'السالمي', 'barwani' => 'البرواني',
            'mukhaini' => 'المخيني', 'hajri' => 'الهاجري', 'qasmi' => 'القاسمي',
            'shibli' => 'الشبلي', 'raisi' => 'الرئيسي', 'balochi' => 'البلوشي',
            'zaabi' => 'الزعابي', 'harrasi' => 'الحراصي', 'mamari' => 'المعمري',
            'wahaili' => 'الوهيبي', 'saidi' => 'السعيدي', 'maawali' => 'المعولي',
            'shizawi' => 'الشيزاوي', 'brashdi' => 'البراشدي', 'ismaili' => 'الإسماعيلي',

            // توسعة الألقاب: بعد أن صارت الترجمة «الكل أو لا شيء»، أي لقب
            // مفقود يُسقط الاسم كاملًا — فتغطية الألقاب صارت أهم من قبل.
            'shamsi' => 'الشامسي', 'shamsy' => 'الشامسي', 'sinani' => 'السناني',
            'mandhari' => 'المنذري', 'jahwari' => 'الجهوري', 'khusaibi' => 'الخصيبي',
            'azri' => 'العذري', 'badi' => 'البادي', 'hashmi' => 'الهاشمي',
            'hatmi' => 'الحاتمي', 'khaldi' => 'الخالدي', 'adawi' => 'العدوي',
            'muqbali' => 'المقبالي', 'naamani' => 'النعماني', 'khanjari' => 'الخنجري',
            'yaqoubi' => 'اليعقوبي', 'kiyumi' => 'الكيومي', 'sabhi' => 'الصبحي',
            'dhuhli' => 'الظهلي', 'aufi' => 'العوفي', 'saifi' => 'السيفي',
            'sawafi' => 'الصوافي', 'hadidi' => 'الحديدي', 'jahdhami' => 'الجهضمي',
            'rasbi' => 'الراسبي', 'bahri' => 'البحري', 'shuaili' => 'الشعيلي',
            'shaqsi' => 'الشقصي', 'hakmani' => 'الحكماني', 'abdali' => 'العبدلي',
            'rushaidi' => 'الرشيدي', 'hasani' => 'الحسني', 'yafai' => 'اليافعي',
            'breiki' => 'البريكي', 'omairi' => 'العميري', 'harmali' => 'الهرمالي',
            'ruzaiqi' => 'الرزيقي', 'waili' => 'الوائلي', 'shihi' => 'الشحي',
            'naabi' => 'النعبي', 'musalmi' => 'المسلمي', 'qassabi' => 'القصابي',

            // ألقاب خليجية وعربية شائعة بين المقيمين
            'ansari' => 'الأنصاري', 'khalili' => 'الخليلي', 'qurashi' => 'القرشي',
            'hamdani' => 'الهمداني', 'shariqi' => 'الشارقي', 'tamimi' => 'التميمي',
            'dosari' => 'الدوسري', 'mutairi' => 'المطيري', 'anzi' => 'العنزي',
            'shammari' => 'الشمري', 'otaibi' => 'العتيبي', 'qahtani' => 'القحطاني',
            'ghamdi' => 'الغامدي', 'zahrani' => 'الزهراني', 'juma' => 'جمعة',

            // ===== توسعة دولية: أسماء غربية شائعة (رجال) — لعملاء الجاليات =====
            'john' => 'جون', 'michael' => 'مايكل', 'mike' => 'مايك', 'david' => 'ديفيد',
            'james' => 'جيمس', 'jim' => 'جيم', 'robert' => 'روبرت', 'rob' => 'روب',
            'william' => 'ويليام', 'will' => 'ويل', 'richard' => 'ريتشارد', 'rick' => 'ريك',
            'charles' => 'تشارلز', 'charlie' => 'تشارلي', 'thomas' => 'توماس', 'tom' => 'توم',
            'daniel' => 'دانيال', 'mark' => 'مارك', 'paul' => 'بول', 'george' => 'جورج',
            'steven' => 'ستيفن', 'stephen' => 'ستيفن', 'steve' => 'ستيف', 'edward' => 'إدوارد',
            'brian' => 'برايان', 'kevin' => 'كيفن', 'jason' => 'جيسون', 'jeffrey' => 'جيفري',
            'jeff' => 'جيف', 'gary' => 'غاري', 'frank' => 'فرانك', 'eric' => 'إريك',
            'andrew' => 'أندرو', 'andy' => 'أندي', 'ryan' => 'راين', 'joshua' => 'جوشوا',
            'patrick' => 'باتريك', 'peter' => 'بيتر', 'henry' => 'هنري', 'carl' => 'كارل',
            'arthur' => 'آرثر', 'roger' => 'روجر', 'keith' => 'كيث', 'jeremy' => 'جيرمي',
            'sean' => 'شون', 'shaun' => 'شون', 'louis' => 'لويس', 'philip' => 'فيليب',
            'phillip' => 'فيليب', 'adam' => 'آدم', 'harry' => 'هاري', 'jack' => 'جاك',
            'alex' => 'أليكس', 'alexander' => 'ألكسندر', 'anthony' => 'أنتوني', 'tony' => 'توني',
            'christopher' => 'كريستوفر', 'chris' => 'كريس', 'matthew' => 'ماثيو', 'matt' => 'مات',
            'nicholas' => 'نيكولاس', 'nick' => 'نيك', 'jonathan' => 'جوناثان', 'benjamin' => 'بنجامين',
            'samuel' => 'صموئيل', 'gregory' => 'غريغوري', 'greg' => 'غريغ', 'oliver' => 'أوليفر',
            'leo' => 'ليو', 'max' => 'ماكس', 'simon' => 'سايمون', 'oscar' => 'أوسكار',
            'felix' => 'فيليكس', 'victor' => 'فيكتور', 'martin' => 'مارتن', 'marcus' => 'ماركوس',
            'aaron' => 'آرون', 'nathan' => 'ناثان', 'ian' => 'إيان', 'neil' => 'نيل',
            'jordan' => 'جوردان', 'cameron' => 'كاميرون', 'dylan' => 'ديلان', 'ethan' => 'إيثان',
            'logan' => 'لوغان', 'lucas' => 'لوكاس', 'jacob' => 'جيكوب', 'luke' => 'لوك',
            'connor' => 'كونور', 'sam' => 'سام', 'dennis' => 'دينيس',
            'walter' => 'والتر', 'raymond' => 'رايموند', 'ronald' => 'رونالد', 'donald' => 'دونالد',
            'jerry' => 'جيري', 'wayne' => 'واين', 'roy' => 'روي', 'ralph' => 'رالف',
            'joe' => 'جو', 'joseph' => 'جوزيف', 'bruce' => 'بروس', 'billy' => 'بيلي',

            // ===== توسعة دولية: أسماء غربية شائعة (نساء) =====
            'mary' => 'ماري', 'patricia' => 'باتريشيا', 'jennifer' => 'جينيفر', 'linda' => 'ليندا',
            'elizabeth' => 'إليزابيث', 'barbara' => 'باربرا', 'susan' => 'سوزان', 'jessica' => 'جيسيكا',
            'karen' => 'كارين', 'nancy' => 'نانسي', 'betty' => 'بيتي', 'helen' => 'هيلين',
            'donna' => 'دونا', 'carol' => 'كارول', 'ruth' => 'روث', 'sharon' => 'شارون',
            'michelle' => 'ميشيل', 'laura' => 'لورا', 'kimberly' => 'كيمبرلي', 'amy' => 'إيمي',
            'angela' => 'أنجيلا', 'ashley' => 'آشلي', 'emma' => 'إيما', 'olivia' => 'أوليفيا',
            'emily' => 'إيميلي', 'sophia' => 'صوفيا', 'sophie' => 'صوفي', 'isabella' => 'إيزابيلا',
            'mia' => 'ميا', 'charlotte' => 'شارلوت', 'amelia' => 'أميليا', 'grace' => 'غريس',
            'chloe' => 'كلوي', 'ella' => 'إيلا', 'lily' => 'ليلي', 'hannah' => 'هانا',
            'lucy' => 'لوسي', 'alice' => 'أليس', 'julia' => 'جوليا', 'anna' => 'آنا',
            'ana' => 'آنا', 'maria' => 'ماريا', 'elena' => 'إيلينا', 'rose' => 'روز',
            'victoria' => 'فيكتوريا', 'catherine' => 'كاثرين', 'katherine' => 'كاثرين', 'kate' => 'كيت',
            'christine' => 'كريستين', 'christina' => 'كريستينا', 'samantha' => 'سامانثا', 'rebecca' => 'ريبيكا',
            'rachel' => 'ريتشل', 'megan' => 'ميغان', 'nicole' => 'نيكول', 'stephanie' => 'ستيفاني',
            'natalie' => 'ناتالي', 'vanessa' => 'فانيسا', 'gloria' => 'غلوريا', 'teresa' => 'تيريزا',
            'andrea' => 'أندريا', 'jane' => 'جين', 'jean' => 'جين', 'ann' => 'آن',
            'marie' => 'ماري', 'monica' => 'مونيكا', 'julie' => 'جولي', 'melissa' => 'ميليسا',
            'kelly' => 'كيلي', 'lisa' => 'ليزا', 'sandy' => 'ساندي', 'tina' => 'تينا',

            // ===== توسعة دولية: ألقاب غربية شائعة =====
            'smith' => 'سميث', 'johnson' => 'جونسون', 'williams' => 'ويليامز', 'brown' => 'براون',
            'jones' => 'جونز', 'garcia' => 'غارسيا', 'miller' => 'ميلر', 'davis' => 'ديفيس',
            'rodriguez' => 'رودريغيز', 'martinez' => 'مارتينيز', 'hernandez' => 'هيرنانديز', 'lopez' => 'لوبيز',
            'gonzalez' => 'غونزاليز', 'wilson' => 'ويلسون', 'anderson' => 'أندرسون', 'taylor' => 'تايلور',
            'moore' => 'مور', 'jackson' => 'جاكسون', 'white' => 'وايت', 'harris' => 'هاريس',
            'clark' => 'كلارك', 'lewis' => 'لويس', 'robinson' => 'روبنسون', 'walker' => 'ووكر',
            'young' => 'يونغ', 'allen' => 'آلن', 'king' => 'كينغ', 'wright' => 'رايت',
            'hill' => 'هيل', 'green' => 'غرين', 'adams' => 'آدمز', 'baker' => 'بيكر',
            'nelson' => 'نيلسون', 'carter' => 'كارتر', 'mitchell' => 'ميتشل', 'roberts' => 'روبرتس',
            'turner' => 'تيرنر', 'phillips' => 'فيليبس', 'campbell' => 'كامبل', 'parker' => 'باركر',
            'evans' => 'إيفانز', 'edwards' => 'إدواردز', 'collins' => 'كولينز', 'stewart' => 'ستيوارت',
            'morris' => 'موريس', 'murphy' => 'ميرفي', 'cook' => 'كوك', 'rogers' => 'روجرز',
            'morgan' => 'مورغان', 'cooper' => 'كوبر', 'peterson' => 'بيترسون', 'bailey' => 'بيلي',
            'reed' => 'ريد', 'howard' => 'هوارد', 'cox' => 'كوكس', 'ward' => 'وارد',
            'watson' => 'واتسون', 'brooks' => 'بروكس', 'bennett' => 'بينيت', 'gray' => 'غراي',
            'cruz' => 'كروز', 'hughes' => 'هيوز', 'price' => 'برايس', 'long' => 'لونغ',
            'foster' => 'فوستر', 'sanders' => 'ساندرز', 'ross' => 'روس', 'powell' => 'باول',
            'russell' => 'راسل', 'perry' => 'بيري', 'butler' => 'بتلر', 'barnes' => 'بارنز',
            'fisher' => 'فيشر', 'henderson' => 'هندرسون', 'coleman' => 'كولمان', 'jenkins' => 'جينكينز',

            // ===== توسعة دولية: أسماء وألقاب جنوب آسيوية (الهند/باكستان/بنغلاديش) =====
            'kumar' => 'كومار', 'singh' => 'سينغ', 'patel' => 'باتيل', 'sharma' => 'شارما',
            'khan' => 'خان', 'das' => 'داس', 'nair' => 'ناير', 'reddy' => 'ريدي',
            'gupta' => 'غوبتا', 'rao' => 'راو', 'iyer' => 'آير', 'menon' => 'مينون',
            'pillai' => 'بيلاي', 'shetty' => 'شيتي', 'raj' => 'راج', 'ravi' => 'رافي',
            'kiran' => 'كيران', 'arun' => 'أرون', 'anil' => 'أنيل', 'sunil' => 'سونيل',
            'vijay' => 'فيجاي', 'ajay' => 'أجاي', 'sanjay' => 'سانجاي', 'rahul' => 'راهول',
            'rohit' => 'روهيت', 'amit' => 'أميت', 'suresh' => 'سوريش', 'ramesh' => 'راميش',
            'mahesh' => 'ماهيش', 'ganesh' => 'غانيش', 'prakash' => 'براكاش', 'deepak' => 'ديباك',
            'manoj' => 'مانوج', 'rajesh' => 'راجيش', 'dinesh' => 'دينيش', 'naveen' => 'نافين',
            'praveen' => 'برافين', 'krishna' => 'كريشنا', 'mohan' => 'موهان', 'gopal' => 'غوبال',
            'ashok' => 'أشوك', 'vikram' => 'فيكرام', 'karthik' => 'كارتيك', 'priya' => 'بريا',
            'pooja' => 'بوجا', 'anita' => 'أنيتا', 'sunita' => 'سونيتا', 'divya' => 'ديفيا',
            'meena' => 'مينا', 'geeta' => 'غيتا', 'radha' => 'رادها', 'deepa' => 'ديبا',
            'lakshmi' => 'لاكشمي', 'anjali' => 'أنجالي', 'neha' => 'نيها', 'shreya' => 'شريا',
            'aslam' => 'أسلم', 'iqbal' => 'إقبال', 'javed' => 'جاويد', 'arshad' => 'أرشد',
            'mushtaq' => 'مشتاق', 'nawaz' => 'نواز', 'tanveer' => 'تنوير', 'kamran' => 'كامران',
            'danish' => 'دانش', 'asif' => 'آصف', 'shakeel' => 'شكيل', 'shahid' => 'شاهد',
            'imtiaz' => 'امتياز', 'pervez' => 'برويز', 'riaz' => 'رياز', 'zulfiqar' => 'ذوالفقار',

            // ===== توسعة دولية: أسماء فلبينية شائعة =====
            'jose' => 'خوسيه', 'juan' => 'خوان', 'pedro' => 'بيدرو', 'carlos' => 'كارلوس',
            'antonio' => 'أنطونيو', 'angel' => 'أنجل', 'cristina' => 'كريستينا', 'rowena' => 'روينا',
            'jenny' => 'جيني', 'joy' => 'جوي', 'ramon' => 'رامون', 'jerome' => 'جيروم',
            'melvin' => 'ميلفين', 'jayson' => 'جيسون', 'cherry' => 'تشيري',

            // ===== توسعة عربية إضافية: أسماء رجال (تهجئات وأسماء ناقصة) =====
            'bashir' => 'بشير', 'basheer' => 'بشير', 'muneeb' => 'منيب', 'areeb' => 'أريب',
            'uzair' => 'عزير', 'zohaib' => 'زهيب', 'anees' => 'أنيس', 'faizan' => 'فيزان',
            'rehan' => 'ريحان', 'talib' => 'طالب', 'wajahat' => 'وجاهت', 'zeeshan' => 'زيشان',
            'junaid' => 'جنيد', 'aqib' => 'عاقب', 'owais' => 'أويس', 'huzaifa' => 'حذيفة',
            'muddassir' => 'مدثر', 'ubaidah' => 'عبيدة', 'muadh' => 'معاذ', 'aymen' => 'أيمن',
            'hammam' => 'همام', 'khubaib' => 'خبيب', 'sabeeh' => 'صبيح', 'muhaimin' => 'مهيمن',
            'rabee' => 'ربيع', 'aoun' => 'عون', 'awn' => 'عون', 'ghadanfar' => 'غضنفر',
            'muhsin' => 'محسن', 'majeed' => 'مجيد', 'bari' => 'الباري',
            'nabih' => 'نبيه', 'sabih' => 'صبيح', 'raji' => 'راجي', 'wasfi' => 'وصفي',
            'suroor' => 'سرور', 'mahmud' => 'محمود', 'tameem' => 'تميم',

            // ===== توسعة عربية إضافية: أسماء نساء ومواليد حديثة =====
            'nusaiba' => 'نسيبة', 'ruqayyah' => 'رقية', 'sumayyah' => 'سمية', 'juwairiya' => 'جويرية',
            'umaima' => 'أميمة', 'khansa' => 'خنساء', 'rufaida' => 'رفيدة', 'maimuna' => 'ميمونة',
            'safiyyah' => 'صفية', 'kulthum' => 'كلثوم', 'asiya' => 'آسية', 'barah' => 'باره',
            'retal' => 'رتال', 'lamar' => 'لمار', 'elaf' => 'إيلاف', 'ilaf' => 'إيلاف',
            'wateen' => 'وتين', 'tuqa' => 'تقى', 'renad' => 'رناد', 'judi' => 'جودي',
            'sila' => 'سيلا', 'tuleen' => 'تولين', 'sham' => 'شام', 'massa' => 'ماسة',
            'rital' => 'ريتال', 'balsam' => 'بلسم', 'karam' => 'كرم', 'diala' => 'ديالا',
            'rasil' => 'راسيل', 'rovan' => 'روفان', 'reetal' => 'ريتال', 'wjdan' => 'وجدان',
            'joana' => 'جوانا', 'liana' => 'ليانا', 'celine' => 'سيلين',
        ];
    }
}
