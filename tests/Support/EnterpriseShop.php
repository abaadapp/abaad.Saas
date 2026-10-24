<?php

namespace Tests\Support;

use App\Models\Branch;
use App\Models\BranchStock;
use App\Models\Business;
use App\Models\Plan;
use App\Models\Product;
use App\Models\User;
use App\Support\PlanFeatures;

/**
 * متجرٌ على باقة المؤسسات بثلاثة فروع وموظّفين بأدوارٍ وإسنادات — ومتجرٌ جار.
 *
 * بياناتٌ اصطناعيّة كلُّها: لا تاجرَ حقيقيّ ولا رقمَ صفٍّ مكتوبٌ باليد.
 */
trait EnterpriseShop
{
    protected Business $shop;

    protected Business $neighbour;

    protected Plan $enterprise;

    protected Branch $muscat;

    protected Branch $salalah;

    protected Branch $sohar;

    protected User $owner;

    /** مديرُ فرع مسقط وحده */
    protected User $muscatManager;

    /** مديرُ فرع صلالة وحده */
    protected User $salalahManager;

    /** أمينُ مخزن صلالة */
    protected User $salalahKeeper;

    /** بائعٌ في مسقط — لا فعلَ له في التحويلات */
    protected User $seller;

    protected Product $rose;

    protected Product $wrap;

    protected function makeEnterpriseShop(): void
    {
        $this->enterprise = Plan::create([
            'name' => 'باقة المؤسسات', 'monthly_price' => 69.9, 'yearly_price' => 699,
            'code' => Plan::CODE_ENTERPRISE, 'capabilities' => PlanFeatures::keys(),
        ]);

        $this->shop = Business::create(['name' => 'ورد الشركة', 'status' => 'نشط', 'plan_id' => $this->enterprise->id]);
        $this->neighbour = Business::create(['name' => 'المتجر الجار', 'status' => 'نشط', 'plan_id' => $this->enterprise->id]);

        $this->muscat = Branch::create(['business_id' => $this->shop->id, 'name' => 'مسقط']);
        $this->salalah = Branch::create(['business_id' => $this->shop->id, 'name' => 'صلالة']);
        $this->sohar = Branch::create(['business_id' => $this->shop->id, 'name' => 'صحار']);

        $this->owner = $this->staff('المالك', 'admin');
        $this->muscatManager = $this->staff('مدير مسقط', 'manager', [$this->muscat]);
        $this->salalahManager = $this->staff('مدير صلالة', 'manager', [$this->salalah]);
        $this->salalahKeeper = $this->staff('أمين مخزن صلالة', 'inventory', [$this->salalah]);
        $this->seller = $this->staff('بائع مسقط', 'sales', [$this->muscat]);

        $this->rose = $this->stocked('ورد أحمر', 1.5, [$this->muscat->id => 50, $this->salalah->id => 4]);
        $this->wrap = $this->stocked('ورق تغليف', 0.25, [$this->muscat->id => 20]);
    }

    protected function staff(string $name, string $role, array $branches = [], ?Business $in = null): User
    {
        $in ??= $this->shop;
        $user = User::create([
            'business_id' => $in->id, 'name' => $name, 'email' => uniqid('u', true).'@enterprise.local',
            'password' => bcrypt('secret'), 'role' => $role, 'status' => 'نشط',
        ]);
        $user->branches()->attach(array_map(fn (Branch $b) => $b->id, $branches));

        return $user;
    }

    /** صنفٌ على رفوف فروعٍ بعينها — والإجماليُّ مجموعُها */
    protected function stocked(string $name, float $cost, array $stock, ?Business $in = null): Product
    {
        $in ??= $this->shop;
        $product = Product::create([
            'business_id' => $in->id, 'name' => $name, 'price' => $cost * 3, 'cost' => $cost,
            'quantity' => array_sum($stock), 'alert_qty' => 5, 'active' => true,
        ]);
        foreach ($stock as $branchId => $qty) {
            BranchStock::adjust($in->id, $branchId, $product->id, $qty);
        }

        return $product;
    }

    /** [الإجماليّ، مسقط، صلالة، صحار] */
    protected function shelves(Product $p): array
    {
        $at = fn (Branch $b) => (int) BranchStock::where('product_id', $p->id)->where('branch_id', $b->id)->value('quantity');

        return [(int) $p->fresh()->quantity, $at($this->muscat), $at($this->salalah), $at($this->sohar)];
    }

    /** مجموعُ الفروع = كميّةُ المنتج — التوازنُ الذي يقوم عليه النظام */
    protected function assertBalanced(Product $p): void
    {
        $this->assertSame(
            (int) $p->fresh()->quantity,
            (int) BranchStock::where('product_id', $p->id)->sum('quantity'),
            'انكسر «مجموع الفروع = كمية المنتج» لـ'.$p->name,
        );
    }
}
