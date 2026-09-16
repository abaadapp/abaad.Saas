<?php

namespace App\Console\Commands;

use App\Support\Archive\Period;

/**
 * أرشيفُ الشهر المنقضي لكلّ متجر — يُجدول أوّلَ كلّ شهر، الساعةَ الثالثة.
 *
 * والجسمُ في `ArchiveRun`: هو نفسُه في الأسبوعيّ، والفرقُ نوعُ المدى.
 * والاسمُ لم يُبدَّل — `archive:monthly` يُنادى من الجدول ومن يد المشغّل.
 */
class ArchiveMonthly extends ArchiveRun
{
    protected $signature = 'archive:monthly
        {--business= : معرّف متجر محدّد (اختياري)}
        {--month= : شهرٌ بعينه بصيغة YYYY-MM (افتراضيًّا: الشهر المنقضي)}';

    protected $description = 'طلبُ أرشيف الشهر المنقضي لكل المتاجر — يُبنى في الطابور';

    protected function type(): string
    {
        return Period::MONTHLY;
    }

    protected function periodOption(): string
    {
        return 'month';
    }
}
