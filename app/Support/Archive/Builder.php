<?php

namespace App\Support\Archive;

use App\Http\Controllers\Admin\CustomerInvoiceController;
use App\Models\BankAccount;
use App\Models\Business;
use App\Models\BusinessArchive;
use App\Models\CustomerInvoiceAttachment;
use App\Models\Expense;
use App\Models\GoodsReceiptNote;
use App\Models\PurchaseOrder;
use App\Models\SupplierInvoice;
use App\Models\User;
use App\Support\Activity;
use App\Support\InvoiceBranding;
use App\Support\Money;
use App\Support\Pdf;
use App\Support\Permissions;
use App\Support\PublicDocument;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use ZipArchive;

/**
 * يبني أرشيفَ شهرٍ واحد — ويضمن أنّ «جاهز» لا تُكتب إلّا على ملفٍّ تامّ.
 *
 * ═══ الترتيب: يُبنى جانبًا، ثمّ يُنقل ═══
 *
 * كلُّ شيءٍ يُكتب في مساحةٍ مؤقّتة، ثمّ يُضغط، ثمّ **يُنقل** إلى مكانه.
 * والنقلُ آخرُ خطوة لأنّه وحده ذرّيٌّ في نظام الملفّات: لو سقط البناء في
 * منتصفه لَما وُجد في مجلّد الأرشيفات ملفٌّ نصفُه — ولا شيءَ أسوأ من ZIP
 * يُفتح فيقول «تالف» بعد أن قال النظام «جاهز».
 *
 * والمساحةُ المؤقّتة تُمحى في `finally`: نجح البناءُ أم سقط، لا يبقى على
 * القرص مجلّدٌ بمئة ميجابايت لا يقرؤه أحد. ونسخٌ يملأ القرص هو نفسُه ما
 * يمنع النسخةَ التالية.
 *
 * ═══ وهذا ليس نسخةً احتياطيّة ═══
 *
 * النسخةُ التقنيّة (`backup:run`) باقيةٌ كما هي: JSON من الجداول الخام،
 * للاستعادة. وهذا ملفُّ قراءةٍ للتاجر — وهو مكتوبٌ في `README` داخل الـZIP
 * نفسِه كي لا يظنّه أحدٌ ما ليس هو، فيبني عليه خطّةَ تعافٍ لا يحملها.
 */
final class Builder
{
    public const VERSION = 1;

    /** مجلّدُ العمل — تحت القرص الخاصّ لا في `/tmp` المشترك */
    private const WORKSPACE = 'archive-workspace';

    public function __construct(
        private readonly BusinessArchive $archive,
    ) {}

    /**
     * يبني الأرشيف من أوّله إلى آخره.
     *
     * ولا يرمي استثناءً إلى نادِيه: يلتقط كلَّ شيء، ويكتب «فشل» وسببًا
     * يُقرأ. ووظيفةٌ تسقط باستثناءٍ تترك الصفَّ على «قيد الإنشاء» إلى الأبد
     * — فيقرأ التاجر «قيد الإنشاء» عن شيءٍ مات منذ أسبوع.
     */
    public function run(): bool
    {
        $business = Business::find($this->archive->business_id);

        if (! $business) {
            return $this->fail(__('لم يعد هذا المتجر موجودًا.'));
        }

        $period = Period::of($this->archive->year, $this->archive->month);
        $work = Storage::disk('local')->path(self::WORKSPACE.'/'.$this->archive->id);
        $zipPath = $work.'.zip';

        $this->archive->update([
            'status' => BusinessArchive::PROCESSING,
            'started_at' => now(),
            'failure_reason' => null,
        ]);

        try {
            File::ensureDirectoryExists($work);

            $manifest = $this->assemble($business, $period, $work);

            $this->pack($work, $zipPath);

            return $this->finish($zipPath, $period, $manifest);
        } catch (\Throwable $e) {
            @unlink($zipPath);

            return $this->fail($this->readable($e));
        } finally {
            File::deleteDirectory($work);
            @unlink($zipPath);
        }
    }

    /* ======================= بناءُ المحتوى ======================= */

