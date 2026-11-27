<?php

namespace App\Support;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * ‏‏نداءاتُ إنستقرام كلُّها من هنا — الربطُ (`InstagramLink`) والإرسالُ (`InstagramMessenger`).
 *
 * والجوابُ شكلٌ واحد لا يُرمى منه استثناء: أخطاءُ تسجيل الدخول تأتي مسطّحةً، وأخطاءُ Graph في
 * `error{}`، وانقطاعُ الشبكة ليس جوابًا أصلًا. و`later`: رفضٌ عابرٌ (حدُّ النداءات) قبل أن تُنفَّذ
 * العمليّة — يُعاد. أمّا انقطاعُ الشبكة فلا يُعاد: قد تكون الرسالةُ خرجت.
 */
class InstagramGraph
{
    /** ‏‏رموزُ Graph لحدّ النداءات — لم تُنفَّذ العمليّة، فتُعاد بعد حين */
    public const RATE_LIMITED = [4, 17, 32, 613, 80002, 80006];

    /** ‏‏رمزٌ أُلغي أو انتهى — يعيد التاجرُ الربط */
    public const TOKEN_ERROR = 190;

    public static function url(string $path, bool $versioned = true): string
    {
        return rtrim((string) config('services.instagram.graph_url'), '/')
            .($versioned ? '/'.config('services.instagram.api_version') : '')
            .$path;
    }

    /** @return array{ok: bool, status: int, body: array, error: string, code: ?int, type: ?string, later: bool} */
    public static function get(string $path, array $query, bool $versioned = true): array
    {
        return self::call(fn () => Http::timeout(self::timeout())->acceptJson()->get(self::url($path, $versioned), $query));
    }

    /** @return array{ok: bool, status: int, body: array, error: string, code: ?int, type: ?string, later: bool} */
    public static function form(string $url, array $form): array
    {
        return self::call(fn () => Http::timeout(self::timeout())->acceptJson()->asForm()->post($url, $form));
    }

    /** ‏‏والرمزُ في الترويسة لا في العنوان — فلا يُكتب في سجلّ وكيلٍ بيننا وبين Meta
     *
     * @return array{ok: bool, status: int, body: array, error: string, code: ?int, type: ?string, later: bool}
     */
    public static function json(string $path, string $token, array $body): array
    {
        return self::call(fn () => Http::timeout(self::timeout())->acceptJson()->withToken($token)->asJson()->post(self::url($path), $body));
    }

    public static function delete(string $path, string $token): void
    {
        self::call(fn () => Http::timeout(self::timeout())->acceptJson()->delete(self::url($path).'?'.http_build_query(['access_token' => $token])));
    }

    private static function timeout(): int
    {
        return (int) config('services.instagram.timeout', 15);
    }

    /** @param callable(): Response $request */
    private static function call(callable $request): array
    {
        try {
            return self::read($request());
        } catch (\Throwable $e) {
            return ['ok' => false, 'status' => 0, 'body' => [], 'error' => 'network_error', 'code' => null, 'type' => null, 'later' => false];
        }
    }

    private static function read(Response $r): array
    {
        $body = (array) ($r->json() ?? []);
        $err = (array) ($body['error'] ?? []);

        $message = (string) ($err['message'] ?? $body['error_message'] ?? '');
        $code = $err['code'] ?? $body['code'] ?? null;
        $type = $err['type'] ?? $body['error_type'] ?? null;
        $code = is_numeric($code) ? (int) $code : null;

        return [
            'ok' => $r->successful() && $err === [] && ! isset($body['error_type']),
            'status' => $r->status(),
            'body' => $body,
            'error' => mb_substr(trim(($code !== null ? $code.' · ' : '').$message) ?: ($r->successful() ? '' : 'HTTP '.$r->status()), 0, 160),
            'code' => $code,
            'type' => is_string($type) ? $type : null,
            'later' => $r->status() === 429 || in_array($code, self::RATE_LIMITED, true),
        ];
    }
}
