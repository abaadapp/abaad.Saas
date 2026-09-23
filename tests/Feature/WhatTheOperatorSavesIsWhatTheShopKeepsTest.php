<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ما يحفظه مديرُ المنصّة هو ما يبقى — ولا يُطفأ مفتاحٌ لم يُمسّ.
 *
 * ═══ وأخطرُ ما في شاشةِ الشركات ═══
 *
 * حقلٌ يسقط من قيم النموذج الابتدائيّة لا يُخطئ شيئًا: يُعرض مطفأً،
 * ويُحفظ مطفأً، وتقول الشاشةُ «تم التحديث بنجاح». فيفقد التاجرُ قدرةً
 * فُتحت له بلا أن يمسّها أحد — ولا يربط أحدٌ بين اختفائها وبين تعديلِ
 * هاتفٍ جرى قبلها بأسبوع.
 *
 * والفئةُ والواجهةُ لا تكشفانه: هما `nullable` فتخرجان من المصفوفة
 * المتحقَّقة حين تغيبان، فتبقيان — ويُقرأ الحفظُ سليمًا.
 */
class WhatTheOperatorSavesIsWhatTheShopKeepsTest extends TestCase
{
    use RefreshDatabase;

    private User $super;

    private Business $shop;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->setLocale('ar');

        $this->super = User::create([
            'business_id' => null, 'name' => 'مدير المنصة', 'email' => 'super@abaad.om',
            'password' => bcrypt('password'), 'role' => 'super_admin', 'status' => 'نشط',
        ]);

        $this->shop = Business::create([
            'name' => 'RIBBON', 'type' => 'محل ورد', 'status' => 'نشط',
            'tier' => 'gold', 'storefront_theme' => 'ribbon', 'boutiques_enabled' => true,
        ]);

        // وللشركة حسابُ دخول — وإلّا طالب `syncAccount` بإنشائه في كل حفظ
        \App\Support\MerchantAccount::create($this->shop, 'ribbon', 'password123');
    }

    /** @param array<string, mixed> $over */
    private function save(array $over = [])
    {
        return $this->actingAs($this->super)->put(
            route('super-admin.businesses.update', $this->shop->id),
            $over + ['name' => $this->shop->name, 'type' => 'محل ورد', 'status' => 'نشط'],
        );
    }

    /* ═══════════ الشاشةُ تقرأ المحفوظ ═══════════ */

    /**
     * شاشةُ التعديل تعرض المفتاحَ كما هو محفوظ — لا مطفأً دائمًا.
     *
     * وهذا نصفُ العطب: مربّعٌ يُعرض مطفأً والقاعدةُ تقول «مفتوح» يجعل
     * المشغّل يظنّ أنّه لم يُفتح، فيفتحه من جديد — أو يحفظ فيُطفئه.
     */
    public function test_the_edit_screen_shows_the_switch_as_it_is_stored(): void
    {
        $props = $this->actingAs($this->super)
            ->get(route('super-admin.businesses.edit', $this->shop->id))
            ->assertOk()->viewData('page')['props'];

        $this->assertTrue($props['business']['boutiques_enabled'], 'الشاشةُ تعرض مفتاحًا مطفأً وهو مفتوح');
        $this->assertSame('gold', $props['business']['tier']);
        $this->assertSame('ribbon', $props['business']['storefront_theme']);
    }

    /* ═══════════ والحفظُ لا يُطفئ ما لم يُمسّ ═══════════ */

    /** تعديلُ حقلٍ لا صلةَ له لا يُطفئ البوتيكات */
    public function test_editing_something_else_does_not_close_the_boutiques(): void
    {
        $this->save(['phone' => '96899110001'])->assertSessionHasNoErrors();

        $this->shop->refresh();

        $this->assertTrue((bool) $this->shop->boutiques_enabled, 'أُطفئ مفتاحٌ لم يُرسَل أصلًا');
        // والفئةُ والواجهةُ تبقيان كما كانتا — وهما ما كان يُخفي العطب
        $this->assertSame('gold', $this->shop->tier);
        $this->assertSame('ribbon', $this->shop->storefront_theme);
        $this->assertSame('96899110001', $this->shop->phone);
    }

    /** ومن أرسله مطفأً يُطفأ — الغيابُ غيرُ الإطفاء */
    public function test_sending_it_off_does_turn_it_off(): void
    {
        $this->save(['boutiques_enabled' => '0'])->assertSessionHasNoErrors();
        $this->assertFalse((bool) $this->shop->refresh()->boutiques_enabled);

        $this->save(['boutiques_enabled' => '1'])->assertSessionHasNoErrors();
        $this->assertTrue((bool) $this->shop->refresh()->boutiques_enabled);
    }

    /**
     * ودورةٌ كاملة: يُفتح، ثمّ يُحرَّر حقلٌ آخر مرّتين، ويبقى مفتوحًا.
     *
     * وهي الحالةُ التي تقع فعلًا: يُفتح المفتاح اليوم، ويُعدَّل العنوانُ
     * بعد أسبوع، والباقةُ بعد شهر — ولا يُفتح تبويبُ البوتيكات ثانيةً.
     */
    public function test_the_switch_survives_a_month_of_ordinary_edits(): void
    {
        $this->save(['boutiques_enabled' => '1'])->assertSessionHasNoErrors();

        $this->save(['address' => 'الخوير'])->assertSessionHasNoErrors();
        $this->save(['city' => 'مسقط'])->assertSessionHasNoErrors();

        $this->assertTrue((bool) $this->shop->refresh()->boutiques_enabled);
    }
}