    /**
     * يكتب كلَّ ما يدخل الـZIP، ويردّ بيانَ ما كُتب.
     *
     * @return array<string, mixed>
     */
    private function assemble(Business $business, Period $period, string $work): array
    {
        $bid = (int) $business->id;
        $currency = Money::of($bid);
        $sections = [];

        /*
         * واللغةُ لغةُ أوراق المتجر لا لغةُ من ضغط الزرّ.
         *
         * الأرشيفُ يُفتح بعد سنة، وقد يُفتح على جهازٍ آخر. ولغةُ من ضغط
         * الزرّ لحظتَها ليست صفةً من صفات الأرشيف. و`InvoiceBranding::render`
         * هي الموضعُ الذي تُبدَّل فيه لغةُ الأوراق ثمّ تُردّ — وهي التي
         * تستعملها الفاتورةُ نفسُها عند الطباعة، فتتّفق الورقةُ والملفّ.
         */
        return InvoiceBranding::render($bid, null, function () use ($bid, $business, $period, $work, $currency, &$sections) {
            $rtl = app()->getLocale() === 'ar';

            /* ---------- أوراقُ إكسل ---------- */
            File::ensureDirectoryExists($work.'/Excel');

            foreach (array_keys(Sheets::FILES) as $name) {
                if (! $this->maySee($name)) {
                    continue;
                }

                $rows = Sheets::write($name, $bid, $period, $work.'/Excel/'.$name.'.xlsx', $currency, $rtl);

                if ($rows !== null) {
                    $sections[$name] = $rows;
                }
            }

            /* ---------- فواتيرُ العملاء الرسميّة ---------- */
            $invoices = $this->invoicePdfs($bid, $period, $work);
            if ($invoices > 0) {
                $sections['Invoices'] = $invoices;
            }

            /* ---------- المرفقاتُ الأصليّة ---------- */
            $attachments = $this->attachments($bid, $period, $work);
            if ($attachments > 0) {
                $sections['Attachments'] = $attachments;
            }

            /*
             * وأرشيفٌ بلا مضمونٍ لا يُسلَّم.
             *
             * شهرٌ لم يقع فيه شيء يُنتج ZIP فيه ورقةُ تعريفٍ وحدها. وتسليمُه
             * «جاهزًا» يجعل التاجر يظنّ أنّ بياناتِ شهره فيه — ويكتشف غيرَ
             * ذلك يومَ يُطلب منه. فيُقال الآن.
             */
            if ($sections === []) {
                throw new RuntimeException(__('لا بيانات في هذه الفترة — لم يُنشأ أرشيف.'));
            }

            $manifest = $this->manifest($business, $period, $work, $sections);

            $this->readme($business, $period, $work, $sections, $currency);

            /*
             * والبيانُ يُعاد كتابتُه بعد الـREADME.
             *
             * `README.pdf` ملفٌّ في الأرشيف كبقيّة الملفّات، وبيانٌ لا يذكره
             * لا يكشف تلفَه. والترتيبُ مقصود: البيانُ يُبنى أوّلًا ليُكتب في
             * الـREADME عددُ ملفّاته، ثمّ يُختم به.
             */
            return $this->manifest($business, $period, $work, $sections);
        });
    }

