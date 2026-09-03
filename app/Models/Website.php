<?php

namespace App\Models;

use App\Support\Website\Blueprints;
use App\Support\Website\Sections;
use App\Support\Website\Templates;
use App\Support\Website\Theme;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * موقع النشاط — واحدٌ لكلّ متجر.
 *
 * وهو المسوّدة: ما في هذا الجدول وجداوله هو ما يعدّله التاجر الآن، لا ما
 * يراه الزائر. والمنشور لقطةٌ مجمّدة في `website_versions` يشير إليها
 * `published_version_id` — فتعديلُ اليوم لا يمسّ موقعًا يعمل حتى يُنشر.
 */
class Website extends Model
{
    protected $guarded = [];

    protected $casts = [
        'theme' => 'array',
        'seo' => 'array',
        'maintenance' => 'boolean',
        'published_at' => 'datetime',
        'draft_saved_at' => 'datetime',
    ];

    /** حالات الموقع كما تُقرأ في اللوحة */
    public const DRAFT = 'draft';

    public const PUBLISHED = 'published';

    public const CHANGED = 'changed';

    public const MAINTENANCE = 'maintenance';

    public function business(): BelongsTo { return $this->belongsTo(Business::class); }

    public function pages(): HasMany { return $this->hasMany(WebsitePage::class)->orderBy('position'); }

    public function sections(): HasMany { return $this->hasMany(WebsiteSection::class); }

    public function versions(): HasMany { return $this->hasMany(WebsiteVersion::class)->orderByDesc('number'); }

    public function publishedVersion(): BelongsTo { return $this->belongsTo(WebsiteVersion::class, 'published_version_id'); }

    public function creator(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }

    /** الأقسام العامّة: ترويسةٌ وتذييل — لا تخصّ صفحة */
    public function globals(): HasMany
    {
        return $this->hasMany(WebsiteSection::class)->whereNotNull('slot')->orderBy('slot');
    }

    public function homePage(): ?WebsitePage
    {
        return $this->pages()->where('is_home', true)->first() ?? $this->pages()->first();
    }

    /**
     * حالُ الموقع بكلمةٍ واحدة.
     *
     * و«فيه تغييرات» حالٌ رابعة لا زينة: التاجر يعدّل ثمّ ينسى أنّه لم ينشر،
     * فيسأل لماذا لا يرى الزائر ما عدّله. والحالُ تقول له قبل أن يسأل.
     */
    public function state(): string
    {
        if ($this->maintenance) {
            return self::MAINTENANCE;
        }

        if (! $this->published_version_id) {
            return self::DRAFT;
        }

        return $this->hasUnpublishedChanges() ? self::CHANGED : self::PUBLISHED;
    }

    /** هل بعد آخر نشرةٍ تعديل؟ — بالعدّاد لا بمقارنة وقتين */
    public function hasUnpublishedChanges(): bool
    {
        return ! $this->published_version_id
            || (int) $this->draft_revision !== (int) $this->published_revision;
    }

    /**
     * ختمُ التعديل — يقرؤه «تم الحفظ ✓» وحسابُ «فيه تغييرات».
     *
     * والزيادة في القاعدة لا في الذاكرة (`increment`): حفظان متزامنان من
     * تبويبين يزيدان مرّتين، وقراءةٌ ثمّ كتابةٌ في PHP تجعل أحدهما يمحو الآخر
     * فيبقى الموقع «بلا تغييرات» وفيه تغيير.
     */
    public function touchDraft(): void
    {
        $this->increment('draft_revision', 1, ['draft_saved_at' => now()]);
    }

    public function goal(): string
    {
        return Blueprints::goal($this->goal);
    }

    public function sells(): bool
    {
        return Blueprints::sells($this->goal);
    }

    /** رموز التصميم كاملةً — المختارُ وما اشتُقّ منه */
    public function tokens(): array
    {
        return Theme::tokens($this->theme ?? Templates::theme($this->template));
    }

    /** قسمٌ عامٌّ بعينه — الترويسة أو التذييل */
    public function slot(string $slot): ?WebsiteSection
    {
        return $this->sections()->where('slot', $slot)->first();
    }

    public function header(): ?WebsiteSection { return $this->slot(Sections::HEADER); }

    public function footer(): ?WebsiteSection { return $this->slot(Sections::FOOTER); }
}
