<?php

namespace App\Support;

use App\Models\Business;
use Illuminate\Support\Facades\Schema;

/**
 * حمولةُ النسخة الاحتياطية لمتجرٍ واحد — للتنزيل اليدويّ وللجدولة معًا.
 *
 * كانت الجداولُ مكتوبةً هنا بأسمائها سبعةَ عشرَ سطرًا، وفي القاعدة أكثرُ من
 * ستّين. فما لم يُذكر لم يُنسخ، ولا شيء يقول ذلك: يفتح التاجر الملفَّ فيراه
 * ممتلئًا، ويكتشف يوم الاستعادة أنّ مخزون فروعه وصناديقَه ودفترَ أستاذه لم
 * تكن فيه — وهو آخرُ يومٍ يصلح للاكتشاف.
 *
 * فصارت تُقرأ من `TenantTables` — قائمةٌ واحدة يقرؤها هذا الملفُّ والاستعادةُ
 * معًا، ويحرسها اختبارٌ يسقط يوم يُضاف جدولٌ لا يُصنَّف.
 *
 * وما لا يُنسخ منصوصٌ عليه بسببه في `TenantTables::NOT_MINE`، والأعمدةُ
 * السرّيّة تُنزع في `SECRETS`: ملفٌّ يُنزَّل على جهازٍ ليس مكانَ رمزِ وصول.
 */
class BackupService
{
    /**
     * النسخةُ الثالثة: الجداولُ كلُّها لا سبعةَ عشرَ.
     *
     * والرقمُ يُقرأ عند الاستعادة: ملفٌّ من الثانية ينقصه أربعون جدولًا،
     * واستعادتُه تحذف ما لا تُعيد — فيُقال ذلك قبل الحذف لا بعده.
     */
    public const VERSION = 3;

    public static function payload(int $bid): array
    {
        $data = [
            'meta' => [
                'app' => 'AbadPOS',
                'version' => self::VERSION,
                'business_id' => $bid,
                'exported_at' => now()->toIso8601String(),
                // ما احتواه هذا الملفّ فعلًا — تقرؤه الاستعادةُ ولا تخمّنه
                'tables' => [],
            ],
            'business' => Business::find($bid)?->only(Business::BACKUP_FIELDS),
        ];

        foreach (TenantTables::all() as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            $data[$table] = self::rows($table, $bid);
            $data['meta']['tables'][] = $table;
        }

        return $data;
    }

    /** سطورُ جدولٍ لهذا المتجر، منزوعةَ ما لا يخرج في ملفّ */
    private static function rows(string $table, int $bid): array
    {
        $strip = TenantTables::SECRETS[$table] ?? [];

        return TenantTables::scope($table, $bid)->orderBy('id')->get()
            ->map(function ($row) use ($strip) {
                $row = (array) $row;

                foreach ($strip as $column) {
                    unset($row[$column]);
                }

                return $row;
            })->all();
    }

    public static function json(int $bid): string
    {
        return json_encode(self::payload($bid), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }

    public static function filename(int $bid): string
    {
        return 'abadpos-backup-'.$bid.'-'.now()->format('Y-m-d-His').'.json';
    }
}