    /**
     * فواتيرُ العملاء — بالقالب الرسميّ نفسِه، لا بتصميمٍ ثانٍ.
     *
     * ═══ ولا سطرَ واحدًا من تصميم الفاتورة يُمسّ ═══
     *
     * `CustomerInvoiceController::paper()` هي التي ترسم الورقةَ في الشاشة
     * وفي زرّ الطباعة. وتُنادى هنا كما تُنادى هناك، بالمتغيّرات نفسِها.
     * فما يخرج في الأرشيف هو ما يخرج من زرّ الطباعة حرفًا بحرف — ولو
     * بُني قالبٌ ثانٍ «للأرشيف» لافترق عن المعتمَد عند أوّل تعديل.
     *
     * ═══ والذاكرة ═══
     *
     * الفواتيرُ تُبنى واحدةً واحدةً من `cursor()`، وكلُّ ملفٍّ يُكتب ويُفلَت.
     * ومتجرٌ بألف فاتورةٍ في الشهر لا يبني ألفَ مستندٍ في الذاكرة.
     */
    private function invoicePdfs(int $bid, Period $period, string $work): int
    {
        $dir = $work.'/Invoices/Customer';
        $bank = BankAccount::where('business_id', $bid)->orderBy('id')->first();
        $written = 0;

        foreach (Sheets::issuedInvoices($bid, $period)->with('items')->cursor() as $invoice) {
            if ($written === 0) {
                File::ensureDirectoryExists($dir);
            }

            $html = CustomerInvoiceController::paper(
                $bid,
                $invoice,
                $invoice->paidTotal(),
                $invoice->outstanding(),
                $bank,
                null,
                ['paperUrl' => PublicDocument::url($invoice) ?? ''],
            )->render();

            File::put(
                $dir.'/'.$this->safeName($invoice->number).'.pdf',
                Pdf::a4($html, 'invoice')->getContent(),
            );

            $written++;
        }

        return $written;
    }

    /**
     * المرفقاتُ كما رُفعت — وبعد سؤالين عن كلٍّ منها.
     *
     * ═══ السؤالان ═══
     *
     * ١) **أهذا مستندُ هذا المتجر؟** — لا يُقرأ مسارٌ من عمودٍ ثمّ يُفتح.
     *    كلُّ استعلامٍ هنا مقيَّدٌ بـ`business_id`، فالمسارُ لا يصل إلينا
     *    أصلًا إلّا من صفٍّ يملكه المتجر.
     *
     * ٢) **أهو داخل القرص الخاصّ؟** — العمودُ نصٌّ، والنصُّ قد يحمل
     *    `../../../.env` من استيرادٍ قديم أو من عبثٍ في الماضي. فيُقاس
     *    المسارُ الحقيقيُّ بعد `realpath` ضدَّ جذر القرص: ما خرج عنه لا
     *    يدخل الأرشيف.
     *
     * واللاحقةُ تبقى كما هي: صورةُ إيصالٍ تبقى صورة. وتحويلُ كلّ مرفقٍ إلى
     * PDF يُفسد ما لا يُفسده شيء — ورقةُ مورّدٍ مصوّرةٌ تُقرأ، وPDF مبنيٌّ
     * منها بجودةٍ أقلّ قد لا يُقرأ.
     */
    private function attachments(int $bid, Period $period, string $work): int
    {
        $written = 0;

        $groups = [
            'Purchases' => [
                [PurchaseOrder::where('business_id', $bid)
                    ->whereBetween('ordered_at', [$period->start(), $period->end()]),
                    'attachment', 'attachment_name', 'number'],
                [PurchaseOrder::where('business_id', $bid)
                    ->whereBetween('ordered_at', [$period->start(), $period->end()]),
                    'receipt', 'receipt_name', 'number'],
                [SupplierInvoice::where('business_id', $bid)
                    ->whereBetween('issued_at', [$period->start(), $period->end()]),
                    'attachment', 'attachment_name', 'supplier_ref'],
                [GoodsReceiptNote::where('business_id', $bid)
                    ->whereBetween('received_at', [$period->start(), $period->end()]),
                    'attachment', 'attachment_name', 'number'],
            ],
            'Expenses' => [
                [Expense::where('business_id', $bid)
                    ->whereBetween('spent_at', [$period->start()->toDateString(), $period->end()->toDateString()]),
                    'attachment', 'attachment_name', 'id'],
            ],
            'Invoices' => [
                [CustomerInvoiceAttachment::where('business_id', $bid)
                    ->whereBetween('created_at', [$period->start(), $period->end()]),
                    'path', 'name', 'id'],
            ],
        ];

        foreach ($groups as $folder => $sources) {
            foreach ($sources as [$query, $pathColumn, $nameColumn, $labelColumn]) {
                foreach ($query->cursor() as $row) {
                    $real = $this->resolve($row->{$pathColumn});

                    if ($real === null) {
                        continue;
                    }

                    $dir = $work.'/Receipts/'.$folder;
                    File::ensureDirectoryExists($dir);

                    $label = $this->safeName((string) ($row->{$labelColumn} ?? $row->id));
                    $extension = pathinfo($row->{$nameColumn} ?: $row->{$pathColumn}, PATHINFO_EXTENSION);
                    $target = $dir.'/'.$label.'-'.$row->id.($extension ? '.'.strtolower($extension) : '');

                    File::copy($real, $target);
                    $written++;
                }
            }
        }

        return $written;
    }

