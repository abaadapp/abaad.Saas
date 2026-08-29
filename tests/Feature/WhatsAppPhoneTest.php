<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Customer;
use App\Support\WhatsAppPhone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * الرقم كما يكتبه الناس — وكما يريده واتساب.
 *
 * الفرق بينهما هو سبب أكثر الرسائل التي «لا تصل»: رقمٌ صحيح مكتوبٌ بمسافةٍ
 * أو بأرقامٍ عربية يُرفض عند المزوّد بعد أن تكون الحصّة قد خُصمت.
 */
class WhatsAppPhoneTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_reads_the_shapes_people_actually_write(): void
    {
        $cases = [
            '91234567' => '96891234567',          // محلّي عمانيّ
            '+968 9123 4567' => '96891234567',    // دوليّ بمسافاتٍ و+
            '00968-91234567' => '96891234567',    // ببادئة الاتصال الدولي
            '96891234567' => '96891234567',       // كما يريده واتساب أصلًا
            '٩١٢٣٤٥٦٧' => '96891234567',          // بأرقامٍ عربية
            '(968) 9123-4567' => '96891234567',   // بأقواسٍ وشرطة
        ];

        foreach ($cases as $raw => $expected) {
            $this->assertSame($expected, WhatsAppPhone::normalize((string) $raw), "الرقم: {$raw}");
        }
    }

    /** والرقم الخليجيّ الآخر يبقى كما هو — الزبون يُهدي إلى دبي والرياض */
    public function test_a_foreign_number_keeps_its_own_country_code(): void
    {
        $this->assertSame('971501234567', WhatsAppPhone::normalize('+971 50 123 4567'));
        $this->assertSame('966501234567', WhatsAppPhone::normalize('00966501234567'));
    }

    public function test_what_is_not_a_number_is_refused(): void
    {
        foreach ([null, '', '   ', 'لا رقم', '123', '1234567890123456789'] as $raw) {
            $this->assertNull(WhatsAppPhone::normalize($raw), 'المُدخل: '.var_export($raw, true));
        }
    }

    /**
     * ولا يُكتب الناتج فوق بيانات العميل.
     *
     * التطبيع للإرسال لا للبيانات: من كتب رقمه بشكلٍ يفهمه يجده كما كتبه.
     * وتصحيحُ بيانات الناس بلا طلبهم يُفسد أكثر ممّا يُصلح — وهنا يفسد
     * البحث عن العميل برقمه كما حفظه.
     */
    public function test_normalizing_never_rewrites_the_stored_customer_row(): void
    {
        $business = Business::create(['name' => 'محل ورد', 'type' => 'محل ورود', 'status' => 'نشط']);
        $customer = Customer::create([
            'business_id' => $business->id, 'name' => 'زبون', 'phone' => '+968 9123 4567',
        ]);

        $this->assertSame('96891234567', WhatsAppPhone::normalize($customer->phone));
        $this->assertSame('+968 9123 4567', $customer->fresh()->phone);
    }
}
