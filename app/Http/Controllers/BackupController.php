<?php

namespace App\Http\Controllers;

use App\Models\Business;
use App\Models\User;
use App\Support\Activity;
use App\Support\BackupService;
use App\Support\Demo;
use App\Support\TenantTables;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * نسخُ بيانات المتجر واستعادتُها — محصورةً بالمتجر الحاليّ.
 *
 * والاستعادةُ تحلّ محلّ ما في المتجر: تحذف ثمّ تُدرج. فما تحذفه ولا تُدرجه
 * يضيع بلا أن يقول شيءٌ ذلك — ولذلك تُقرأ الجداولُ والترتيبُ من
 * `TenantTables` وحدها، هي نفسُها التي بُنيت بها النسخة.
 */
class BackupController extends Controller
{
    private function bid(): int { return auth()->user()->business_id ?? Demo::bid(); }

    public function download()
    {
        $bid = $this->bid();

        Activity::log('backup', 'صدّر نسخة احتياطية للمتجر');

        return response(BackupService::json($bid), 200, [
            'Content-Type' => 'application/json; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.BackupService::filename($bid).'"',
        ]);
    }

    public function restore(Request $request)
    {
        $request->validate(['backup' => ['required', 'file', 'max:51200']]);

        $data = json_decode(file_get_contents($request->file('backup')->getRealPath()), true);

        if (! is_array($data) || (($data['meta']['app'] ?? null) !== 'AbadPOS')) {
            return back()->with('toast', ['msg' => __('ملف النسخة الاحتياطية غير صالح'), 'type' => 'error']);
        }

        /*
         * وملفٌّ من صيغةٍ قديمة يُردّ قبل الحذف لا بعده.
         *
         * النسخُ حتّى الثانية تحمل سبعةَ عشرَ جدولًا من ستّين. واستعادتُها
         * تحذف مخزونَ الفروع والصناديقَ ودفترَ الأستاذ ثمّ لا تُعيد منها
         * شيئًا — وتقول «تمّت بنجاح». والردُّ هنا يمنع ذلك، ولا يُفقده شيئًا:
         * ملفُّه على جهازه كما هو، ونسخةُ الليلة تُؤخذ بالصيغة الجديدة.
         */
        if ((int) ($data['meta']['version'] ?? 0) < BackupService::VERSION) {
            return back()->with('toast', [
                'msg' => __('هذه نسخةٌ بصيغةٍ قديمة لا تحمل كلّ جداول المتجر — استعادتُها تمحو ما لا تُعيد. خُذ نسخةً جديدة واستعِد منها.'),
                'type' => 'error',
            ]);
        }

        $bid = $this->bid();
        $currentUserId = auth()->id();

        DB::transaction(function () use ($data, $bid, $currentUserId) {
            $this->wipe($bid);
            $this->restoreBusiness($data, $bid);
            $this->insertAll($data, $bid, $currentUserId);
            $this->restoreUsers($data, $bid, $currentUserId);
        });

        Activity::log('restore', 'استعاد بيانات المتجر من نسخة احتياطية');

        return back()->with('toast', ['msg' => __('تمت استعادة البيانات بنجاح'), 'type' => 'success']);
    }

    /**
     * يمحو بيانات المتجر — الأبناءَ قبل الآباء.
     *
     * وبعكس ترتيب الإدراج بالضبط: سطرٌ يُحذف قبل أبنائه يُردّ بمفتاحٍ خارجيّ
     * — و`supplier_invoices` مرتبطٌ بمورّده بـ`restrict`، فحذفُ المورّدين
     * قبله كان **يُسقط الاستعادة كلَّها** بعد أن حذفت المنتجات.
     *
     * والمحوُ نهائيّ لا ناعم: الاستعادةُ تحلّ محلّ ما كان، وصفٌّ مخفيٌّ يظهر
     * في «المحذوفات» بعدها فيستعيده التاجر — فيصير لكلّ منتجٍ نسختان.
     *
     * والموظّفون لا يُمحون: صاحبُ النشاط يفقد حسابه في منتصف الاستعادة.
     */
    private function wipe(int $bid): void
    {
        foreach (array_reverse(TenantTables::all()) as $table) {
            if ($table === 'users' || ! Schema::hasTable($table)) {
                continue;
            }

            TenantTables::scope($table, $bid)->delete();
        }
    }

    /** حقولُ ملفّ المتجر الآمنة — لا الباقةُ ولا الاشتراك */
    private function restoreBusiness(array $data, int $bid): void
    {
        if (empty($data['business']) || ! is_array($data['business'])) {
            return;
        }

        Business::where('id', $bid)->update(
            collect($data['business'])->only(Business::BACKUP_FIELDS)->all()
        );
    }

    /**
     * يُدرج الجداول بترتيبها — والمؤجَّلُ يُكتب في جولةٍ ثانية.
     *
     * `websites.published_version_id` يشير إلى نسخةٍ لم تُدرج بعد، ونسخُها
     * تشير إليه: حلقةٌ لا يحلّها ترتيب. فيُدرَج بلا مؤشّرٍ ثمّ يُعاد إليه.
     */
    private function insertAll(array $data, int $bid, ?int $currentUserId): void
    {
        $deferred = [];

        foreach (TenantTables::all() as $table) {
            if ($table === 'users' || ! Schema::hasTable($table)) {
                continue;
            }

            $rows = $data[$table] ?? [];

            if (! is_array($rows) || $rows === []) {
                continue;
            }

            $columns = array_flip(array_column(Schema::getColumns($table), 'name'));
            $hold = TenantTables::DEFERRED[$table] ?? [];
            $clean = [];

            foreach ($rows as $row) {
                if (! is_array($row)) {
                    continue;
                }

                // عمودٌ في الملفّ لا وجود له في القاعدة اليوم يُسقط الإدراج
                $row = array_intersect_key($row, $columns);

                if (isset($columns['business_id'])) {
                    $row['business_id'] = $bid;
                }

                foreach ($hold as $column) {
                    if (($row[$column] ?? null) !== null) {
                        $deferred[$table][$row['id']][$column] = $row[$column];
                        $row[$column] = null;
                    }
                }

                $clean[] = $row;
            }

            foreach (array_chunk($clean, 500) as $chunk) {
                DB::table($table)->insert($chunk);
            }
        }

        foreach ($deferred as $table => $rows) {
            foreach ($rows as $id => $values) {
                DB::table($table)->where('id', $id)->update($values);
            }
        }
    }

    /**
     * الموظّفون: تحديثٌ وإضافةٌ بلا حذف.
     *
     * ولا تُستورد كلمات المرور — ليست في الملفّ أصلًا. فالحسابُ القائم يبقى
     * بكلمته، والجديدُ يُنشأ بكلمةٍ عشوائية تُلزم صاحبَها بإعادة تعيينها.
     * وحسابُ من ينفّذ الاستعادة لا يُمسّ: لا يُطرد أحدٌ في منتصف عمله.
     */
    private function restoreUsers(array $data, int $bid, ?int $currentUserId): void
    {
        foreach ($data['users'] ?? [] as $row) {
            if (! is_array($row) || empty($row['email'])) {
                continue;
            }

            unset($row['password'], $row['remember_token'], $row['id']);

            $existing = User::where('email', $row['email'])->first();

            if (! $existing) {
                $row['business_id'] = $bid;
                $row['password'] = bcrypt(Str::random(40));
                User::create($row);

                continue;
            }

            if ($existing->id === $currentUserId) {
                continue;
            }

            $existing->update(collect($row)->except(['business_id'])->all());
        }
    }
}
