<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Supplier;
use App\Support\Activity;
use App\Support\Demo;
use Illuminate\Http\Request;
use Mpdf\Mpdf;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * تصدير قائمة المورّدين — إكسل وPDF.
 *
 * كان قسم العملاء يُصدَّر بثلاث صيغ والمورّدون بلا صيغةٍ واحدة، وهما قائمتا
 * أسماءٍ وأرقامِ تواصل بالبنية نفسها: من يريد مراجعة مورّديه مع محاسبه كان
 * ينسخهم بيده من الشاشة.
 *
 * وعدد أوامر الشراء عمودٌ في الملف لأنه السؤال الأوّل عن أي مورّد: من
 * يُشترى منه فعلًا، ومن بقي اسمًا في القائمة بلا أمرٍ واحد.
 */
class SupplierExportController extends Controller
{
    private const SESSION_KEY = 'supplier_import';

    private const ALLOWED_EXT = ['csv', 'xls', 'xlsx', 'xlsm'];

    private function bid(): int { return auth()->user()->business_id ?? Demo::bid(); }

    /** أعمدة الملف — واحدةٌ للصيغتين فلا تفترقان عند أوّل تعديل */
    private function columns(): array
    {
        return [__('الاسم'), __('الهاتف'), __('البريد'), __('مسؤول التواصل'), __('أوامر الشراء'), __('ملاحظات')];
    }

    private function suppliers()
    {
        return Supplier::where('business_id', $this->bid())
            ->withCount('purchaseOrders')->orderBy('name')->get();
    }

    public function xlsx()
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setRightToLeft(true);
        $sheet->setTitle(__('الموردين'));

        $sheet->fromArray($this->columns(), null, 'A1');

        $lastCol = 'F';
        $sheet->getStyle("A1:{$lastCol}1")->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
        $sheet->getStyle("A1:{$lastCol}1")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('111111');
        $sheet->getStyle("A1:{$lastCol}1")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        $rows = $this->suppliers()->map(fn ($s) => [
            $s->name,
            $s->phone ?? '',
            $s->email ?? '',
            $s->contact_person ?? '',
            (int) $s->purchase_orders_count,
            $s->notes ?? '',
        ])->all();

        $sheet->fromArray($rows, null, 'A2');

