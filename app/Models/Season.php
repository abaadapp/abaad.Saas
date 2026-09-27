<?php

namespace App\Models;

use App\Support\SeasonCycle;
use App\Support\Seasons;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * الموسم — مدّةٌ تجمع أصنافًا قائمةً وتُبرزها في الصندوق والموقع.
 *
 * ليس نوعَ منتجٍ ولا قسمًا ولا مخزونًا ولا سعرًا: الصنفُ يبقى كما هو أينما
 * كان، والموسمُ مرشِّحٌ يُظهره حينًا ويُذكّر بالاستعداد له قبله.
 */
class Season extends Model
{
    protected $guarded = [];

    protected $casts = [
        'starts_at' => 'date',
        'ends_at' => 'date',
        'active' => 'boolean',
        'show_in_pos' => 'boolean',
        'show_on_website' => 'boolean',
        'repeats' => 'boolean',
        'cycle_overrides' => 'array',
    ];

    public const UPCOMING = 'upcoming';

    public const ACTIVE = 'active';

    public const ENDED = 'ended';

    public const INACTIVE = 'inactive';

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    /** أصنافُه — والمحذوفُ حذفًا ناعمًا يسقط وحدَه بنطاق `SoftDeletes` */
    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'season_product')->withTimestamps();
    }

    public function reminders(): HasMany
    {
        return $this->hasMany(SeasonReminder::class)->orderBy('id');
    }

    /**
     * دورتُه التي تخصّ اليومَ — تواريخُها ومفتاحُها.
     *
     * وتاريخا الصفّ مرساةٌ لا موعدٌ جارٍ حين يتكرّر: الحسابُ في
     * `SeasonCycle`، وكلُّ شاشةٍ تقرأ منه لا من العمودين مباشرةً.
     *
     * @return array{starts_at: Carbon, ends_at: Carbon, key: string, cycle: string, overridden: bool}
     */
    public function cycle(?Carbon $today = null): array
    {
        return SeasonCycle::current($this, $today);
    }

    /** حالتُه اليومَ — قاعدةٌ واحدةٌ للوحة والصندوق والموقع (انظر Seasons::status) */
    public function status(?Carbon $today = null): string
    {
        return Seasons::status($this, $today);
    }

    /** أهو جارٍ اليوم؟ — فعّالٌ والتاريخُ داخل مدّته */
    public function isLive(?Carbon $today = null): bool
    {
        return $this->status($today) === self::ACTIVE;
    }

    /**
     * المواسمُ الجاريةُ اليوم — للصندوق والموقع، لا للوحة.
     *
     * ═══ والمتكرّرُ لا يُسأل عنه عمودًا ═══
     *
     * تاريخا الصفّ مرساةٌ، فموسمٌ يعود كلَّ سنةٍ تكون مرساتُه قبل سنواتٍ
     * ولا تقول شيئًا عن اليوم. فيُجلب المتكرّرُ كلُّه ثمّ يُرشَّح بالحساب
     * (`Seasons::live`)، ويبقى غيرُ المتكرّر على شرطه في القاعدة — وهو
     * الأكثرُ، فلا يُحمَّل الصندوقُ ما لا يلزم.
     */
    public function scopeLive($query, ?Carbon $today = null)
    {
        $day = ($today ?? today())->toDateString();

        return $query->where('active', true)
            ->where(fn ($q) => $q
                ->where('repeats', true)
                ->orWhere(fn ($w) => $w
                    ->whereDate('starts_at', '<=', $day)
                    ->whereDate('ends_at', '>=', $day)));
    }
}
