<?php

namespace Tests\Feature;

use App\Models\PosDevice;
use App\Models\PosPeripheral;
use App\Support\TillHealth;
use Tests\TestCase;

/**
 * ما يمنع إيصالَ صندوقٍ من الخروج — وما لا يُعدّ عطلًا.
 *
 * على صفوفٍ خام كما يقرؤها التدقيق (`pos:printing-audit`): لا قاعدةَ هنا، فكلُّ
 * حالةٍ تُكتب بيدها ويُقرأ جوابُها.
 */
class TillHealthTest extends TestCase
{
    private function till(int $id, array $over = []): array
    {
        return $over + [
            'id' => $id, 'business_id' => 1, 'branch_id' => 10, 'name' => 'صندوق '.$id, // رقمٌ مقصود: صفوفٌ خام بلا قاعدة — لا صفَّ يُخمَّن رقمُه
            'status' => PosDevice::ACTIVE, 'activated_at' => '2026-10-01 09:00:00', 'last_seen_at' => '2026-10-24 09:00:00',
        ];
    }

    private function printer(int $id, int $till, array $over = []): array
    {
        return $over + [
            'id' => $id, 'pos_device_id' => $till, 'name' => 'طابعة '.$id, 'type' => PosPeripheral::PRINTER,
            'connection' => 'network', 'address' => '192.168.1.'.$id, 'port' => null,
            'active' => true, 'auto_print' => false, 'is_receipt_printer' => false,
        ];
    }

    private function codes(array $found, int $till): array
    {
        return collect($found[$till] ?? [])->pluck('code')->sort()->values()->all();
    }

    public function test_a_single_working_printer_is_healthy(): void
    {
        $this->assertSame([], TillHealth::assess([$this->till(1)], [$this->printer(1, 1)]));
    }

    /**
     * صندوقٌ جديدٌ بلا طابعة، وفي فرعه ملغى يحملها — مشتبهٌ لا مؤكَّد.
     *
     * لا صفَّ يربط الصندوقين: قد يكون الجديدُ بديلَ الملغى فبقيت طابعتُه خلفه،
     * وقد يكون الملغى أُلغي عمدًا والجديدُ يطبع من المتصفّح. فلا يُقال «عطل».
     */
    public function test_a_printer_left_on_a_revoked_till_is_only_suspected(): void
    {
        $found = TillHealth::assess(
            [$this->till(1, ['status' => PosDevice::REVOKED]), $this->till(2, ['activated_at' => '2026-10-20 09:00:00'])],
            [$this->printer(1, 1)],
        );

        $this->assertSame([TillHealth::PRINTER_ON_REVOKED_TILL], $this->codes($found, 2));
        $this->assertSame(TillHealth::SUSPECTED, $found[2][0]['level']);
        $this->assertSame(1, $found[2][0]['other']);
        $this->assertArrayNotHasKey(1, $found, 'الملغى نفسُه لا يُقيَّم');
    }

    /** وأقدمُ نشطٌ بطابعته لم يُرَ منذ فُعّل الأحدث — مشتبه، لا مؤكَّد */
    public function test_an_unused_older_till_holding_the_printer_is_suspected(): void
    {
        $found = TillHealth::assess(
            [$this->till(1, ['last_seen_at' => '2026-10-19 18:00:00']), $this->till(2, ['activated_at' => '2026-10-20 09:00:00'])],
            [$this->printer(1, 1)],
        );

        $this->assertSame([TillHealth::STALE_TILL_HOLDS_PRINTER], $this->codes($found, 2));
        $this->assertSame(TillHealth::SUSPECTED, $found[2][0]['level']);
    }

    /** صندوقان يعملان، واحدٌ بلا طابعة — مشروع */
    public function test_two_tills_in_use_one_without_a_printer_is_legitimate(): void
    {
        $found = TillHealth::assess(
            [$this->till(1), $this->till(2, ['activated_at' => '2026-10-20 09:00:00'])],
            [$this->printer(1, 1)],
        );

        $this->assertSame([], $found);
    }

    /** وصندوقٌ بلا طابعةٍ في متجرٍ لا طابعةَ فيه — مشروع: يطبع من المتصفّح أو لا يطبع */
    public function test_a_shop_without_printers_is_not_broken(): void
    {
        $this->assertSame([], TillHealth::assess([$this->till(1), $this->till(2)], []));
    }

    /** والطابعةُ على صندوقٍ ملغى في فرعٍ آخر لا تُنسب إلى هذا */
    public function test_a_revoked_till_in_another_branch_is_not_this_ones_printer(): void
    {
        $found = TillHealth::assess(
            [$this->till(1, ['status' => PosDevice::REVOKED, 'branch_id' => 11]), $this->till(2)],  // رقمٌ مقصود: فرعٌ آخرُ في صفوفٍ خام
            [$this->printer(1, 1)],
        );

        $this->assertSame([], $found);
    }

    /** طابعتان ولا «طابعة إيصال» مسمّاة — يُطبع على الأقدم كما كان، فيُراجَع ولا يُقال «عطل» */
    public function test_two_unnamed_printers_are_ambiguous_and_a_named_one_settles_it(): void
    {
        $found = TillHealth::assess([$this->till(1)], [$this->printer(1, 1), $this->printer(2, 1)]);
        $this->assertSame([TillHealth::AMBIGUOUS_RECEIPT], $this->codes($found, 1));
        $this->assertSame(TillHealth::SUSPECTED, $found[1][0]['level']);

        $found = TillHealth::assess([$this->till(1)], [$this->printer(1, 1), $this->printer(2, 1, ['is_receipt_printer' => true])]);
        $this->assertSame([], $found);
    }

