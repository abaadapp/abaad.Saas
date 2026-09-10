<?php

namespace App\Support\Website\Domain;

use App\Models\WebsiteDomain;
use App\Support\Website\Domains;

/**
 * المزوّدُ الافتراضيّ: التاجر يوجّه سجلَّه، ونحن نسأل DNS.
 *
 * ولا شهادةَ يُصدرها: إصدارُها على الخادم (nginx وacme) لا في هذه الطبقة.
 * فهو يقول «التوجيه وصل» ولا يقول «العنوان يفتح بأمان» — والفرقُ بينهما
 * هو ما يبقى على من ينشر.
 *
 * وسجلٌّ واحد لا أربعة: `CNAME` إلى عنوان الوصل. وشرحُ خمسةِ أنواعٍ من
 * السجلّات لصاحب محلٍّ يجعله يغلق الشاشة — والواحد يكفي لكلّ نطاقٍ فرعيّ.
 * أمّا النطاق العاري (`myshop.om` بلا `www`) فأكثرُ المسجّلين لا يقبل فيه
 * `CNAME`، فيُقال له `A` وعنوانُ الخادم — وهو ما يُضبط في الإعدادات.
 */
final class ManualDnsProvider implements CustomDomainProvider
{
    public function name(): string
    {
        return 'manual';
    }

    public function instructions(WebsiteDomain $domain): array
    {
        $host = (string) $domain->normalized_hostname;
        $connect = Domains::connectHost();

        // النطاق العاري: تسميتان فقط (`myshop.om`) — و`@` اسمُه في أكثر اللوحات
        if (substr_count($host, '.') <= 1) {
            $ip = (string) config('storefront.connect_ip');

            return $ip !== '' ? [[
                'type' => 'A',
                'name' => '@',
                'value' => $ip,
            ]] : [[
                'type' => 'CNAME',
                'name' => 'www',
                'value' => $connect,
            ]];
        }

        return [[
            'type' => 'CNAME',
            // أوّلُ تسمية: `www` في `www.myshop.om`
            'name' => explode('.', $host)[0],
            'value' => $connect,
        ]];
    }

    public function register(WebsiteDomain $domain): ?string
    {
        // لا مزوّدَ يُسجَّل عنده: التوجيهُ في يد التاجر
        return null;
    }

    public function check(WebsiteDomain $domain): DomainCheck
    {
        $host = (string) $domain->normalized_hostname;
        $connect = Domains::connectHost();
        $ip = (string) config('storefront.connect_ip');

        $records = $this->lookup($host);

        if ($records === null) {
            return DomainCheck::waiting(__('لم نتمكّن من قراءة سجلّات هذا النطاق الآن — نعيد المحاولة'));
        }

        foreach ($records as $record) {
            $target = mb_strtolower(trim((string) ($record['target'] ?? $record['ip'] ?? ''), '.'));

            if ($target !== '' && ($target === mb_strtolower($connect) || ($ip !== '' && $target === $ip))) {
                return DomainCheck::active();
            }
        }

        if ($records === []) {
            return DomainCheck::waiting(__('لم يظهر السجلّ بعد — قد يستغرق انتشارُه ساعات'));
        }

        return DomainCheck::failed(__('هذا النطاق يشير إلى مكانٍ آخر — راجع السجلّ الذي أضفته'));
    }

    public function forget(WebsiteDomain $domain): void
    {
        // لا شيء عند مزوّد: السجلّ في لوحة التاجر يحذفه بنفسه
    }

    /**
     * سؤالُ DNS — وفشلُ الشبكة يُميَّز عن «لا سجلّ».
     *
     * `dns_get_record` تردّ `false` عند تعذّر السؤال و`[]` حين لا سجلّ.
     * وخلطُهما يجعل انقطاعَ شبكةٍ عندنا يُقرأ خطأً في نطاق التاجر، فيُقال
     * له «راجع سجلّك» وسجلُّه سليم.
     *
     * @return list<array<string, mixed>>|null
     */
    private function lookup(string $host): ?array
    {
        if (! function_exists('dns_get_record')) {
            return null;
        }

        $records = @dns_get_record($host, DNS_CNAME | DNS_A);

        return $records === false ? null : array_values($records);
    }
}