    /**
     * يردّ المسارَ الحقيقيَّ للمرفق، أو `null` إن لم يكن آمنًا أو لم يوجد.
     *
     * والغيابُ لا يُسقط الأرشيف: ملفٌّ حُذف من القرص وبقي صفُّه أمرٌ يقع —
     * وأرشيفُ سنةٍ كاملة لا يُمنع لأنّ إيصالًا واحدًا فُقد.
     */
    private function resolve(?string $stored): ?string
    {
        if ($stored === null || trim($stored) === '') {
            return null;
        }

        /*
         * والجذرُ من القرص نفسِه لا من `storage_path` مكتوبًا بيدنا.
         *
         * `FinancialAttachmentController` يقرأ هذه الملفّات من
         * `Storage::disk('local')` — فجذرُها جذرُه. وبناؤه هنا باليد يجعل
         * موضعين يقولان أين تعيش المرفقات، ويفترقان يومَ يُبدَّل جذرُ القرص
         * في `filesystems.php`: يقرأ البابُ ملفًّا لا يجده الأرشيف.
         *
         * وقد وقع ذلك فعلًا في أوّل قياس.
         */
        $root = realpath(Storage::disk('local')->path(''));

        if ($root === false) {
            return null;
        }

        $real = realpath($root.DIRECTORY_SEPARATOR.ltrim($stored, '/\\'));

        if ($real === false || ! is_file($real)) {
            return null;
        }

        // خارج الجذر لا يدخل — ولو وصل إلينا من صفٍّ يملكه المتجر
        if (! str_starts_with($real, $root.DIRECTORY_SEPARATOR)) {
            return null;
        }

        return $real;
    }

    /* ======================= البيانُ والتعريف ======================= */

    /**
     * `manifest.json` — بصمةُ كلّ ملفٍّ وحجمُه.
     *
     * وغايتُه واحدة: أن يُكتشف تلفُ محتوًى داخل ZIP سليم. والـZIP يحمل CRC
     * لكلّ عضوٍ فيه، لكنّه يحميه من تلف النقل لا من نقصٍ وقع قبل الضغط.
     *
     * ولا سرَّ فيه: أسماءُ ملفّاتٍ وأحجامٌ وبصمات — لا مسارَ خادمٍ ولا مفتاح.
     */
    private function manifest(Business $business, Period $period, string $work, array $sections): array
    {
        $files = [];

        foreach ($this->walk($work) as $absolute) {
            $relative = str_replace('\\', '/', substr($absolute, strlen($work) + 1));

            if ($relative === 'manifest.json') {
                continue;
            }

            $files[] = [
                'path' => $relative,
                'sha256' => hash_file('sha256', $absolute),
                'size' => filesize($absolute),
            ];
        }

        usort($files, fn ($a, $b) => strcmp($a['path'], $b['path']));

        $manifest = [
            'archive_version' => self::VERSION,
            'business_id' => (int) $business->id,
            'period' => $period->key(),
            'generated_at' => now()->toIso8601String(),
            'timezone' => Period::timezone(),
            'sections' => $sections,
            'files' => $files,
        ];

        File::put($work.'/manifest.json', json_encode(
            $manifest,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT,
        ));

        return $manifest;
    }

