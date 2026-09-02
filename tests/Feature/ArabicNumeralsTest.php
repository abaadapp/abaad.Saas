<?php

namespace Tests\Feature;

use App\Http\Middleware\NormalizeNumbers;
use App\Models\Branch;
use App\Models\Business;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\Product;
use App\Models\PurchaseOrderItem;
use App\Models\Supplier;
use App\Models\User;
use App\Support\Numerals;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * الأرقام تصل إنجليزيّةً مهما كُتبت.
 *
 * لوحةُ المفاتيح العربية تكتب «٥٠٠» لا «500»، وكان ذلك يُردّ بـ«يجب أن يكون
 * رقمًا» — رسالةٌ لا يفهمها من كتبه لأنّه يراه رقمًا وهو كذلك. وكان النظام
 * يُصحّح عشرين اسمَ حقلٍ ماليّ مكتوبةً باليد، فالكميّةُ والهاتفُ ونسبةُ
 * الضريبة خارجها.
 *
 * وما تفحصه هذه الحالات ثلاثة: أنّ الرقم يصل رقمًا **أيًّا كان اسم حقله**،
 * وأنّ الحروف لا تُمسّ، وأنّ كلمة السرّ لا تُمسّ — فتبديلُ حرفٍ فيها حسابٌ
 * لا يُفتح بعدها.
 */
class ArabicNumeralsTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::create(['name' => 'متجري', 'type' => 'عام', 'status' => 'نشط']);
        Branch::create(['business_id' => $this->business->id, 'name' => 'الرئيسي']);
        Currency::create([
            'business_id' => $this->business->id, 'code' => 'OMR', 'name' => 'ريال عماني',
            'symbol' => 'ر.ع', 'rate' => 1, 'is_base' => true, 'active' => true,
        ]);

        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'صاحب النشاط', 'email' => 'o@abaad.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);
    }

    /* --------------------------- القاعدة --------------------------- */

    public function test_arabic_and_persian_digits_become_english(): void
    {
        $this->assertSame('0123456789', Numerals::toAscii('٠١٢٣٤٥٦٧٨٩'));
        $this->assertSame('0123456789', Numerals::toAscii('۰۱۲۳۴۵۶۷۸۹'));
        $this->assertSame('12.5', Numerals::toAscii('١٢٫٥'));
    }

    /**
     * ولا يُمسّ إلّا الرقم.
     *
     * «شارع ٢٣» عنوانُ زبون، و«الطلب ملغى» ملاحظةٌ كتبها التاجر. فتبديلُ حرفٍ
     * عربيّ فيهما يعني نظامًا يُعيد كتابة ما كتبه صاحبُه.
     */
    public function test_the_letters_are_left_exactly_as_written(): void
    {
        $this->assertSame('شارع 23', Numerals::toAscii('شارع ٢٣'));
        $this->assertSame('باقة ورد حمراء', Numerals::toAscii('باقة ورد حمراء'));
        // والفاصلة العربية «،» ترد في الكلام، فلا تُبدَّل
        $this->assertSame('ورد، وبطاقة', Numerals::toAscii('ورد، وبطاقة'));
    }

    /* ------------------------- على كلّ حقل ------------------------- */

    /**
     * وليس على قائمةٍ من الأسماء.
     *
     * الكميّة كانت خارج القائمة المالية: يكتب التاجر «٥» في مخزون منتجٍ
     * فيُردّ عليه بأنّها ليست رقمًا.
     */
    public function test_a_quantity_typed_in_arabic_is_accepted(): void
    {
        $this->actingAs($this->owner)->post(route('admin.products.store'), [
            'name' => 'باقة ورد',
            'price' => '١٢٫٥',
            'quantity' => '٥',
            'alert_qty' => '٢',
        ])->assertSessionHasNoErrors();

        $product = Product::where('business_id', $this->business->id)->firstOrFail();

        $this->assertSame(12.5, (float) $product->price);
        $this->assertSame(5, (int) $product->quantity);
        $this->assertSame(2, (int) $product->alert_qty);
    }

    /** والهاتف كذلك — وهو أكثر ما يُكتب بلوحةٍ عربية */
    public function test_a_phone_typed_in_arabic_is_stored_in_english(): void
    {
        $this->actingAs($this->owner)->post(route('admin.customers.store'), [
            'name' => 'زبون',
            'phone' => '٩١٢٣٤٥٦٧',
        ])->assertSessionHasNoErrors();

        $this->assertSame('91234567', Customer::where('business_id', $this->business->id)->value('phone'));
    }

    /**
     * والعنوان يُكتب رقمُه إنجليزيًّا وحروفُه كما هي — في الطلب نفسه.
     *
     * الفحص على طلبٍ حقيقيّ لا على الدالّة وحدها: بين الدالّة والحقل وسيطٌ
     * يمشي على الحمولة كلّها، وقد يُخطئ في العمق أو في المصفوفات.
     */
    public function test_a_nested_value_is_normalised_too(): void
    {
        $this->actingAs($this->owner)->post(route('admin.customers.store'), [
            'name' => 'زبون',
            'phone' => '٩١٢٣٤٥٦٧',
            'address' => 'شارع ٢٣، بيت ٥',
        ])->assertSessionHasNoErrors();

        $this->assertSame('شارع 23، بيت 5', Customer::where('business_id', $this->business->id)->value('address'));
    }

    /* --------------------------- الاستثناء --------------------------- */

    /**
     * كلمة السرّ لا تُمسّ.
     *
     * لو بُدّلت أرقامُها عند الحفظ وعند الدخول لَبدا كلُّ شيءٍ سليمًا — إلى أن
     * يمرّ الحقلُ يومًا بمسارٍ لا يمرّ بهذا الوسيط، فلا يُفتح الحساب ولا يُعرف
     * لماذا. فتبقى كما كُتبت حرفًا بحرف.
     */
    public function test_a_password_is_never_rewritten(): void
    {
        $this->assertTrue(NormalizeNumbers::untouched('password'));
        $this->assertTrue(NormalizeNumbers::untouched('password_confirmation'));
        $this->assertTrue(NormalizeNumbers::untouched('current_password'));

        $this->owner->update(['password' => Hash::make('سرّي٥٠٠')]);

        $this->post(route('login.attempt'), [
            'email' => 'o@abaad.om',
            'password' => 'سرّي٥٠٠',
        ]);

        $this->assertAuthenticatedAs($this->owner);
    }

    /* ------------------------- لا حقلَ يُنسى ------------------------- */

    /**
     * كلُّ حقلِ كتابةٍ في الواجهة يمرّ بالحارس.
     *
     * الحارس في `Input` و`Textarea`، فما رُسم بـ`<input>` مباشرةً يفلت منه:
     * يكتب التاجر رقمًا بلوحةٍ عربية في حقل `type="number"` فلا يظهر شيء —
     * المتصفّح يعدّه قيمةً غير صالحة فيُفرغه، ولا رسالةَ تقول لماذا.
     *
     * فتُحصى الحقول المرسومة يدويًّا: من قبل خانةَ اختيارٍ أو ملفًّا فلا شأن
     * له بالأرقام، ومن قبل نصًّا فعليه أن يُركّب الحارس.
     */
    public function test_no_hand_drawn_text_field_escapes_the_guard(): void
    {
        $guilty = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(resource_path('js')));

        foreach ($files as $file) {
            if ($file->isDir() || ! in_array($file->getExtension(), ['tsx', 'ts'], true)) {
                continue;
            }

            $path = $file->getPathname();

            // بيتُ الحارس نفسه: `Input` تُركّبه، و`numerals.ts` تشرحه في توثيقها
            if (str_ends_with($path, 'ui/input.tsx') || str_ends_with($path, 'lib/numerals.ts')) {
                continue;
            }

            $source = file_get_contents($path);

            /*
             * والعنصر يُقرأ إلى «/>» لا إلى أوّل «>».
             *
             * الأولى كانت تقطعه عند سهم دالّةٍ في خاصيّة (`(el) => {`) فتُخفي
             * ما بعده — ومنه `type="file"`، فيُتّهم حقلُ ملفٍّ بأنّه حقل كتابة.
             */
            preg_match_all('/<input\b.*?\/>/s', $source, $matches);

            foreach ($matches[0] as $element) {
                $isTyped = preg_match('/type=(["\'])(checkbox|radio|file)\1/', $element);

                if ($isTyped || str_contains($element, 'useAsciiDigits') || str_contains($element, 'ref={attach}')
                    || str_contains($element, 'ref={searchRef}')) {
                    continue;
                }

                $guilty[] = basename($path);
            }
        }

        $this->assertSame([], array_unique($guilty), 'حقل كتابةٍ مرسومٌ يدويًّا بلا حارس أرقام');
    }

    /* ------------------------- الفاصلة العشريّة ------------------------- */

    /**
     * الفاصلة العربية «،» فاصلٌ عشريّ في حقل مال — لا حرفٌ يُمحى.
     *
     * ولوحةُ المفاتيح العربية تُخرجها حيث تُخرج الإنجليزيةُ «,»، فهي ما يضغطه
     * التاجر وهو يعني الفاصل. وكانت تُمحى مع فواصل الآلاف: «4،5» تصير «45» —
     * **عشرةُ أضعاف الثمن**، بلا خطأٍ ولا رسالة، ولا يظهر إلّا في فاتورة.
     */
    public function test_the_arabic_comma_is_read_as_a_decimal_point_not_erased(): void
    {
        $this->assertSame('4.5', NormalizeNumbers::normalize('4،5'));
        $this->assertSame('4.5', NormalizeNumbers::normalize(Numerals::toAscii('٤،٥')));
        $this->assertSame('12.750', NormalizeNumbers::normalize('12،750'));
    }

    /**
     * وأكثرُ من واحدةٍ فواصلُ آلاف — بالقاعدة نفسها التي تحكم أختها اللاتينية.
     *
     * وقاعدتان لفاصلتين تفترقان يومًا: تُقرأ «1،234،567» مليونًا في موضعٍ
     * وواحدًا وربعًا في آخر.
     */
    public function test_more_than_one_arabic_comma_is_read_as_thousands(): void
    {
        $this->assertSame('1234567', NormalizeNumbers::normalize('1،234،567'));
        $this->assertSame(
            NormalizeNumbers::normalize('1,234,567'),
            NormalizeNumbers::normalize('1،234،567'),
            'الفاصلتان تُقرآن بقاعدتين مختلفتين',
        );
    }

    public function test_a_cost_typed_with_an_arabic_comma_reaches_the_purchase_order(): void
    {
        $supplier = Supplier::create(['business_id' => $this->business->id, 'name' => 'مشتل الوادي']);

        $branch = Branch::where('business_id', $this->business->id)->firstOrFail();

        $this->actingAs($this->owner)
            ->withSession(['current_branch' => $branch->id])
            ->post(route('admin.purchases.store'), [
                'supplier_id' => $supplier->id,
                'branch_id' => $branch->id,
                'items' => [['name' => 'ورد جوري', 'cost' => '٤،٥', 'quantity' => '٢']],
            ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('purchase_order_items', ['name' => 'ورد جوري', 'quantity' => 2]);

        $item = PurchaseOrderItem::where('name', 'ورد جوري')->firstOrFail();

        $this->assertSame('4.500', (string) $item->cost, 'كلفةُ الوحدة وصلت عشرةَ أضعافها');
    }

    /**
     * ولا حقلَ يُصادَق رقمًا يبقى خارج قائمة التوحيد.
     *
     * القائمة تُكتب باليد، وقائمةٌ كذلك تنسى التاليَ دائمًا. وقد نسيت عشرة
     * حقول — ومنها **الرواتب كلُّها**: يكتب المدير راتبًا «٥٠٠،٧٥» بلوحةٍ
     * عربية فيُردّ بـ«يجب أن يكون رقمًا» على رقمٍ صحيح، ويبقى الراتب القديم.
     *
     * فيُقرأ الواجبُ من قواعد التحقّق نفسها لا من ذاكرة من كتبها.
     */
    public function test_every_field_validated_as_a_number_is_normalised(): void
    {
        $missing = [];

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(app_path('Http'), \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            // 'اسم_الحقل' => [ … 'numeric' … ] — على سطرٍ واحد كما تُكتب في النظام
            preg_match_all(
                '/[\'"]([a-z_]+)[\'"]\s*=>\s*\[[^\]]*[\'"]numeric[\'"][^\]]*\]/',
                file_get_contents($file->getPathname()),
                $matches,
            );

            foreach ($matches[1] as $field) {
                if (! in_array($field, NormalizeNumbers::FIELDS, true)) {
                    $missing[$field] = $file->getFilename();
                }
            }
        }

        $this->assertSame([], $missing, 'حقلٌ يُصادَق رقمًا ولا تُوحَّد فواصلُه');
    }

    /**
     * ولا حقلَ عشريٍّ يبقى `type="number"`.
     *
     * حقلُ الأرقام يرفض ما لا يكتمل: «4.» قيمةٌ غير صالحة عنده فيُفرغ نفسه،
     * فلا سبيل إلى وضع نقطةٍ مكان الفاصلة العربية. والحقول العشريّة تُرسم
     * نصًّا بلوحةٍ عشريّة — يعرفها `Input` من `step` الكسريّ، وما رُسم يدويًّا
     * يقولها بنفسه.
     */
    public function test_no_fractional_field_is_still_a_number_input(): void
    {
        $guilty = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(resource_path('js')));

        foreach ($files as $file) {
            if ($file->isDir() || ! in_array($file->getExtension(), ['tsx', 'ts'], true)) {
                continue;
            }

            // بيتُ القاعدة نفسها
            if (str_ends_with($file->getPathname(), 'ui/input.tsx')) {
                continue;
            }

            preg_match_all('/<[Ii]nput\b.*?\/>/s', file_get_contents($file->getPathname()), $matches);

            foreach ($matches[0] as $element) {
                /*
                 * والكسرُ يُلتمس في نصّ الخاصيّة كلِّه لا في قيمةٍ حرفيّة.
                 *
                 * `step={field === 'price' ? '0.001' : '1'}` كسريٌّ كأخيه
                 * المكتوب `step="0.001"` — وقاعدةٌ تقرأ الحرفيّ وحده تمرّ عليه.
                 * وهو ما وقع فعلًا: الحقلُ المعطوب نفسُه أفلت من أوّل صياغة
                 * لهذا الحارس.
                 */
                $fractional = preg_match('/step=\{?[^}\n]*(?:0\.\d+|\bany\b)/', $element);
                $isNumber = preg_match('/type=(["\'])number\1/', $element);

                /*
                 * و`<Input>` معفاة: هي التي تُبدّل النوع بنفسها حين يكون
                 * `step` كسريًّا. والمرصود ما رُسم بـ`<input>` صغيرة.
                 */
                if ($fractional && $isNumber && str_starts_with($element, '<input')) {
                    $guilty[] = basename($file->getPathname());
                }
            }
        }

        $this->assertSame([], array_unique($guilty), 'حقلٌ عشريٌّ ما زال type="number" — يرفض الفاصلة');
    }
}
