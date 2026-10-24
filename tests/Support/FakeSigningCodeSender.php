<?php

namespace Tests\Support;

use App\Models\PlatformDocument;
use App\Support\SigningCodeSender;
use RuntimeException;

/**
 * مزوّدُ رموز التحقّق في الاختبارات وحدها — يحفظ ما «أرسله» في الذاكرة.
 *
 * لا مكانَ له في التطبيق: التطبيقُ بلا مزوّدٍ مربوط يُغلق التوقيع.
 */
class FakeSigningCodeSender implements SigningCodeSender
{
    /** @var list<array{phone:string, code:string, document:int}> */
    public array $sent = [];

    public bool $down = false;

    public function send(string $phone, string $code, PlatformDocument $document): void
    {
        if ($this->down) {
            throw new RuntimeException('gateway down for '.$phone.' code '.$code);
        }

        $this->sent[] = ['phone' => $phone, 'code' => $code, 'document' => $document->id];
    }

    /** آخرُ رمزٍ وصل هاتفَ هذا المستند */
    public function last(PlatformDocument $document): string
    {
        $mine = array_values(array_filter($this->sent, fn ($m) => $m['document'] === $document->id));

        return $mine === [] ? '' : $mine[count($mine) - 1]['code'];
    }
}