    /**
     * `README.pdf` — بالعربيّة والإنجليزيّة في ورقةٍ واحدة.
     *
     * وأهمُّ سطرٍ فيه أنّ هذا **ليس** نسخةً تقنيّة تُستعاد. وبلا ذلك يظنّ
     * التاجر أنّه يملك خطّةَ تعافٍ ولا يملكها — وطمأنينةٌ كاذبة أسوأ من
     * تحذيرٍ كاذب.
     */
    private function readme(Business $business, Period $period, string $work, array $sections, array $currency): void
    {
        $html = view('documents.archive.readme', [
            'business' => $business,
            'period' => $period,
            'sections' => $sections,
            'currency' => $currency,
            'version' => self::VERSION,
            'timezone' => Period::timezone(),
            'generatedAt' => now(),
        ])->render();

        File::put($work.'/README.pdf', Pdf::a4($html, 'readme')->getContent());
    }

    /* ======================= الضغطُ والختم ======================= */

    private function pack(string $work, string $zipPath): void
    {
        $zip = new ZipArchive;

        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException(__('تعذّر إنشاء ملفّ الأرشيف على القرص.'));
        }

        foreach ($this->walk($work) as $absolute) {
            $zip->addFile($absolute, str_replace('\\', '/', substr($absolute, strlen($work) + 1)));
        }

