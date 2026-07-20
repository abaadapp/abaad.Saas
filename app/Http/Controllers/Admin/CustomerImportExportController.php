<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Customer;
use App\Support\Activity;
use App\Support\Demo;
use Illuminate\Http\Request;
use Mpdf\Mpdf;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class CustomerImportExportController extends Controller
{
    private const SESSION_KEY = 'customer_import';
    private const ALLOWED_EXT = ['csv', 'xls', 'xlsx', 'xlsm'];

    private function bid(): int { return auth()->user()->business_id ?? Demo::bid(); }

    /** أعمدة الملف (الترويسة) */
    private function columns(): array
    {
        return ['الاسم', 'الهاتف', 'البريد', 'العنوان', 'النقاط'];
    }

    private function customerRows(): array
    {
        return Customer::where('business_id', $this->bid())
            ->orderBy('id')->get()
            ->map(fn ($c) => [
                $c->name,
                $c->phone ?? '',
                $c->email ?? '',
                $c->address ?? '',
                (int) $c->points,
            ])->all();
    }

    /* ============================ تصدير Excel ============================ */
    public function exportXlsx()
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setRightToLeft(true);
        $sheet->setTitle('العملاء');

        $headers = $this->columns();
        $sheet->fromArray($headers, null, 'A1');

        // تنسيق الترويسة
        $lastCol = 'E';
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
        $customers = Customer::where('business_id', $bid)->with('branch')->orderBy('id')->get();

        $html = view('pdf.customers-list', [
            'business' => Demo::business($bid),
            'customers' => $customers,
            'generatedAt' => now()->format('Y-m-d H:i'),
        ])->render();

        Activity::log('report', 'صدّر قائمة العملاء (PDF)');

        $mpdf = new Mpdf([
            'mode' => 'utf-8', 'format' => 'A4',
            'margin_left' => 12, 'margin_right' => 12, 'margin_top' => 14, 'margin_bottom' => 14,
            'directionality' => 'rtl', 'autoScriptToLang' => true, 'autoLangToFont' => true,
        ]);
        $mpdf->WriteHTML($html);
        $name = 'customers-' . now()->format('Y-m-d');

        return response($mpdf->Output($name . '.pdf', 'S'), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="' . $name . '.pdf"',
        ]);
    }

    /* ==================== استيراد: رفع الملف ثم المعاينة ==================== */
    public function upload(Request $request)
    {
        $request->validate([
            'file' => ['required', 'file', 'max:5120'],
            'branch_id' => ['nullable', 'integer'],
        ], [], ['file' => 'الملف']);

        $file = $request->file('file');
        $ext = strtolower($file->getClientOriginalExtension());
        if (! in_array($ext, self::ALLOWED_EXT, true)) {
            return back()->with('toast', ['msg' => 'صيغة غير مدعومة. المدعوم: ' . implode('، ', self::ALLOWED_EXT), 'type' => 'danger']);
        }

        // التحقق من الفرع (ضمن نفس النشاط)
        $branchId = $request->integer('branch_id') ?: null;
        if ($branchId && ! Branch::where('business_id', $this->bid())->whereKey($branchId)->exists()) {
            $branchId = null;
        }

        try {
            $reader = IOFactory::createReaderForFile($file->getRealPath());
            $reader->setReadDataOnly(true);
            $spreadsheet = $reader->load($file->getRealPath());
            $data = $spreadsheet->getActiveSheet()->toArray(null, true, false, false);
        } catch (\Throwable $e) {
            return back()->with('toast', ['msg' => 'تعذّر قراءة الملف. تأكد أنه ملف صالح.', 'type' => 'danger']);
        }

        $data = array_values(array_filter($data, fn ($r) => count(array_filter($r, fn ($v) => trim((string) $v) !== '')) > 0));
        if (count($data) === 0) {
            return back()->with('toast', ['msg' => 'الملف فارغ.', 'type' => 'warning']);
        }

        // اكتشاف الترويسة وترتيب الأعمدة
        $map = $this->detectColumns($data[0]);
        if ($map['isHeader']) {
            array_shift($data);
        }
        $idx = $map['index'];

        $existingPhones = Customer::where('business_id', $this->bid())
            ->whereNotNull('phone')->pluck('phone')->map(fn ($p) => $this->normPhone($p))->filter()->all();
        $existingPhones = array_flip($existingPhones);
        $seen = [];

        $rows = [];
        foreach ($data as $r) {
            $name = trim((string) ($r[$idx['name']] ?? ''));
            $phone = trim((string) ($idx['phone'] !== null ? ($r[$idx['phone']] ?? '') : ''));
            $email = trim((string) ($idx['email'] !== null ? ($r[$idx['email']] ?? '') : ''));
            $address = trim((string) ($idx['address'] !== null ? ($r[$idx['address']] ?? '') : ''));
            $points = (int) ($idx['points'] !== null ? ($r[$idx['points']] ?? 0) : 0);

            $status = 'new';
            $note = 'جديد';
            $normPhone = $this->normPhone($phone);

            if ($name === '') {
                $status = 'invalid';
                $note = 'بدون اسم — يُتجاهل';
            } elseif ($email !== '' && ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $status = 'invalid';
                $note = 'بريد غير صالح — يُتجاهل';
            } elseif ($normPhone !== '' && isset($existingPhones[$normPhone])) {
                $status = 'duplicate';
                $note = 'مكرر (موجود مسبقًا) — يُتجاهل';
            } elseif ($normPhone !== '' && isset($seen[$normPhone])) {
                $status = 'duplicate';
                $note = 'مكرر داخل الملف — يُتجاهل';
            }

            if ($normPhone !== '') {
                $seen[$normPhone] = true;
            }

            $rows[] = compact('name', 'phone', 'email', 'address', 'points', 'status', 'note');
        }

        session()->put(self::SESSION_KEY, [
            'rows' => $rows,
            'branch_id' => $branchId,
            'file' => $file->getClientOriginalName(),
        ]);

        return redirect()->route('admin.customers.import.preview');
    }

    public function preview()
    {
        $payload = session(self::SESSION_KEY);
        if (! $payload) {
            return redirect()->route('admin.customers.index')
                ->with('toast', ['msg' => 'لا يوجد ملف للمعاينة. ارفع ملفًا أولًا.', 'type' => 'warning']);
        }

        $branch = $payload['branch_id'] ? Branch::find($payload['branch_id']) : null;
        $rows = $payload['rows'];
        $counts = [
            'total' => count($rows),
            'new' => count(array_filter($rows, fn ($r) => $r['status'] === 'new')),
            'duplicate' => count(array_filter($rows, fn ($r) => $r['status'] === 'duplicate')),
            'invalid' => count(array_filter($rows, fn ($r) => $r['status'] === 'invalid')),
        ];

        return view('admin.customers.import-preview', [
            'rows' => $rows,
            'counts' => $counts,
            'branch' => $branch,
            'file' => $payload['file'],
        ]);
    }

    public function confirm()
    {
        $payload = session(self::SESSION_KEY);
        if (! $payload) {
            return redirect()->route('admin.customers.index')
                ->with('toast', ['msg' => 'انتهت الجلسة. أعد رفع الملف.', 'type' => 'warning']);
        }

        $bid = $this->bid();
        $branchId = $payload['branch_id'] ?: null;
        $imported = 0;

        foreach ($payload['rows'] as $r) {
            if ($r['status'] !== 'new') {
                continue;
            }
            Customer::create([
                'business_id' => $bid,
                'branch_id' => $branchId,
                'name' => $r['name'],
                'phone' => $r['phone'] ?: null,
                'email' => $r['email'] ?: null,
                'address' => $r['address'] ?: null,
                'points' => (int) $r['points'],
            ]);
            $imported++;
        }

        session()->forget(self::SESSION_KEY);
        Activity::log('created', "استورد {$imported} عميلًا من ملف: " . $payload['file']);

        return redirect()->route('admin.customers.index')
            ->with('toast', ['msg' => "تم استيراد {$imported} عميلًا بنجاح", 'type' => 'success']);
    }

    public function cancel()
    {
        session()->forget(self::SESSION_KEY);

        return redirect()->route('admin.customers.index')
            ->with('toast', ['msg' => 'أُلغيت عملية الاستيراد', 'type' => 'warning']);
    }

    /* ============================== أدوات ============================== */
    private function normPhone(string $phone): string
    {
        return preg_replace('/\D+/', '', $phone) ?? '';
    }

    /** اكتشاف ترتيب الأعمدة من الترويسة، أو افتراض الترتيب القياسي */
    private function detectColumns(array $firstRow): array
    {
        $norm = array_map(fn ($v) => trim((string) $v), $firstRow);
        $aliases = [
            'name' => ['الاسم', 'اسم', 'العميل', 'name', 'customer'],
            'phone' => ['الهاتف', 'هاتف', 'الجوال', 'جوال', 'رقم', 'phone', 'mobile'],
            'email' => ['البريد', 'ايميل', 'الايميل', 'email', 'mail'],
            'address' => ['العنوان', 'عنوان', 'address'],
            'points' => ['النقاط', 'نقاط', 'points'],
        ];

        $index = ['name' => 0, 'phone' => 1, 'email' => 2, 'address' => 3, 'points' => 4];
        $isHeader = false;

        foreach ($norm as $i => $cell) {
            $low = mb_strtolower($cell);
            foreach ($aliases as $key => $names) {
                foreach ($names as $n) {
                    if ($low !== '' && mb_strpos($low, mb_strtolower($n)) !== false) {
                        $index[$key] = $i;
                        $isHeader = true;
                        break 2;
                    }
                }
            }
        }

        // إن لم تُكتشف ترويسة، الأعمدة الاختيارية قد لا تكون موجودة
        if (! $isHeader) {
            $cols = count($norm);
            $index = [
                'name' => 0,
                'phone' => $cols > 1 ? 1 : null,
                'email' => $cols > 2 ? 2 : null,
                'address' => $cols > 3 ? 3 : null,
                'points' => $cols > 4 ? 4 : null,
            ];
        }

        return ['isHeader' => $isHeader, 'index' => $index];
    }
}
