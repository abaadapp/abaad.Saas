<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * سجلُّ نشرِ متجر الواجهة الخاصّة — أخو `Website` لا نسخةٌ منه.
 *
 * `Website` يملك صفحاتٍ وأقسامًا تُبنى؛ وهذا لا يملك إلّا **مسوّدةً**
 * ومؤشّرَي نشر. لأنّ الواجهةَ الخاصّة بناؤها في القالب، والذي يُحرَّر فيها
 * مفاتيحُ نصٍّ وصورةٍ وترتيب (انظر `Store\StoreContent::VERSIONED`).
 *
 * وتاريخُهما واحد: كلاهما يكتب في `website_versions` — انظر الهجرة.
 *
 * ووجودُ الصفّ نفسُه هو مفتاحُ التشغيل: متجرٌ بلا صفٍّ هنا يعمل كما كان
 * قبل نظام النشر — يُحفظ فيظهر. فالتفعيلُ متجرًا متجرًا، والتراجعُ حذفُ
 * صفٍّ لا ترحيلٌ عكسيّ.
 */
class StoreSite extends Model
{
    protected $guarded = [];

    protected $casts = [
        'draft' => 'array',
        'draft_saved_at' => 'datetime',
        'published_at' => 'datetime',
    ];

    public function business(): BelongsTo { return $this->belongsTo(Business::class); }

    public function publishedVersion(): BelongsTo { return $this->belongsTo(WebsiteVersion::class, 'published_version_id'); }

    public function saver(): BelongsTo { return $this->belongsTo(User::class, 'draft_saved_by'); }

    /** نسخُ هذا المتجر — من التاريخ المشترك، مصفّاةً بنوعها */
    public function versions(): HasMany
    {
        return $this->hasMany(WebsiteVersion::class, 'business_id', 'business_id')
            ->where('kind', WebsiteVersion::THEME);
    }
}