        /*
         * و`close()` هي لحظةُ الكتابة الحقيقيّة.
         *
         * `addFile` تسجّل نيّةً؛ والبايتاتُ تُكتب عند الإغلاق. فقرصٌ امتلأ
         * بينهما يُظهر نجاحًا في كلّ `addFile` وفشلًا هنا — ولو لم يُفحص
         * لَخرج ZIP مبتورٌ مكتوبٌ عليه «جاهز».
         */
        if (! $zip->close()) {
            throw new RuntimeException(__('تعذّر إغلاق ملفّ الأرشيف — قد تكون مساحة القرص ممتلئة.'));
        }
    }

    /**
     * الخمسةُ التي تُقاس قبل أن تُكتب «جاهز».
     *
     * أُغلق الـZIP (فوق)، والملفُّ موجود، وحجمُه فوق الأدنى ودون الأقصى،
     * وبصمتُه حُسبت، والبيانُ فيه. وما لم تتمّ الخمسةُ يُحذف الملفُّ ويُكتب
     * «فشل» — ولا يُترك ملفٌّ يتيمٌ على القرص يُحسب من الحصّة ولا يقرؤه أحد.
     */
    private function finish(string $zipPath, Period $period, array $manifest): bool
    {
        if (! is_file($zipPath)) {
            return $this->fail(__('لم يُكتب ملفّ الأرشيف على القرص.'));
        }

        $size = (int) filesize($zipPath);

        /* والحكمُ في `Policy::sizeVerdict` — موضعٌ واحد يُقاس مباشرةً */
        if ($refusal = Policy::sizeVerdict($size)) {
            return $this->fail($refusal);
        }

        $checksum = hash_file('sha256', $zipPath);

        if ($checksum === false) {
            return $this->fail(__('تعذّر حساب بصمة الأرشيف.'));
        }

        $disk = Policy::remoteDisk() ?? 'local';
        $path = $this->destination($period);

        $handle = fopen($zipPath, 'rb');

        try {
            $stored = Storage::disk($disk)->put($path, $handle);
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
        }

        if ($stored === false || ! Storage::disk($disk)->exists($path)) {
            return $this->fail(__('تعذّر حفظ الأرشيف في مكانه.'));
        }

        $months = Policy::retentionMonths();

        $this->archive->update([
            'status' => BusinessArchive::READY,
            'storage_disk' => $disk,
            'storage_path' => $path,
            'file_size' => $size,
            'checksum' => $checksum,
            'archive_version' => self::VERSION,
            'completed_at' => now(),
            'expires_at' => $months > 0 ? now()->addMonthsNoOverflow($months) : null,
            'failure_reason' => null,
        ]);

        Activity::log('backup', __('أُنشئ أرشيف بيانات :period', ['period' => $period->key()]), [
            'business_id' => $this->archive->business_id,
            'subject_type' => BusinessArchive::class,
            'subject_id' => $this->archive->id,
        ]);

        return true;
    }

    /** `archives/{متجر}/{سنة-شهر}/Abaad-{اسم}-{سنة-شهر}.zip` */
    private function destination(Period $period): string
    {
        $business = Business::find($this->archive->business_id);
        $slug = $this->safeName((string) ($business?->name ?? 'business')) ?: 'business';

        return sprintf(
            'archives/%d/%s/Abaad-%s-%s.zip',
            $this->archive->business_id,
            $period->key(),
            $slug,
            $period->key(),
        );
    }

    private function fail(string $reason): bool
    {
        $this->archive->update([
            'status' => BusinessArchive::FAILED,
            'failure_reason' => mb_substr($reason, 0, 500),
            'completed_at' => now(),
            'storage_path' => null,
            'file_size' => null,
            'checksum' => null,
        ]);

        Activity::log('backup', __('فشل إنشاء أرشيف :period', [
            'period' => sprintf('%04d-%02d', $this->archive->year, $this->archive->month),
        ]), [
            'business_id' => $this->archive->business_id,
            'subject_type' => BusinessArchive::class,
            'subject_id' => $this->archive->id,
        ]);

        return false;
    }

    /**
     * رسالةٌ يقرؤها تاجر — لا أثرُ استثناءٍ فيه مساراتُ الخادم.
     *
     * وأثرُ الاستثناء الكاملُ يبقى في سجلّ Laravel لمن يُصلح.
     */
    private function readable(\Throwable $e): string
    {
        /*
         * ═══ والمتوقَّعُ لا يُقيَّد خطأً ═══
         *
         * «لا بيانات في هذه الفترة» ليست عطبًا: شهرٌ لم يقع فيه شيء، والجواب
         * صحيحٌ ومكتوبٌ في الصفّ يقرؤه صاحبه. وكان يُقيَّد `ERROR` في سجلّ
         * النظام — فأخرج تشغيلُ الاختبارات وحده ستّةً وسبعين سطرًا.
         *
         * وسجلٌّ يمتلئ بالطبيعيّ يُخفي الحقيقيّ: من يفتحه يبحث عن العطب بين
         * مئة سطرٍ سليم، فيتعوّد ألّا يفتحه.
         *
         * فما رفضناه نحن عمدًا (`RuntimeException` برسالةٍ كُتبت لتُقرأ) يُردّ
         * ولا يُقيَّد؛ وما لم نتوقّعه يُقيَّد كاملًا بأثره ليُصلَح.
         */
        if ($e instanceof RuntimeException) {
            return $e->getMessage();
        }

        report($e);

        return __('تعذّر إنشاء الأرشيف — راجع سجلّ النظام.');
    }

    /** أيُكتب هذا الملفُّ لمن طلب الأرشيف؟ — والمجدولُ يكتب كلَّ شيء */
    private function maySee(string $name): bool
    {
        $needs = Sheets::GATED[$name] ?? null;

        if ($needs === null) {
            return true;
        }

        $by = $this->archive->generated_by;

        /*
         * والمجدولُ لا شخصَ له — فيكتب كلَّ شيء.
         *
         * أرشيفُ المتجر ملكُ المتجر، ومسيرةُ رواتبه منه. والحارسُ على
         * **التنزيل** لا على الكتابة: من ينزّله هو صاحبُ النشاط أو من
         * مُنح `business.export`، ويُسأل عن `payroll.view` عند بابه.
         */
        if ($by === null) {
            return true;
        }

        return (bool) User::find($by)?->may($needs);
    }

    /** اسمٌ يصلح ملفًّا على أيّ نظام — ولا يخرج من مجلّده */
    private function safeName(string $raw): string
    {
        $clean = preg_replace('/[^\p{L}\p{N}_-]+/u', '-', $raw) ?? $raw;

        return trim(mb_substr($clean, 0, 80), '-');
    }

    /** @return iterable<string> كلُّ ملفٍّ تحت المجلّد، بمسارٍ مطلق */
    private function walk(string $dir): iterable
    {
        foreach (File::allFiles($dir) as $file) {
            yield $file->getPathname();
        }
    }

    /** يُقرأ في الحرّاس: الأفعالُ التي يتطلّبها هذا الأرشيف */
    public static function requires(): string
    {
        return Permissions::BUSINESS_EXPORT;
    }
}