        foreach (range('A', $lastCol) as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        Activity::log('report', 'صدّر قائمة الموردين (Excel)');

        $writer = new Xlsx($spreadsheet);
        $filename = 'suppliers-'.now()->format('Y-m-d').'.xlsx';

        return response()->streamDownload(function () use ($writer) {
            $writer->save('php://output');
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    public function pdf()
    {
        $html = view('pdf.suppliers-list', [
            'business' => Demo::business($this->bid()),
            'suppliers' => $this->suppliers(),
            'generatedAt' => now()->format('Y-m-d H:i'),
        ])->render();

        Activity::log('report', 'صدّر قائمة الموردين (PDF)');

        $mpdf = new Mpdf([
            'mode' => 'utf-8', 'format' => 'A4',
            'margin_left' => 12, 'margin_right' => 12, 'margin_top' => 14, 'margin_bottom' => 14,
            'directionality' => 'rtl', 'autoScriptToLang' => true, 'autoLangToFont' => true,
        ]);
        $mpdf->WriteHTML($html);
        $name = 'suppliers-'.now()->format('Y-m-d');

        return response($mpdf->Output($name.'.pdf', 'S'), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$name.'.pdf"',
        ]);
    }

    /* ============================ استيراد من ملف ============================ */

    /**
     * الرفع لا يكتب شيئًا — يقرأ ويصف ويؤجّل.
     *
     * ملفٌ يُكتب فورًا لا سبيل لمراجعته: يكتشف التاجر بعد الاستيراد أن عمودًا
     * أُسيء فهمه وقد صار في قاعدته. فالمعاينة تقول قبل الكتابة كم سيُضاف وكم
     * سيُحدَّث وكم سيُتجاهل ولماذا.
     */
    public function upload(Request $request)
    {
        $request->validate(['file' => ['required', 'file', 'max:5120']], [], ['file' => __('الملف')]);

        $file = $request->file('file');
        $ext = strtolower($file->getClientOriginalExtension());
        if (! in_array($ext, self::ALLOWED_EXT, true)) {
            return back()->with('toast', ['msg' => __('صيغة غير مدعومة. المدعوم:').' '.implode('، ', self::ALLOWED_EXT), 'type' => 'danger']);
        }

        try {
            $reader = IOFactory::createReaderForFile($file->getRealPath());
            $reader->setReadDataOnly(true);
            $data = $reader->load($file->getRealPath())->getActiveSheet()->toArray(null, true, false, false);
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

        $bid = $this->bid();

        // المورّدون الحاليون: للمطابقة — تحديثٌ بدل تكرار
        $byPhone = [];
        $byName = [];
        foreach (Supplier::where('business_id', $bid)->get(['id', 'name', 'phone']) as $s) {
            $np = $this->normPhone((string) $s->phone);
            if ($np !== '') {
                $byPhone[$np] ??= $s->id;
            }
            $nn = $this->norm((string) $s->name);
            if ($nn !== '') {
                $byName[$nn] ??= $s->id;
            }
        }

        $seen = [];
        $rows = [];
        foreach ($data as $r) {
            $get = fn ($k) => $idx[$k] !== null ? trim((string) ($r[$idx[$k]] ?? '')) : '';
            $name = $get('name');
            $phone = $get('phone');
            $email = $get('email');
            $contact = $get('contact');
            $notes = $get('notes');

            $np = $this->normPhone($phone);
            $nn = $this->norm($name);

            $status = 'new';
            $note = __('سيُضاف');
            $targetId = null;

            if ($name === '') {
                $status = 'invalid';
                $note = __('بدون اسم — يُتجاهل');
            } elseif ($email !== '' && ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $status = 'invalid';
                $note = __('بريد غير صالح — يُتجاهل');
            } elseif ($np !== '' && isset($seen[$np])) {
                $status = 'dup_file';
                $note = __('مكرر داخل الملف — يُتجاهل');
            } elseif ($np === '' && $nn !== '' && isset($seen['n:'.$nn])) {
                $status = 'dup_file';
                $note = __('مكرر داخل الملف — يُتجاهل');
            } else {
                if ($np !== '' && isset($byPhone[$np])) {
                    $targetId = $byPhone[$np];
                } elseif ($nn !== '' && isset($byName[$nn])) {
                    $targetId = $byName[$nn];
                }
                if ($targetId) {
                    $status = 'update';
                    $note = __('موجود — سيُحدَّث');
                }
            }

            if ($np !== '') {
                $seen[$np] = true;
            } elseif ($nn !== '') {
                $seen['n:'.$nn] = true;
            }

            /*
             * ما ذكره الملفّ فعلًا — وما سكت عنه يبقى عند التحديث.
             *
             * لولا هذا لكان استيراد قائمة أسماءٍ وأرقام — وهو أكثر ما يُستورد —
             * يمحو بريد كل مورّدٍ طابق وملاحظاته واسم مسؤول التواصل معه.
             */
            $stated = [];
            foreach (['name', 'phone', 'email', 'contact', 'notes'] as $field) {
                $stated[$field] = $idx[$field] !== null && $get($field) !== '';
            }

            $rows[] = compact('name', 'phone', 'email', 'contact', 'notes', 'status', 'note', 'targetId', 'stated');
        }

        session()->put(self::SESSION_KEY, ['rows' => $rows, 'file' => $file->getClientOriginalName()]);

        return redirect()->route('admin.suppliers.import.preview');
    }

    public function preview()
    {
        $payload = session(self::SESSION_KEY);
        if (! $payload) {
            return redirect()->route('admin.suppliers.index')
                ->with('toast', ['msg' => __('لا يوجد ملف للمعاينة. ارفع ملفًا أولًا.'), 'type' => 'warning']);
        }

        $rows = $payload['rows'];
        $counts = [
            'total' => count($rows),
            'new' => count(array_filter($rows, fn ($r) => $r['status'] === 'new')),
            'update' => count(array_filter($rows, fn ($r) => $r['status'] === 'update')),
            'skip' => count(array_filter($rows, fn ($r) => in_array($r['status'], ['invalid', 'dup_file'], true))),
        ];

        // ما لا يذكره الملفّ يبقى كما هو — يُقال قبل التأكيد لا بعده
        $labels = ['name' => 'الاسم', 'phone' => 'الهاتف', 'email' => 'البريد', 'contact' => 'مسؤول التواصل', 'notes' => 'ملاحظات'];
        $untouched = [];
        if ($counts['update'] > 0) {
            foreach ($labels as $key => $label) {
                if (! collect($rows)->where('status', 'update')->contains(fn ($r) => $r['stated'][$key] ?? false)) {
                    $untouched[] = __($label);
                }
            }
        }

        return \Inertia\Inertia::render('Admin/Suppliers/ImportPreview', [
            'rows' => $rows,
            'counts' => $counts,
            'untouched' => $untouched,
            'file' => $payload['file'],
        ]);
    }

    public function confirm()
    {
        $payload = session(self::SESSION_KEY);
        if (! $payload) {
            return redirect()->route('admin.suppliers.index')
                ->with('toast', ['msg' => __('انتهت الجلسة. أعد رفع الملف.'), 'type' => 'warning']);
        }

        $bid = $this->bid();
        $added = 0;
        $updated = 0;

        foreach ($payload['rows'] as $r) {
            $fields = [
                'name' => $r['name'],
                'phone' => $r['phone'] ?: null,
                'email' => $r['email'] ?: null,
                'contact_person' => $r['contact'] ?: null,
                'notes' => $r['notes'] ?: null,
            ];

            if ($r['status'] === 'new') {
                Supplier::create($fields + ['business_id' => $bid]);
                $added++;
            } elseif ($r['status'] === 'update' && $r['targetId']) {
                $supplier = Supplier::where('business_id', $bid)->find($r['targetId']);
                if (! $supplier) {
                    continue;
                }
                // وما لم يذكره الملفّ لا يُكتب — انظر بناء `stated` أعلاه
                $columns = [
                    'name' => 'name', 'phone' => 'phone', 'email' => 'email',
                    'contact' => 'contact_person', 'notes' => 'notes',
                ];
                foreach ($columns as $field => $column) {
                    if (! ($r['stated'][$field] ?? true)) {
                        unset($fields[$column]);
                    }
                }
                $supplier->update($fields);
                $updated++;
            }
        }

        session()->forget(self::SESSION_KEY);
        Activity::log('updated', "استيراد الموردين من ملف: {$payload['file']} — أُضيف {$added}، حُدِّث {$updated}");

        return redirect()->route('admin.suppliers.index')
            ->with('toast', ['msg' => __('تم الاستيراد: أُضيف :added مورّدًا وحُدِّث :updated', ['added' => $added, 'updated' => $updated]), 'type' => 'success']);
    }

    public function cancel()
    {
        session()->forget(self::SESSION_KEY);

        return redirect()->route('admin.suppliers.index')
            ->with('toast', ['msg' => __('أُلغيت عملية الاستيراد'), 'type' => 'warning']);
    }

    /* ================================ أدوات ================================ */

    private function normPhone(string $phone): string
    {
        return preg_replace('/\D+/', '', $phone) ?? '';
    }

    private function norm(string $v): string
    {
        return trim(mb_strtolower($v));
    }

    /** اكتشاف ترتيب الأعمدة من الترويسة، أو افتراض ترتيب الملفّ المصدَّر */
    private function detectColumns(array $firstRow): array
    {
        $aliases = [
            'name' => ['الاسم', 'اسم', 'المورد', 'المورّد', 'name', 'supplier', 'vendor'],
            'phone' => ['الهاتف', 'هاتف', 'الجوال', 'جوال', 'رقم', 'phone', 'mobile'],
            'email' => ['البريد', 'ايميل', 'الايميل', 'email', 'mail'],
            'contact' => ['مسؤول', 'الشخص', 'المسؤول', 'contact'],
            'notes' => ['ملاحظ', 'notes', 'note'],
        ];

        $found = [];
        $isHeader = false;

        foreach (array_map(fn ($v) => trim((string) $v), $firstRow) as $i => $cell) {
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
            return ['isHeader' => true, 'index' => [
                'name' => $found['name'] ?? 0,
                'phone' => $found['phone'] ?? null,
                'email' => $found['email'] ?? null,
                'contact' => $found['contact'] ?? null,
                'notes' => $found['notes'] ?? null,
            ]];
        }

        // بلا ترويسة: الترتيب كما يُصدَّر — اسم، هاتف، بريد، مسؤول، ملاحظات
        $cols = count($firstRow);

        return ['isHeader' => false, 'index' => [
            'name' => 0,
            'phone' => $cols > 1 ? 1 : null,
            'email' => $cols > 2 ? 2 : null,
            'contact' => $cols > 3 ? 3 : null,
            'notes' => $cols > 4 ? 4 : null,
        ]];
    }
}
