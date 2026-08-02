<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\BranchStock;
use App\Models\Category;
use App\Models\Product;
use App\Support\Activity;
use App\Support\Demo;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Mpdf\Mpdf;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * تصدير المنتجات واستيرادها — نظير CustomerImportExportController.
 *
 * الغرض الأول نقل تاجرٍ من نظامه السابق: يصدّر من هناك، يعدّل الأعمدة، يرفع
 * هنا. فالتصدير هنا **بيانات لا تقرير**: ترويسة في الصف الأول وأعمدة مطابقة
 * لما يقبله الاستيراد حرفيًّا، حتى يدور الملف كاملًا. تصدير المنتجات القديم
 * (products.xlsx) تقريرٌ للطباعة فيه عنوان ومعرّف وحالة محسوبة — لا يصلح
 * للعودة، وبقي كما هو.
 *
 * وأخطر ما هنا الكمية: كتابتها في products.quantity وحدها تكسر التوازن
 * «مجموع الفروع = كمية المنتج» الذي يقوم عليه بيع الفروع. فكل كمية تمرّ
 * عبر BranchStock — والتحديث يُطبّق **الفرق** لا الكمية كاملة.
 */
class ProductImportExportController extends Controller
{
    private const SESSION_KEY = 'product_import';
    private const ALLOWED_EXT = ['csv', 'xls', 'xlsx', 'xlsm'];

    private function bid(): int { return auth()->user()->business_id ?? Demo::bid(); }

    /** أعمدة الملف بترتيبها (تُستخدم للتصدير وللاستيراد معًا) */
    private function columns(): array
    {
        return [
            __('الاسم'), __('القسم'), 'SKU', __('الباركود'), __('السعر'), __('التكلفة'),
            __('الكمية'), __('حد التنبيه'), __('الضريبة %'), __('الخصم %'), __('الحالة'),
        ];
    }

    private function productRows(): array
    {
        return Product::where('business_id', $this->bid())
            ->with('category')->orderBy('id')->get()
            ->map(fn ($p) => [
                $p->name,
                $p->category?->name ?? '',
                (string) ($p->sku ?? ''),
                (string) ($p->barcode ?? ''),
                round((float) $p->price, 3),
                round((float) $p->cost, 3),
                (int) $p->quantity,
                (int) $p->alert_qty,
                round((float) $p->tax, 2),
                round((float) $p->discount, 2),
                $p->active ? __('مفعّل') : __('معطّل'),
            ])->all();
    }

    /* ============================ تصدير Excel ============================ */
    public function exportXlsx()
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setRightToLeft(true);
        $sheet->setTitle(__('المنتجات'));

        $sheet->fromArray($this->columns(), null, 'A1');

        $lastCol = 'K';
        $sheet->getStyle("A1:{$lastCol}1")->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
        $sheet->getStyle("A1:{$lastCol}1")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('111111');
        $sheet->getStyle("A1:{$lastCol}1")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        // SKU والباركود نصّان لا رقمان: أصفارٌ بادئة تضيع، و«5901234123457»
        // يُعرض 5.9E+12 فيعود من الملف باركودًا مختلفًا عن الملصق على الرفّ.
        $row = 2;
        foreach ($this->productRows() as $r) {
            $sheet->setCellValue("A{$row}", $r[0]);
            $sheet->setCellValue("B{$row}", $r[1]);
            $sheet->setCellValueExplicit("C{$row}", $r[2], DataType::TYPE_STRING);
            $sheet->setCellValueExplicit("D{$row}", $r[3], DataType::TYPE_STRING);
            foreach ([4 => 'E', 5 => 'F', 6 => 'G', 7 => 'H', 8 => 'I', 9 => 'J'] as $i => $col) {
                $sheet->setCellValue("{$col}{$row}", $r[$i]);
            }
            $sheet->setCellValue("K{$row}", $r[10]);
            $row++;
        }

