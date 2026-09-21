<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Customer;
use App\Support\Activity;
use App\Support\Demo;
use Illuminate\Http\Request;
use App\Support\Pdf;
use App\Support\Sheet;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class CustomerImportExportController extends Controller
{
    private const SESSION_KEY = 'customer_import';
    private const ALLOWED_EXT = ['csv', 'xls', 'xlsx', 'xlsm'];

    private function bid(): int { return auth()->user()->business_id ?? Demo::bid(); }

    /** أعمدة الملف بترتيب النشاط (تُستخدم للتصدير والنموذج) */
    private function columns(): array
    {
        return [__('الاسم'), __('الهاتف'), __('البريد'), __('العنوان'), __('الفرع'), __('النقاط'), __('اللغة'), __('تاريخ الميلاد')];
    }

    /**
     * لغةُ رسائل واتساب كما تُكتب في الملفّ — بأيّ لفظٍ معقول.
     *
     * الملفُّ يكتبه إنسان: «العربية» و«عربي» و«ar» و«Arabic» لغةٌ واحدة.
     * وما لم يُفهم يُردّ `null` فيُقال في المعاينة لا بعد الاستيراد.
     */
    public static function parseLanguage(string $value): ?string
    {
        $v = mb_strtolower(trim($value));
        $v = str_replace(['أ', 'إ', 'آ'], 'ا', $v);

        return match (true) {
            $v === '' => null,
            in_array($v, ['ar', 'ara', 'arabic', 'العربية', 'العربيه', 'عربي', 'عربية', 'عربيه', 'ع'], true) => 'ar',
            in_array($v, ['en', 'eng', 'english', 'الانجليزية', 'الانجليزيه', 'انجليزي', 'انجليزية', 'انجليزيه', 'انكليزي', 'e'], true) => 'en',
            default => null,
        };
    }

    private static function languageLabel(?string $code): string
    {
        return match ($code) { 'ar' => 'العربية', 'en' => 'English', default => '' };
    }

    /**
     * صفوف الملفّ — وهي ما تعرضه الشاشة لا ما في الجدول كلِّه.
     *
     * ثلاثة أزرار في قائمةٍ واحدة تحت عنوان «تصدير»، وكان اثنان منها يتجاهلان
     * البحث والثالث يتبعه: فمن بحث عن اسمٍ ثمّ صدّر Excel خرج بقاعدة العملاء
     * كاملة، ولا شيء في الملفّ يقول إنّ ما فيه غير ما كان أمامه. والاستيراد
     * لا يحذف أحدًا، فملفٌّ مُرشَّح لا يُنقص من القاعدة شيئًا إن أُعيد.
     *
     * @see \App\Support\ListFilters::customers
     */
    private function customerQuery(): \Illuminate\Database\Eloquent\Builder
    {
        $q = Customer::where('business_id', $this->bid())->with('branch')->orderBy('id');
        \App\Support\ListFilters::customers($q, request());

        return $q;
    }

    private function customerRows(): array
    {
        return $this->customerQuery()->get()
            ->map(fn ($c) => [
                $c->name,
                $c->phone ?? '',
                $c->email ?? '',
                $c->address ?? '',
                $c->branch?->name ?? '',
                (int) $c->points,
                self::languageLabel($c->language),
                // الميلادُ يخرج، والتنبيهُ والملاحظةُ لا: الملفُّ يُرسَل ويُشارَك
                \App\Support\CustomerFlags::formatBirthday($c),
            ])->all();
    }

    /* ============================ تصدير Excel ============================ */
    public function exportXlsx()
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setRightToLeft(true);
        $sheet->setTitle(__('العملاء'));

        $sheet->fromArray($this->columns(), null, 'A1');

        $lastCol = 'F';
        $sheet->getStyle("A1:{$lastCol}1")->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
        $sheet->getStyle("A1:{$lastCol}1")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('111111');
        $sheet->getStyle("A1:{$lastCol}1")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        $sheet->fromArray($this->customerRows(), null, 'A2');

        foreach (range('A', $lastCol) as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        Activity::log('report', 'صدّر قائمة العملاء (Excel)');

        $writer = new Xlsx($spreadsheet);
        $filename = 'customers-' . now()->format('Y-m-d') . '.xlsx';

        return response()->streamDownload(function () use ($writer) {
            $writer->save('php://output');
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    /* ============================ تصدير PDF ============================ */
    public function exportPdf()
    {
        $bid = $this->bid();
        $customers = $this->customerQuery()->get();

        $html = view('pdf.customers-list', [
            'business' => Demo::business($bid),
            'customers' => $customers,
            'generatedAt' => now()->format('Y-m-d H:i'),
        ])->render();

        Activity::log('report', 'صدّر قائمة العملاء (PDF)');

        return Pdf::a4($html, 'customers-' . now()->format('Y-m-d'));
    }

    /* ==================== استيراد: رفع الملف ثم المعاينة ==================== */
    public function upload(Request $request)
    {
        $request->validate([
            'file' => ['required', 'file', 'max:5120'],
            'branch_id' => ['nullable', 'integer'],
        ], [], ['file' => __('الملف')]);

        $file = $request->file('file');
        $ext = strtolower($file->getClientOriginalExtension());
        if (! in_array($ext, self::ALLOWED_EXT, true)) {
            return back()->with('toast', ['msg' => __('صيغة غير مدعومة. المدعوم:') . ' ' . implode('، ', self::ALLOWED_EXT), 'type' => 'danger']);
        }

        $bid = $this->bid();

        // الفرع الافتراضي (لمن ليس له فرع في الملف)
        $defaultBranchId = $request->integer('branch_id') ?: null;
        if ($defaultBranchId && ! Branch::where('business_id', $bid)->whereKey($defaultBranchId)->exists()) {
            $defaultBranchId = null;
        }

        // خريطة فروع النشاط بالاسم (لمطابقة عمود الفرع في الملف)
        $branches = Branch::where('business_id', $bid)->get();
        $branchByName = [];
        $branchName = [];
        foreach ($branches as $b) {
            $branchByName[$this->norm($b->name)] = $b->id;
            $branchName[$b->id] = $b->name;
        }
        if ($defaultBranchId) {
            $branchName[$defaultBranchId] = $branches->firstWhere('id', $defaultBranchId)?->name;
        }

        try {
            // والترميز يُقرأ لا يُفترض — انظر `Sheet`
            $data = Sheet::rows($file->getRealPath());
        } catch (\Throwable $e) {
            return back()->with('toast', ['msg' => __('تعذّر قراءة الملف. تأكد أنه ملف صالح.'), 'type' => 'danger']);
        }

        $data = array_values(array_filter($data, fn ($r) => count(array_filter($r, fn ($v) => trim((string) $v) !== '')) > 0));
        if (count($data) === 0) {
            return back()->with('toast', ['msg' => __('الملف فارغ.'), 'type' => 'warning']);
        }

        $map = $this->detectColumns($data[0]);
        if ($map['isHeader']) {
            array_shift($data);
        }
        $idx = $map['index'];

        // العملاء الحاليون: للمطابقة (تحديث بدل تكرار)
        $existing = Customer::where('business_id', $bid)->get(['id', 'name', 'phone', 'language']);
        $byPhone = [];
        $byName = [];
        $languageOf = [];
        foreach ($existing as $c) {
            $languageOf[$c->id] = $c->language;
            $np = $this->normPhone((string) $c->phone);
            if ($np !== '') {
                $byPhone[$np] ??= $c->id;
            }
            $nn = $this->norm((string) $c->name);
            if ($nn !== '') {
                $byName[$nn] ??= $c->id;
            }
        }

        /*
         * والمحذوف ناعمًا لا يظهر هنا — فيمرّ صفُّه كأنه عميلٌ جديد.
         *
         * فيُنشأ عميلٌ حيٌّ بهاتف عميلٍ محذوف، وليس في مسار الاستيراد قيدُ
         * تفرّدٍ يمنعه — القيد في نموذج الإضافة وحده. ثمّ تُستعاد النسخة
         * القديمة يومًا فيصير في المتجر سجلّان بالهاتف نفسه: شخصٌ واحد
         * برصيدَي نقاط، يشتري على أحدهما ويستبدل من الآخر.
         */
        $trashed = [];
        foreach (Customer::onlyTrashed()->where('business_id', $bid)->get(['id', 'name', 'phone']) as $c) {
            $np = $this->normPhone((string) $c->phone);
            if ($np !== '') {
                $trashed[$np] ??= $c->name;
            }
        }

        $seen = [];
        $rows = [];
        foreach ($data as $r) {
            $get = fn ($k) => $idx[$k] !== null ? trim((string) ($r[$idx[$k]] ?? '')) : '';
            $name = $get('name');
            $phone = $get('phone');
            $email = $get('email');
            $address = $get('address');
            $fileBranch = $get('branch');
            $points = (int) ($idx['points'] !== null ? ($r[$idx['points']] ?? 0) : 0);
            $languageText = $get('language');
            $language = self::parseLanguage($languageText);
            $birthdayText = $get('birthday');
            $birthday = \App\Support\CustomerFlags::parseBirthday($birthdayText);

            // تحديد الفرع: فرع الملف إن طابق فرعًا موجودًا، وإلا الفرع الافتراضي
            $branchId = $defaultBranchId;
            if ($fileBranch !== '' && isset($branchByName[$this->norm($fileBranch)])) {
                $branchId = $branchByName[$this->norm($fileBranch)];
            }
            $branchDisplay = $branchId ? ($branchName[$branchId] ?? '—') : __('بدون فرع');

            $status = 'new';
            $note = __('سيُضاف');
            $targetId = null;
            $np = $this->normPhone($phone);
            $nn = $this->norm($name);

            if ($name === '') {
                $status = 'invalid';
                $note = __('بدون اسم — يُتجاهل');
            } elseif ($email !== '' && ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $status = 'invalid';
                $note = __('بريد غير صالح — يُتجاهل');
            } elseif (mb_strlen($name) > 255) {
                // العمود يسع ٢٥٥ حرفًا — وما زاد كان يُمرَّر إلى القاعدة فتردّه بخطأ
                $status = 'invalid';
                $note = __('الاسم أطول من ٢٥٥ حرفًا — يُتجاهل');
            } elseif ($np !== '' && isset($seen[$np])) {
                $status = 'dup_file';
                $note = __('مكرر داخل الملف — يُتجاهل');
            } elseif ($np !== '' && isset($trashed[$np])) {
                $status = 'invalid';
                $note = __('رقمه مسجَّل لعميل محذوف — استعِده من المحذوفات أولًا');
            } else {
                // مطابقة عميل موجود => تحديث
                if ($np !== '' && isset($byPhone[$np])) {
                    $targetId = $byPhone[$np];
                } elseif ($np === '' && $nn !== '' && isset($byName[$nn])) {
                    $targetId = $byName[$nn];
                }
                if ($targetId) {
                    $status = 'update';
                    $note = __('موجود — سيُحدَّث');
                }
            }

            /*
             * ولغةُ رسائل واتساب تُسأل هنا كما تُسأل في كلّ باب.
             *
             * صفٌّ جديدٌ بلا لغةٍ يُردّ، وصفٌّ يحدّث عميلًا لا لغةَ له يُردّ
             * كذلك — فالاستيرادُ أسرعُ طريقٍ لجولة اللغة: صدّر الملفّ،
             * املأ العمود، أعد رفعه. ومن كتب لفظًا لا يُفهم يُقال له.
             */
            if (in_array($status, ['new', 'update'], true)) {
                if ($languageText !== '' && $language === null) {
                    $status = 'invalid';
                    $note = __('لغة غير معروفة — اكتب «العربية» أو «English» — يُتجاهل');
                } elseif ($language === null && ($status === 'new' || ($languageOf[$targetId] ?? null) === null)) {
                    $status = 'invalid';
                    $note = __('بلا لغة رسائل واتساب — أضف عمود «اللغة» (العربية/English) — يُتجاهل');
                } elseif ($birthday === false) {
                    $status = 'invalid';
                    $note = __('تاريخ ميلاد لا يُفهم — اكتبه «12/03» أو «12/03/1990» — يُتجاهل');
                }
            }

            if ($np !== '') {
                $seen[$np] = true;
            }

            /*
             * ما ذكره الملفّ فعلًا — وما سكت عنه يبقى عند التحديث.
             *
             * كان الصفُّ يُقرأ بطاقةَ عميلٍ كاملة: عمودٌ غائبٌ يعني فراغًا أو
             * صفرًا. فاستيراد قائمة أسماءٍ وأرقام — وهو أكثر ما يُستورد —
             * كان **يمحو نقاط الولاء إلى صفر** لكل عميلٍ طابق، ويمحو بريده
             * وعنوانه معها. ولا شيء في الشاشة يقول ذلك.
             */
            $stated = [];
            foreach (['name', 'phone', 'email', 'address', 'points', 'language', 'birthday'] as $field) {
                $stated[$field] = $idx[$field] !== null && $get($field) !== '';
            }

            $birthday = $birthday ?: null;
            $rows[] = compact('name', 'phone', 'email', 'address', 'points', 'language', 'birthday', 'branchId', 'branchDisplay', 'status', 'note', 'targetId', 'stated');
        }

        session()->put(self::SESSION_KEY, [
            'rows' => $rows,
            'default_branch_id' => $defaultBranchId,
            'file' => $file->getClientOriginalName(),
        ]);

        return redirect()->route('admin.customers.import.preview');
    }

    public function preview()
    {
        $payload = session(self::SESSION_KEY);
        if (! $payload) {
            return redirect()->route('admin.customers.index')
                ->with('toast', ['msg' => __('لا يوجد ملف للمعاينة. ارفع ملفًا أولًا.'), 'type' => 'warning']);
        }

        $default = $payload['default_branch_id'] ? Branch::find($payload['default_branch_id']) : null;
        $rows = $payload['rows'];
        $counts = [
            'total' => count($rows),
            'new' => count(array_filter($rows, fn ($r) => $r['status'] === 'new')),
            'update' => count(array_filter($rows, fn ($r) => $r['status'] === 'update')),
            'skip' => count(array_filter($rows, fn ($r) => in_array($r['status'], ['invalid', 'dup_file'], true))),
        ];

        // ما لا يذكره الملفّ يبقى كما هو — يُقال قبل التأكيد لا بعده
        $labels = ['name' => 'الاسم', 'phone' => 'الهاتف', 'email' => 'البريد', 'address' => 'العنوان', 'points' => 'النقاط', 'language' => 'اللغة', 'birthday' => 'تاريخ الميلاد'];
        $untouched = [];
        if ($counts['update'] > 0) {
            foreach ($labels as $key => $label) {
                if (! collect($rows)->where('status', 'update')->contains(fn ($r) => $r['stated'][$key] ?? false)) {
                    $untouched[] = __($label);
                }
            }
        }

        return \Inertia\Inertia::render('Admin/Customers/ImportPreview', [
            'rows' => $rows,
            'counts' => $counts,
            'untouched' => $untouched,
            'defaultBranchName' => $default?->name,
            'file' => $payload['file'],
        ]);
    }

    public function confirm()
    {
        $payload = session(self::SESSION_KEY);
        if (! $payload) {
            return redirect()->route('admin.customers.index')
                ->with('toast', ['msg' => __('انتهت الجلسة. أعد رفع الملف.'), 'type' => 'warning']);
        }

        $bid = $this->bid();
        $added = 0;
        $updated = 0;

        /*
         * الملفّ يدخل كلُّه أو لا يدخل منه شيء.
         *
         * كان يُكتب صفًّا صفًّا بلا معاملة: صفٌّ يردّه الجدول في منتصف ملفٍّ
         * من مئتَي سطر يترك مئةً مكتوبةً ومئةً لا، ولا شاشة تقول أين توقّف.
         * وإعادة الرفع بعدها تُضاعف عمل النصف الأول.
         */
        \DB::transaction(function () use ($payload, $bid, &$added, &$updated) {
            foreach ($payload['rows'] as $r) {
                $fields = [
                    'name' => $r['name'],
                    'phone' => $r['phone'] ?: null,
                    'email' => $r['email'] ?: null,
                    'address' => $r['address'] ?: null,
                    'points' => (int) $r['points'],
                    'language' => $r['language'] ?? null,
                    'birth_day' => $r['birthday']['day'] ?? null,
                    'birth_month' => $r['birthday']['month'] ?? null,
                    'birth_year' => $r['birthday']['year'] ?? null,
                ];

                if ($r['status'] === 'new') {
                    $customer = Customer::create(array_merge($fields, [
                        'business_id' => $bid,
                        'branch_id' => $r['branchId'] ?: null,
                    ]));
                    $this->notePoints($customer, 0, (int) $fields['points'], $payload['file']);
                    $added++;
                } elseif ($r['status'] === 'update' && $r['targetId']) {
                    $customer = Customer::where('business_id', $bid)->find($r['targetId']);
                    if ($customer) {
                        // الحفاظ على الفرع: لا نمسح الفرع الحالي إن لم يُحدَّد فرع في الاستيراد
                        if ($r['branchId']) {
                            $fields['branch_id'] = $r['branchId'];
                        }
                        // وما لم يذكره الملفّ لا يُكتب — انظر بناء `stated` أعلاه
                        foreach (['name', 'phone', 'email', 'address', 'points', 'language'] as $field) {
                            if (! ($r['stated'][$field] ?? true)) {
                                unset($fields[$field]);
                            }
                        }
                        // والميلادُ ثلاثةُ أعمدةٍ لعمودٍ واحد في الملفّ — يُسكَت عنها معًا
                        if (! ($r['stated']['birthday'] ?? false)) {
                            unset($fields['birth_day'], $fields['birth_month'], $fields['birth_year']);
                        }
                        $before = (int) $customer->points;
                        $customer->update($fields);
                        $this->notePoints(
                            $customer, $before,
                            array_key_exists('points', $fields) ? (int) $fields['points'] : $before,
                            $payload['file'],
                        );
                        $updated++;
                    }
                }
            }
        });

        session()->forget(self::SESSION_KEY);
        Activity::log('updated', "استيراد العملاء من ملف: {$payload['file']} — أُضيف {$added}، حُدِّث {$updated}");

        return redirect()->route('admin.customers.index')
            ->with('toast', ['msg' => __('تم الاستيراد: أُضيف :added عميلًا وحُدِّث :updated', ['added' => $added, 'updated' => $updated]), 'type' => 'success']);
    }

    public function cancel()
    {
        session()->forget(self::SESSION_KEY);

        return redirect()->route('admin.customers.index')
            ->with('toast', ['msg' => __('أُلغيت عملية الاستيراد'), 'type' => 'warning']);
    }

    /* ============================== أدوات ============================== */

    /**
     * رصيدُ نقاطٍ تغيّر يقول من غيّره.
     *
     * ═══ ما كان ═══
     *
     * الاستيراد كان يكتب `points` في الصفّ مباشرةً — إنشاءً وتحديثًا — بلا
     * حركةٍ في `point_transactions`. والنقطةُ مالٌ (مئةُ نقطةٍ ريال)، وكلُّ
     * بابٍ آخر يمسّها يكتب حركتَها: البيع، والاستبدال اليدويّ، وإلغاءُ
     * الفاتورة، وتصحيحُها (`OrderCorrection`). فكان الاستيرادُ وحدَه يُغيّر
     * أرصدةَ مئتَي عميلٍ بلا سطرٍ يقول متى ولا من أين.
     *
     * وشاشةُ برنامج الولاء تعرض الثلاثةَ جنبًا إلى جنب: «مجموع النقاط»
     * و«المكتسبة» و«المستبدَلة». جرّبتُ ملفًّا يرفع الأرصدة إلى ثمانية آلافٍ
     * والدفترُ يعرف مئةً — ثلاثةُ أرقامٍ على شاشةٍ واحدة لا يمكن أن تصدُق
     * معًا، ولا سطرَ يشرح الفارق.
     *
     * ═══ ولا يُمنع الاستيراد ═══
     *
     * من ينقل قاعدةَ عملائه من نظامٍ سابق ينقل أرصدتهم معها — وهذا حقُّه.
     * فيُكتب الفارقُ حركةً مسمّاةً باسم الملفّ: الرصيدُ يبقى كما أراد،
     * ويبقى مفسَّرًا.
     */
    private function notePoints(Customer $customer, int $before, int $after, string $file): void
    {
        $delta = $after - $before;

        if ($delta === 0) {
            return;
        }

        \App\Models\PointTransaction::record(
            $customer,
            $delta > 0 ? 'earn' : 'redeem',
            abs($delta),
            $after,
            null,
            'استيراد ملف: '.$file,
        );
    }

    private function normPhone(string $phone): string
    {
        return preg_replace('/\D+/', '', $phone) ?? '';
    }

    private function norm(string $v): string
    {
        return trim(mb_strtolower($v));
    }

    /** اكتشاف ترتيب الأعمدة من الترويسة، أو افتراض ترتيب النشاط القياسي */
    private function detectColumns(array $firstRow): array
    {
        $norm = array_map(fn ($v) => trim((string) $v), $firstRow);
        $aliases = [
            'name' => ['الاسم', 'اسم', 'العميل', 'name', 'customer'],
            'phone' => ['الهاتف', 'هاتف', 'الجوال', 'جوال', 'رقم', 'phone', 'mobile'],
            'email' => ['البريد', 'ايميل', 'الايميل', 'email', 'mail'],
            'address' => ['العنوان', 'عنوان', 'address'],
            'branch' => ['الفرع', 'فرع', 'branch'],
            'points' => ['النقاط', 'نقاط', 'points'],
            'language' => ['اللغة', 'لغة', 'language', 'lang'],
            'birthday' => ['تاريخ الميلاد', 'الميلاد', 'ميلاد', 'birthday', 'birth', 'dob'],
        ];

        $index = ['name' => 0, 'phone' => 1, 'email' => 2, 'address' => 3, 'branch' => 4, 'points' => 5, 'language' => 6, 'birthday' => 7];
        $found = [];
        $isHeader = false;

        foreach ($norm as $i => $cell) {
            $low = mb_strtolower($cell);
            if ($low === '') {
                continue;
            }
            foreach ($aliases as $key => $names) {
                if (isset($found[$key])) {
                    continue;
                }
                foreach ($names as $n) {
                    if (mb_strpos($low, mb_strtolower($n)) !== false) {
                        $found[$key] = $i;
                        $isHeader = true;
                        break;
                    }
                }
            }
        }

        if ($isHeader) {
            // اعتمد الأعمدة المكتشفة؛ غير الموجودة تصبح null
            $index = [
                'name' => $found['name'] ?? 0,
                'phone' => $found['phone'] ?? null,
                'email' => $found['email'] ?? null,
                'address' => $found['address'] ?? null,
                'branch' => $found['branch'] ?? null,
                'points' => $found['points'] ?? null,
                'language' => $found['language'] ?? null,
                'birthday' => $found['birthday'] ?? null,
            ];
        } else {
            // بلا ترويسة: افتراض الترتيب حسب عدد الأعمدة المتاحة
            $cols = count($norm);
            $index = [
                'name' => 0,
                'phone' => $cols > 1 ? 1 : null,
                'email' => $cols > 2 ? 2 : null,
                'address' => $cols > 3 ? 3 : null,
                'branch' => $cols > 5 ? 4 : null,
                'points' => $cols > 5 ? 5 : ($cols > 4 ? 4 : null),
                'language' => $cols > 6 ? 6 : null,
                'birthday' => $cols > 7 ? 7 : null,
            ];
        }

        return ['isHeader' => $isHeader, 'index' => $index];
    }
}
