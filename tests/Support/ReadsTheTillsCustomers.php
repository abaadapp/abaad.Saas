<?php

namespace Tests\Support;

/**
 * زبائنُ الصندوق كما تصل جهازًا لا نسخةَ عنده — من مسار المزامنة لا من الصفحة.
 *
 * الصندوقُ لم يعد يحمل الزبائن في خصائص صفحته (`TillCustomers`): يقرؤهم من
 * الجهاز ويسأل `pos.tillCustomers.sync` عمّا تغيّر. وجهازٌ بلا نسخةٍ يرسل `{}`
 * فيصله كلُّ دلو — فما يرجع هنا هو القائمةُ كلُّها كما يراها الصندوق.
 *
 * ويُستدعى بعد أن تُهيَّأ الجلسةُ للصندوق (الكوكي والدخول) كما كانت تُهيَّأ لفتحه.
 */
trait ReadsTheTillsCustomers
{
    /** @return list<array<string, mixed>> مرتّبةً بالمعرّف، كما يرتّبها الصندوق */
    protected function tillCustomers(): array
    {
        $res = $this->postJson(route('pos.tillCustomers.sync'), ['have' => []]);
        $res->assertOk();

        return collect($res->json('rows'))->flatten(1)->sortBy('id')->values()->all();
    }

    /**
     * صفٌّ بالمعنى الذي يقرؤه الصندوق — الغائبُ فيه فارغ.
     *
     * لا يُرسَل الفارغ (`TillCustomers::rows`): لا سياق، لا لغة، لا نقاط. والصندوقُ
     * يقرأ غيابَها كما كان يقرأ `null` (`toPosCustomer`) — فيُملأ هنا بالمعنى نفسِه.
     *
     * @return array<string, mixed>|null
     */
    protected function asTillReadsIt(?array $row): ?array
    {
        if ($row === null) {
            return null;
        }

        $row['context'] ??= ['alert' => null, 'birthday_in' => null, 'note' => null];
        $row['language'] ??= null;
        $row['points'] ??= 0;

        return $row;
    }
}
