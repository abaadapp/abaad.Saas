<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * قيدٌ ماليّ يُمحى من دفتر الأستاذ.
 *
 * كان إلغاءُ البيعة يحذف قيدَيها، وحذفُ المصروف يحذف قيده. فتصير حركةٌ
 * وقعت كأنّها لم تقع: يقرأ المحاسب الميزان فلا يجد أثرًا لها ولا لإلغائها،
 * ولا يعرف أنّ رقمًا قرأه أمسِ تغيّر — ومن ألغى ومتى وكم كان المبلغ يذهب
 * كلُّه مع الصفّ المحذوف.
 *
 * وليس هذا خطرًا نظريًّا: `OrderCorrection::cancel` يُنادى من نقطة البيع
 * بلا حدٍّ زمنيّ ولا بوّابة صلاحية، فمن يبيع يستطيع أن يمحو أثرَ ما باع.
 *
 * فصار الأصلُ يبقى ويُكتب مقابله عكسُه: الرصيدُ صفرٌ كما كان، والتاريخ
 * مقروء. وهذا الحارس يمنع عودةَ المحو — لا في البيع ولا في المصروف.
 */
class TheLedgerIsNeverErasedTest extends TestCase
{
    /** المواضع التي كانت تمحو، ولا يجوز أن تعود */
    private const ERASERS = [
        'Support/Books.php',
        'Support/OrderCorrection.php',
        'Http/Controllers/Admin/ExpenseController.php',
        'Http/Controllers/Admin/TrashController.php',
    ];

    public function test_no_one_deletes_a_journal_entry(): void
    {
        $found = [];

        foreach (self::ERASERS as $file) {
            $code = file_get_contents(app_path($file));

            // `JournalEntry $e) => $e->delete()` وما شابهه
            if (preg_match('/JournalEntry\s+\$\w+\)\s*=>\s*\$\w+->(force)?delete\(\)/', $code)) {
                $found[] = basename($file);
            }
        }

        $this->assertSame([], $found, 'قيدٌ يُمحى من الدفتر في: '.implode('، ', $found));
    }

    /** والبابُ القديم لا يبقى مفتوحًا باسمه */
    public function test_the_erasing_doors_are_gone(): void
    {
        $books = file_get_contents(app_path('Support/Books.php'));

        $this->assertStringNotContainsString('function forgetSale', $books);
        $this->assertStringNotContainsString('function forgetExpense', $books);
        $this->assertStringContainsString('function unpostSale', $books);
        $this->assertStringContainsString('function unpostExpense', $books);
    }

    /** ولا يُنادى المحذوف من أيّ موضع */
    public function test_nothing_calls_the_old_doors(): void
    {
        $callers = [];

        $dir = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(app_path(), \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($dir as $file) {
            if (! $file->isFile() || ! str_ends_with($file->getFilename(), '.php')) {
                continue;
            }

            $code = file_get_contents($file->getPathname());

            if (str_contains($code, 'forgetSale(') || str_contains($code, 'forgetExpense(')) {
                $callers[] = $file->getFilename();
            }
        }

        $this->assertSame([], $callers, 'نداءٌ لبابٍ يمحو: '.implode('، ', $callers));
    }
}