        $sheet->freezePane('A2');
        foreach (range('A', $lastCol) as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        Activity::log('report', 'صدّر قائمة المنتجات (Excel)');

        $writer = new Xlsx($spreadsheet);
        $filename = 'products-' . now()->format('Y-m-d') . '.xlsx';

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
        $products = Product::where('business_id', $bid)->with('category')->orderBy('id')->get();

        $html = view('pdf.products-list', [
            'business' => Demo::business($bid),
            'products' => $products,
            'generatedAt' => now()->format('Y-m-d H:i'),
        ])->render();

        Activity::log('report', 'صدّر قائمة المنتجات (PDF)');

        $mpdf = new Mpdf([
            'mode' => 'utf-8', 'format' => 'A4',
            'margin_left' => 12, 'margin_right' => 12, 'margin_top' => 14, 'margin_bottom' => 14,
            'directionality' => 'rtl', 'autoScriptToLang' => true, 'autoLangToFont' => true,
        ]);
        $mpdf->WriteHTML($html);
        $name = 'products-' . now()->format('Y-m-d');

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
        ], [], ['file' => __('الملف')]);

        $file = $request->file('file');
        $ext = strtolower($file->getClientOriginalExtension());
        if (! in_array($ext, self::ALLOWED_EXT, true)) {
            return back()->with('toast', ['msg' => __('صيغة غير مدعومة. المدعوم:') . ' ' . implode('، ', self::ALLOWED_EXT), 'type' => 'danger']);
        }

        $bid = $this->bid();

        // الفرع الذي تُودَع فيه الكميات — لا يُترك للصدفة، وإلا اختلّ التوازن
        $branchId = $request->integer('branch_id') ?: null;
        if ($branchId && ! Branch::where('business_id', $bid)->whereKey($branchId)->exists()) {
            $branchId = null;
        }
        $branchId ??= Demo::activeBranchId();

        try {
            $reader = IOFactory::createReaderForFile($file->getRealPath());
            $reader->setReadDataOnly(true);
            $spreadsheet = $reader->load($file->getRealPath());
            $data = $spreadsheet->getActiveSheet()->toArray(null, true, false, false);
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

        // أقسام النشاط بالاسم — ما لا يُطابق منها يُنشأ عند التأكيد
        $categoryByName = Category::where('business_id', $bid)->get()
            ->mapWithKeys(fn ($c) => [$this->norm((string) $c->name) => $c->id])->all();

        // المنتجات الحالية: للمطابقة (تحديث بدل تكرار)
        $existing = Product::where('business_id', $bid)->get(['id', 'name', 'sku', 'barcode', 'quantity']);
        $bySku = [];
        $byBarcode = [];
        $byName = [];
        $qtyOf = [];
        foreach ($existing as $p) {
            $qtyOf[$p->id] = (int) $p->quantity;
            $nSku = $this->norm((string) $p->sku);
            $nBar = $this->norm((string) $p->barcode);
            $nName = $this->norm((string) $p->name);
            if ($nSku !== '') {
                $bySku[$nSku] ??= $p->id;
            }
            if ($nBar !== '') {
                $byBarcode[$nBar] ??= $p->id;
            }
            if ($nName !== '') {
                $byName[$nName] ??= $p->id;
            }
        }

        $seenSku = [];
        $seenBarcode = [];
        $newCategories = [];
        $rows = [];

        foreach ($data as $r) {
            $get = fn ($k) => $idx[$k] !== null ? trim((string) ($r[$idx[$k]] ?? '')) : '';
            $name = $get('name');
            $category = $get('category');
            $sku = $get('sku');
            $barcode = $get('barcode');
            $priceRaw = $get('price');
            $price = $this->num($priceRaw);
            $cost = $this->num($get('cost'));
            $quantity = (int) $this->num($get('quantity'));
            $alertQty = $idx['alert_qty'] !== null && $get('alert_qty') !== '' ? (int) $this->num($get('alert_qty')) : 10;
            $tax = $this->num($get('tax'));
            $discount = $this->num($get('discount'));
            $active = ! in_array($this->norm($get('status')), ['معطل', 'معطّل', 'غير مفعل', 'غير مفعّل', 'inactive', 'disabled', '0', 'لا'], true);

            $categoryId = $category !== '' ? ($categoryByName[$this->norm($category)] ?? null) : null;
            $categoryNew = $category !== '' && $categoryId === null;
            if ($categoryNew) {
                $newCategories[$this->norm($category)] = $category;
            }
            $categoryDisplay = $category !== '' ? $category : __('بدون قسم');

            $nSku = $this->norm($sku);
            $nBar = $this->norm($barcode);
            $nName = $this->norm($name);

            $status = 'new';
            $note = __('سيُضاف');
            $targetId = null;

            if ($name === '') {
                $status = 'invalid';
                $note = __('بدون اسم — يُتجاهل');
            } elseif ($priceRaw === '' || ! $this->looksNumeric($priceRaw)) {
                $status = 'invalid';
                $note = __('سعر غير صالح — يُتجاهل');
            } elseif ($price < 0 || $cost < 0 || $quantity < 0) {
                $status = 'invalid';
                $note = __('قيمة سالبة — يُتجاهل');
            } elseif ($nSku !== '' && isset($seenSku[$nSku])) {
                $status = 'dup_file';
                $note = __('SKU مكرر داخل الملف — يُتجاهل');
            } elseif ($nBar !== '' && isset($seenBarcode[$nBar])) {
                $status = 'dup_file';
                $note = __('باركود مكرر داخل الملف — يُتجاهل');
            } else {
                // المطابقة بالأدقّ فالأعمّ: SKU ثم الباركود ثم الاسم
                $targetId = ($nSku !== '' ? ($bySku[$nSku] ?? null) : null)
                    ?? ($nBar !== '' ? ($byBarcode[$nBar] ?? null) : null)
                    ?? ($nSku === '' && $nBar === '' && $nName !== '' ? ($byName[$nName] ?? null) : null);

                if ($targetId) {
                    $status = 'update';
                    $note = __('موجود — سيُحدَّث');
                }
            }

            if ($status !== 'invalid') {
                if ($nSku !== '') {
                    $seenSku[$nSku] = true;
                }
                if ($nBar !== '') {
                    $seenBarcode[$nBar] = true;
                }
            }

            $rows[] = compact(
                'name', 'category', 'categoryDisplay', 'categoryNew', 'categoryId', 'sku', 'barcode',
                'price', 'cost', 'quantity', 'alertQty', 'tax', 'discount', 'active',
                'status', 'note', 'targetId',
            ) + ['currentQty' => $targetId ? ($qtyOf[$targetId] ?? 0) : null];
        }

        session()->put(self::SESSION_KEY, [
            'rows' => $rows,
            'branch_id' => $branchId,
            'new_categories' => array_values($newCategories),
            'file' => $file->getClientOriginalName(),
        ]);

        return redirect()->route('admin.products.import.preview');
    }

    public function preview()
    {
        $payload = session(self::SESSION_KEY);
        if (! $payload) {
            return redirect()->route('admin.products.index')
                ->with('toast', ['msg' => __('لا يوجد ملف للمعاينة. ارفع ملفًا أولًا.'), 'type' => 'warning']);
        }

        $rows = $payload['rows'];
        $counts = [
            'total' => count($rows),
            'new' => count(array_filter($rows, fn ($r) => $r['status'] === 'new')),
            'update' => count(array_filter($rows, fn ($r) => $r['status'] === 'update')),
            'skip' => count(array_filter($rows, fn ($r) => in_array($r['status'], ['invalid', 'dup_file'], true))),
        ];

        return \Inertia\Inertia::render('Admin/Products/ImportPreview', [
            'rows' => $rows,
            'counts' => $counts,
            'branchName' => $payload['branch_id'] ? Branch::find($payload['branch_id'])?->name : null,
            'newCategories' => $payload['new_categories'],
            'file' => $payload['file'],
        ]);
    }

    public function confirm()
    {
        $payload = session(self::SESSION_KEY);
        if (! $payload) {
            return redirect()->route('admin.products.index')
                ->with('toast', ['msg' => __('انتهت الجلسة. أعد رفع الملف.'), 'type' => 'warning']);
        }

        $bid = $this->bid();
        $branchId = $payload['branch_id'];
        $added = 0;
        $updated = 0;

        // ملف بمئة صنف يجب أن يدخل كاملًا أو لا يدخل: نصفُ كتالوجٍ مستورَد
        // أسوأ من لا شيء، لأن التاجر لا يعرف أين توقّف.
        DB::transaction(function () use ($payload, $bid, $branchId, &$added, &$updated) {
            $categoryId = [];

            foreach ($payload['rows'] as $r) {
                if (! in_array($r['status'], ['new', 'update'], true)) {
                    continue;
                }

                // القسم: يُطابَق، وإلا يُنشأ مرّة واحدة ويُعاد استعماله
                $catId = $r['categoryId'];
                if (! $catId && $r['category'] !== '') {
                    $key = $this->norm($r['category']);
                    $catId = $categoryId[$key] ??= Category::firstOrCreate(
                        ['business_id' => $bid, 'name' => $r['category']],
                    )->id;
                }

                $fields = [
                    'name' => $r['name'],
                    'category_id' => $catId,
                    'sku' => $r['sku'] ?: null,
                    'barcode' => $r['barcode'] ?: null,
                    'price' => $r['price'],
                    'cost' => $r['cost'],
                    'alert_qty' => $r['alertQty'],
                    'tax' => $r['tax'],
                    'discount' => $r['discount'],
                    'active' => $r['active'],
                ];

                if ($r['status'] === 'new') {
                    $product = Product::create($fields + [
                        'business_id' => $bid,
                        'quantity' => $r['quantity'],
                        'image' => Demo::image('prod' . uniqid()),
                    ]);
                    BranchStock::adjust($bid, $branchId, $product->id, $r['quantity']);
                    $added++;

                    continue;
                }

                $product = Product::where('business_id', $bid)->find($r['targetId']);
                if (! $product) {
                    continue;
                }

                // الفرق لا الكمية: كتابة 50 فوق منتجٍ رصيده 30 موزّع على فرعين
                // تجعل مجموع الفروع 30 وكمية المنتج 50 — وهو الخلل الذي كان
                // يُجيز البيع من فرعٍ فارغ.
                $delta = $r['quantity'] - (int) $product->quantity;
                $product->update($fields + ['quantity' => $r['quantity']]);
                if ($delta !== 0) {
                    BranchStock::ensureAllocated($bid, $product->id, (int) $product->quantity - $delta);
                    BranchStock::adjust($bid, $branchId, $product->id, $delta);
                }
                $updated++;
            }
        });

        session()->forget(self::SESSION_KEY);
        Activity::log('updated', "استيراد المنتجات من ملف: {$payload['file']} — أُضيف {$added}، حُدِّث {$updated}");

        return redirect()->route('admin.products.index')
            ->with('toast', ['msg' => __('تم الاستيراد: أُضيف :added منتجًا وحُدِّث :updated', ['added' => $added, 'updated' => $updated]), 'type' => 'success']);
    }

    public function cancel()
    {
        session()->forget(self::SESSION_KEY);

        return redirect()->route('admin.products.index')
            ->with('toast', ['msg' => __('أُلغيت عملية الاستيراد'), 'type' => 'warning']);
    }

    /* ============================== أدوات ============================== */

    /**
     * هل يحمل النصّ عددًا؟ «١٢٫٥٠٠ ر.ع» يحمله و«غير محدد» لا.
     * is_numeric على النصّ الخام يرفض الأول — وهو أكثر ما يصل من أنظمة عربية.
     */
    private function looksNumeric(string $v): bool
    {
        return preg_match('/[\d٠-٩]/u', $v) === 1;
    }

    /** «١٢٫٥٠٠» و«12,500» و«12.5 ر.ع» كلها تصل من أنظمة سابقة */
    private function num(string $v): float
    {
        $v = strtr(trim($v), [
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
            '٫' => '.', '،' => '', ',' => '',
        ]);
        // أوّل عددٍ في النصّ لا كلّ ما يشبه العدد: «12.500 ر.ع» تحمل نقطة
        // ثانية في رمز العملة، وتنظيفٌ ساذج يخرج بـ«12.500.» فتصير صفرًا.
        return preg_match('/-?\d+(?:\.\d+)?/', $v, $m) === 1 ? (float) $m[0] : 0.0;
    }

    private function norm(string $v): string
    {
        return trim(mb_strtolower($v));
    }

    /** اكتشاف ترتيب الأعمدة من الترويسة، أو افتراض ترتيب النشاط القياسي */
    private function detectColumns(array $firstRow): array
    {
        $norm = array_map(fn ($v) => trim((string) $v), $firstRow);
        // الترتيب مقصود: «حد التنبيه» قبل «الكمية» وإلا التقط «الكمية» عمودَه،
        // و«التكلفة» قبل «السعر»، و«الباركود» قبل «SKU» — وإلا التقط
        // «barcode» عمودَ SKU لأنه ينتهي بـ«code».
        $aliases = [
            'name' => ['الاسم', 'اسم', 'المنتج', 'الصنف', 'name', 'product', 'item'],
            'category' => ['القسم', 'قسم', 'التصنيف', 'الفئة', 'category', 'dept'],
            'barcode' => ['الباركود', 'باركود', 'barcode', 'ean', 'upc'],
            'sku' => ['sku', 'رمز', 'الرمز', 'code', 'الكود'],
            'cost' => ['التكلفة', 'تكلفة', 'سعر الشراء', 'cost', 'purchase'],
            'price' => ['السعر', 'سعر', 'البيع', 'price', 'sale'],
            'alert_qty' => ['حد التنبيه', 'الحد الأدنى', 'حد', 'alert', 'min', 'reorder'],
            'quantity' => ['الكمية', 'كمية', 'المخزون', 'الرصيد', 'quantity', 'qty', 'stock'],
            'tax' => ['الضريبة', 'ضريبة', 'tax', 'vat'],
            'discount' => ['الخصم', 'خصم', 'discount'],
            'status' => ['الحالة', 'حالة', 'status', 'active'],
        ];

        $found = [];

        foreach ($norm as $i => $cell) {
            $low = mb_strtolower($cell);
            if ($low === '') {
                continue;
            }
            foreach ($aliases as $key => $names) {
                if (isset($found[$key]) || in_array($i, $found, true)) {
                    continue;
                }
                foreach ($names as $n) {
                    if (mb_strpos($low, mb_strtolower($n)) !== false) {
                        $found[$key] = $i;
                        break;
                    }
                }
            }
        }

        // مطابقة اسمٍ وحدها لا تكفي: صفّ بيانات فيه «SKU-9» يطابق عمود SKU
        // فيُبتلع أوّل منتج في الملف بوصفه ترويسة. الترويسة لا تحمل أرقامًا،
        // وصفّ البيانات يحمل سعرًا وكمية دائمًا.
        $hasNumber = false;
        foreach ($norm as $cell) {
            if ($cell !== '' && is_numeric(str_replace(',', '', $cell))) {
                $hasNumber = true;
                break;
            }
        }
        $isHeader = $found !== [] && ! $hasNumber;

        $order = ['name', 'category', 'sku', 'barcode', 'price', 'cost', 'quantity', 'alert_qty', 'tax', 'discount', 'status'];

        if ($isHeader) {
            $index = [];
            foreach ($order as $key) {
                $index[$key] = $found[$key] ?? null;
            }
            $index['name'] ??= 0;
        } else {
            // بلا ترويسة: الترتيب القياسي بقدر ما توفّر من أعمدة
            $cols = count($norm);
            $index = [];
            foreach ($order as $i => $key) {
                $index[$key] = $i < $cols ? $i : null;
            }
        }

        return ['isHeader' => $isHeader, 'index' => $index];
    }
}