    public function test_a_disabled_named_printer_is_said(): void
    {
        $found = TillHealth::assess([$this->till(1)], [
            $this->printer(1, 1, ['is_receipt_printer' => true, 'active' => false]),
            $this->printer(2, 1),
        ]);

        $this->assertSame([TillHealth::RECEIPT_DISABLED], $this->codes($found, 1));
    }

    /** وعنوانٌ يرفضه التطبيق — بقاعدة التطبيق نفسِها؛ والمنفذُ لا يُفترض 9100 */
    public function test_an_address_the_app_refuses_is_confirmed(): void
    {
        $found = TillHealth::assess([$this->till(1)], [$this->printer(1, 1, ['address' => '192.168.1.50:9100', 'port' => 9101])]);

        $this->assertSame([TillHealth::INVALID_ADDRESS], $this->codes($found, 1));
        $this->assertSame(1, $found[1][0]['peripheral']);
    }

    /** وUSB أو بلوتوث بلا عنوان ليس «عنوانًا غير صالح» */
    public function test_a_usb_or_bluetooth_printer_has_no_address_to_refuse(): void
    {
        $found = TillHealth::assess([$this->till(1)], [$this->printer(1, 1, ['connection' => 'usb', 'address' => null])]);
        $this->assertSame([], $found);

        $found = TillHealth::assess([$this->till(1)], [$this->printer(1, 1, ['connection' => 'bluetooth', 'address' => null])]);
        $this->assertSame([], $found);
    }

    /** المكرّرة: العنوانُ والمنفذُ الفعليّ نفسُهما (الفارغُ 9100) — والمنفذُ الآخرُ طابعةٌ أخرى */
    public function test_the_same_printer_twice_is_a_duplicate_but_another_port_is_not(): void
    {
        $twice = [
            $this->printer(1, 1, ['address' => '192.168.1.50', 'port' => null, 'is_receipt_printer' => true]),
            $this->printer(2, 1, ['address' => '192.168.1.50', 'port' => 9100]),
        ];
        $found = TillHealth::assess([$this->till(1)], $twice);
        $this->assertSame([TillHealth::DUPLICATE_PRINTER], $this->codes($found, 1));
        $this->assertSame(TillHealth::CONFIRMED, $found[1][0]['level']);

        $twoPorts = [
            $this->printer(1, 1, ['address' => '192.168.1.50', 'port' => 9100, 'is_receipt_printer' => true]),
            $this->printer(2, 1, ['address' => '192.168.1.50', 'port' => 9101]),
        ];
        $this->assertSame([], TillHealth::assess([$this->till(1)], $twoPorts));
    }

    /** والاسمُ والتوصيلُ نفسُهما وحدهما مشتبه: طابعتا USB تُسمّيان «طابعة» قد تكونان اثنتين */
    public function test_the_same_name_alone_is_only_suspected(): void
    {
        $found = TillHealth::assess([$this->till(1)], [
            $this->printer(1, 1, ['connection' => 'usb', 'address' => null, 'name' => 'طابعة', 'is_receipt_printer' => true]),
            $this->printer(2, 1, ['connection' => 'usb', 'address' => null, 'name' => 'طابعة']),
        ]);

        $this->assertSame([TillHealth::SAME_NAME_PRINTER], $this->codes($found, 1));
        $this->assertSame(TillHealth::SUSPECTED, $found[1][0]['level']);
        $this->assertSame(2, $found[1][0]['peripheral']);
    }

    public function test_auto_print_on_a_printer_that_is_not_the_receipt_printer_has_no_effect(): void
    {
        $found = TillHealth::assess([$this->till(1)], [
            $this->printer(1, 1, ['is_receipt_printer' => true]),
            $this->printer(2, 1, ['auto_print' => true]),
        ]);

        $this->assertSame([TillHealth::AUTO_PRINT_ELSEWHERE], $this->codes($found, 1));
        $this->assertSame(2, $found[1][0]['peripheral']);
    }

    /**
     * ولكلّ رمزٍ جملةٌ للتاجر ودرجة — والمشتبهُ يُقال بشرطه («إن كان…»)، فلا
     * يُخبَر تاجرٌ بعطلٍ لم يثبت.
     */
    public function test_every_code_has_a_level_and_a_sentence(): void
    {
        foreach (TillHealth::LEVELS as $code => $level) {
            $this->assertContains($level, [TillHealth::CONFIRMED, TillHealth::SUSPECTED]);
            $this->assertNotSame($code, TillHealth::message($code), 'لا جملة للرمز '.$code);
        }

        foreach ([TillHealth::PRINTER_ON_REVOKED_TILL, TillHealth::STALE_TILL_HOLDS_PRINTER, TillHealth::SAME_NAME_PRINTER] as $code) {
            foreach (['ar' => 'إن ', 'en' => 'If '] as $locale => $if) {
                app()->setLocale($locale);
                $this->assertStringContainsString($if, TillHealth::message($code), "المشتبهُ يُقال بلا شرط ({$locale}): {$code}");
            }
        }
    }

    /** وما يجهله التدقيقُ عن صندوقين لا يُعدّ مؤكَّدًا أبدًا: الأنماطُ بين صندوقين كلُّها مشتبهة */
    public function test_nothing_read_across_two_tills_is_ever_confirmed(): void
    {
        $this->assertSame(TillHealth::SUSPECTED, TillHealth::LEVELS[TillHealth::PRINTER_ON_REVOKED_TILL]);
        $this->assertSame(TillHealth::SUSPECTED, TillHealth::LEVELS[TillHealth::STALE_TILL_HOLDS_PRINTER]);
    }
}
