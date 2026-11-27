<?php

namespace App\Support;

use App\Jobs\RequestWhatsAppCoexistenceSync;
use App\Models\Business;
use App\Models\WhatsAppChat;
use App\Models\WhatsAppChatAttachment;
use App\Models\WhatsAppChatMessage;
use App\Models\WhatsAppConnection;
use App\Models\WhatsAppContact;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * ═══ رقمٌ يبقى في تطبيق واتساب للأعمال ويصل أبعاد معًا (التعايش) ═══
 *
 * التاجرُ يردّ من هاتفه كما اعتاد، وأبعاد يستقبل ويُرسل من الرقم نفسه. ولكي يرى الصندوقُ
 * المحادثةَ كاملةً وتعرف الأتمتةُ متى ردّ التاجر بنفسه، تُرسل Meta على تطبيق أبعاد أربعةَ
 * أنواعٍ من الإشعارات (وثيقة «Onboard WhatsApp Business app users»):
 *
 *   · `smb_message_echoes` — ما يرسله التاجرُ من التطبيق بعد الربط.
 *   · `history` — محادثاتُه السابقة (حتى ١٨٠ يومًا) على دفعاتٍ بنسبة تقدّم، إن وافق على مشاركتها.
 *   · `smb_app_state_sync` — جهاتُ اتّصاله: إضافةٌ وتعديلٌ وحذف.
 *   · `account_update` — انفصالُ الرقم عن أبعاد (`PARTNER_REMOVED`، `ACCOUNT_OFFBOARDED`).
 *
 * والجهاتُ والتاريخُ لا يصلان حتى يُطلبا (`smb_app_data`): **مرّةً واحدة لكلٍّ منهما، خلال
 * ٢٤ ساعة من الربط** — وإلّا لزم أن يُفصل الرقمُ ويُربط من أوّله. فيُطلبان فور الربط من
 * الطابور (`RequestWhatsAppCoexistenceSync`)، ويُحجز كلُّ طلبٍ قبل ندائه فلا يخرج مرّتين.
 *
 * ═══ وما لا يفعله التاريخُ المستورد ═══
 *
 * لا يفتح نافذةَ الردّ (Meta: النافذةُ تفتحها رسالةُ زبونٍ **بعد** الربط)، ولا يُحسب غيرَ
 * مقروء، ولا يُطلق ردًّا آليًّا ولا طلبًا. هو سجلٌّ يُقرأ.
 *
 * وحالُ المزامنة في `metadata['coexistence_sync']` على الوصلة، يُكتب تحت قفل صفّها.
 */
final class WhatsAppCoexistence
{
    public const ECHOES = 'smb_message_echoes';

    public const HISTORY = 'history';

    public const CONTACTS = 'smb_app_state_sync';

    public const ACCOUNT_UPDATE = 'account_update';

    /** ‏‏حقولُ الإشعارات التي يشترك فيها تطبيقُ أبعاد عند Meta لهذه الميزة */
    public const FIELDS = [self::ACCOUNT_UPDATE, self::HISTORY, self::CONTACTS, self::ECHOES];

    /** ‏‏ما يُطلب من Meta بعد الربط — الجهاتُ أوّلًا ثمّ التاريخ، كما في الوثيقة */
    public const SYNC_ORDER = [self::CONTACTS, self::HISTORY];

    public const SYNC_HOURS = 24;

    /** ‏‏«History sync is turned off by the business from the WhatsApp Business App» */
    public const HISTORY_DECLINED = 2593109;

    /** ‏‏اسمُ المُرسِل على ما كتبه التاجرُ من هاتفه — كما يُكتب اسمُ الموظّف على ردّه */
    public const PHONE_SENDER = 'تطبيق واتساب للأعمال';

    public const PARTNER_REMOVED = 'PARTNER_REMOVED';

    public const OFFBOARDED = 'ACCOUNT_OFFBOARDED';

    public const RECONNECTED = 'ACCOUNT_RECONNECTED';

    /** ‏‏`last_error_code` لوصلةٍ انفصلت من جهة التطبيق */
    public const UNLINKED = 'coexistence_unlinked';

    /** ‏‏نتيجةُ طلب مزامنة */
    public const DONE = 'done';

    public const CALLED = 'called';

    public const BUSY = 'busy';

    public const FAILED = 'failed';

    public const EXPIRED = 'expired';

    private const KEY = 'coexistence_sync';

    /** ‏‏حجزٌ أقدمُ من هذا يُعدّ ميتًا (عاملٌ سقط في منتصف النداء) */
    private const CLAIM_MINUTES = 10;

    /** ‏‏لماذا انفصل — بلغة التاجر (`disconnection_info.reason`) */
    private const REASONS = [
        'PRIMARY_INACTIVITY' => 'لم يُفتح تطبيق واتساب للأعمال على الهاتف نحو 14 يومًا',
        'COMPANION_INACTIVITY' => 'لم يُستعمل جهازٌ مرتبطٌ نحو 30 يومًا',
        'ACCOUNT_DISCONNECTED' => 'فُصل الحساب من التطبيق أو حُذف',
        'BUSINESS_DOWNGRADE' => 'سُجّل الرقم في واتساب العاديّ بدل واتساب للأعمال',
        'CHANGE_NUMBER' => 'تغيّر رقم الحساب في التطبيق',
        'USER_RE_REGISTERED' => 'أُعيد تسجيل الرقم على جهازٍ آخر',
    ];

    /** ‏‏أوصلةُ متجرٍ على رقمٍ في تطبيق الأعمال؟ — وحدها تُقرأ لها هذه الإشعارات */
    public static function applies(?WhatsAppConnection $c): bool
    {
        return $c !== null && $c->coexistence && $c->owner_type === WhatsAppMode::OWNER_BUSINESS && filled($c->business_id)
            && ! WhatsAppOwnApp::is($c);
    }

    /**
     * ‏‏بعد ربطٍ ناجح: حالٌ جديدة، وطلبُ الجهات ثمّ التاريخ من الطابور.
     * وربطٌ جديد بعد انفصالٍ يبدأ من أوّله — كذلك تقول Meta.
     */
    public static function start(WhatsAppConnection $c): void
    {
        if (! self::applies($c)) {
            return;
        }

        self::patch($c, fn () => ['started_at' => now()->toIso8601String()]);

        RequestWhatsAppCoexistenceSync::dispatch((int) $c->id)->onQueue((string) config('whatsapp.queue', 'whatsapp'));
    }

    /**
     * ‏‏يطلب نوعًا واحدًا من Meta — مرّةً واحدة.
     *
     * يحجز قبل النداء تحت قفل الصفّ: عاملان معًا لا يطلبان مرّتين. وما قُبل لا يُطلب ثانيةً،
     * وما فشل يُفكّ حجزُه ليُعاد، وما تجاوز ٢٤ ساعة من الربط لا يُطلب أبدًا (Meta ترفضه).
     */
    public static function request(WhatsAppConnection $c, string $type): string
    {
        if (! in_array($type, self::SYNC_ORDER, true) || ! self::applies($c)) {
            return self::DONE;
        }

        $go = self::DONE;
        self::patch($c, function (array $s) use ($type, &$go) {
            $t = (array) ($s[$type] ?? []);

            if (filled($t['request_id'] ?? null)) {
                $go = self::DONE;

                return $s;
            }

            $started = isset($s['started_at']) ? Carbon::parse($s['started_at']) : null;
            if ($started === null || $started->copy()->addHours(self::SYNC_HOURS)->isPast()) {
                $s['expired_at'] ??= now()->toIso8601String();
                $go = self::EXPIRED;

                return $s;
            }

            if (filled($t['claimed_at'] ?? null) && Carbon::parse($t['claimed_at'])->gt(now()->subMinutes(self::CLAIM_MINUTES))) {
                $go = self::BUSY;

                return $s;
            }

            $t['claimed_at'] = now()->toIso8601String();
            $s[$type] = $t;
            $go = self::CALLED;

            return $s;
        });

        if ($go !== self::CALLED) {
            return $go;
        }

        $r = WhatsAppEmbeddedSignup::smbAppData((string) $c->phone_number_id, (string) $c->access_token, $type);

        self::patch($c, function (array $s) use ($type, $r) {
            $t = (array) ($s[$type] ?? []);
            $t['claimed_at'] = null;
            if ($r['ok']) {
                $t['request_id'] = $r['request_id'];
                $t['requested_at'] = now()->toIso8601String();
                $t['error'] = null;
            } else {
                $t['error'] = mb_substr(trim(($r['code'] ? $r['code'].' · ' : '').(string) $r['message']), 0, 300);
                $t['error_at'] = now()->toIso8601String();
            }
            $s[$type] = $t;

            return $s;
        });

        return $r['ok'] ? self::DONE : self::FAILED;
    }

    /* ───────────────────────── الإشعارات ───────────────────────── */

    /** ‏‏ما أرسله التاجرُ من التطبيق بعد الربط — يُكتب في محادثته بالصندوق، مرّةً لكلّ رسالة */
    public static function echoes(WhatsAppConnection $c, array $value): int
    {
        if (($business = self::inboxFor($c)) === null) {
            return 0;
        }

        $written = 0;
        foreach ((array) ($value['message_echoes'] ?? []) as $echo) {
            $echo = (array) $echo;
            $to = WhatsAppPhone::normalize($echo['to'] ?? null);
            if ($to === null) {
                continue;
            }
            $written += (int) self::write($business, $c, $to, $echo, WhatsAppChatMessage::OUT, WhatsAppStatus::SENT, null);
        }

        return $written;
    }

    /**
     * ‏‏المحادثاتُ السابقة — دفعاتٌ بنسبة تقدّم، أو رفضُ التاجر مشاركتها.
     *
     * والاتّجاهُ من `from`: رقمُ النشاط = أرسلها التاجر، وغيرُه = أرسلها الزبون.
     * ودفعةُ الوسائط اللاحقة (`messages` بلا `history`) تُلحق الملفَّ برسالته إن وُجدت.
     */
    public static function history(WhatsAppConnection $c, array $value): int
    {
        $own = WhatsAppPhone::normalize($value['metadata']['display_phone_number'] ?? $c->display_phone_number);
        $business = self::inboxFor($c);
        $written = 0;

        foreach ((array) ($value['history'] ?? []) as $block) {
            $block = (array) $block;

            foreach ((array) ($block['errors'] ?? []) as $error) {
                if ((int) ($error['code'] ?? 0) === self::HISTORY_DECLINED) {
                    self::patch($c, function (array $s) {
                        $s['history_declined_at'] ??= now()->toIso8601String();

                        return $s;
                    });
                }
            }

            $meta = (array) ($block['metadata'] ?? []);
            if (isset($meta['progress'])) {
                $progress = max(0, min(100, (int) $meta['progress']));
                self::patch($c, function (array $s) use ($progress, $meta) {
                    $s['history_progress'] = max((int) ($s['history_progress'] ?? 0), $progress);
                    $s['history_phase'] = (int) ($meta['phase'] ?? 0);
                    $s['history_at'] = now()->toIso8601String();

                    return $s;
                });
            }

            if ($business === null) {
                continue;
            }

            foreach ((array) ($block['threads'] ?? []) as $thread) {
                $thread = (array) $thread;
                $phone = WhatsAppPhone::normalize($thread['id'] ?? ($thread['context']['wa_id'] ?? null));
                if ($phone === null) {
                    continue;
                }

                foreach ((array) ($thread['messages'] ?? []) as $m) {
                    $m = (array) $m;
                    $mine = $own !== null && WhatsAppPhone::normalize($m['from'] ?? null) === $own;
                    $written += (int) self::write(
                        $business, $c, $phone, $m,
                        $mine ? WhatsAppChatMessage::OUT : WhatsAppChatMessage::IN,
                        $mine ? self::sentStatus((string) ($m['history_context']['status'] ?? '')) : WhatsAppChatMessage::HISTORY,
                        null,
                    );
                }
            }
        }

        // ‏‏ودفعةُ الوسائط: ملفٌّ لرسالةٍ وصلت نصًّا بديلًا قبله
        if ($business !== null) {
            foreach ((array) ($value['messages'] ?? []) as $m) {
                $written += (int) self::attach($business, $c, (array) $m);
            }
        }

        return $written;
    }

    /**
     * ‏‏جهاتُ اتّصال التاجر — إضافةٌ أو تعديلٌ أو حذف. لا يُنشأ منها عميلٌ في المتجر.
     * وتُحفظ ولو كان الصندوقُ مغلقًا: Meta لا تُرسلها ثانيةً.
     */
    public static function contacts(WhatsAppConnection $c, array $value): int
    {
        if (! self::applies($c)) {
            return 0;
        }

        $changed = 0;
        foreach ((array) ($value['state_sync'] ?? []) as $item) {
            $item = (array) $item;
            $contact = (array) ($item['contact'] ?? []);
            $user = trim((string) ($contact['user_id'] ?? ''));
            if (($item['type'] ?? '') !== 'contact' || $user === '') {
                continue;
            }

            $key = ['business_id' => (int) $c->business_id, 'user_id' => mb_substr($user, 0, 100)];

            if (($item['action'] ?? '') === 'remove') {
                $changed += WhatsAppContact::where($key)->whereNull('removed_at')->update(['removed_at' => now(), 'updated_at' => now()]);

                continue;
            }

            Contention::retry(fn () => WhatsAppContact::updateOrCreate($key, [
                'whatsapp_connection_id' => $c->id,
                'phone' => WhatsAppPhone::normalize($contact['phone_number'] ?? null),
                'full_name' => filled($contact['full_name'] ?? null) ? mb_substr((string) $contact['full_name'], 0, 150) : null,
                'first_name' => filled($contact['first_name'] ?? null) ? mb_substr((string) $contact['first_name'], 0, 100) : null,
                'removed_at' => null,
                'synced_at' => now(),
            ]));
            $changed++;
        }

        return $changed;
    }

    /**
     * ‏‏انفصالُ الرقم عن أبعاد من جهة التطبيق — يقف الإرسالُ منه ويُقال للتاجر لماذا.
     *
     * الإشعارُ على حساب واتساب (`entry.id`) بلا رقم، فيُطابَق بالحساب — ووصلاتُ تطبيقات التجّار
     * لا تُمسّ (أبوابُها غيرُ هذا الباب).
     */
    public static function account(string $wabaId, array $value): int
    {
        $event = (string) ($value['event'] ?? '');
        $waba = (string) ($value['waba_info']['waba_id'] ?? $wabaId);
        if ($waba === '' || ! in_array($event, [self::PARTNER_REMOVED, self::OFFBOARDED, self::RECONNECTED], true)) {
            return 0;
        }

        $touched = 0;
        $links = WhatsAppConnection::where('waba_id', $waba)->where('owner_type', WhatsAppMode::OWNER_BUSINESS)
            ->where('status', '!=', WhatsAppConnection::INACTIVE)->get();

        foreach ($links as $c) {
            if (WhatsAppOwnApp::is($c)) {
                continue;
            }

            $reason = (string) ($value['disconnection_info']['reason'] ?? '');
            self::patch($c, function (array $s) use ($event, $reason, $value) {
                $s['account_event'] = [
                    'event' => $event,
                    'reason' => $reason !== '' ? mb_substr($reason, 0, 60) : null,
                    'by' => filled($value['disconnection_info']['initiated_by'] ?? null) ? mb_substr((string) $value['disconnection_info']['initiated_by'], 0, 20) : null,
                    'at' => now()->toIso8601String(),
                ];

                return $s;
            });

            if ($event !== self::RECONNECTED) {
                WhatsAppConnection::whereKey($c->id)->update([
                    'status' => WhatsAppConnection::ERROR,
                    'last_error_code' => self::UNLINKED,
                    'last_error_message' => mb_substr($event.($reason !== '' ? ' · '.$reason : ''), 0, 500),
                    'last_error_at' => now(),
                ]);
                Activity::log('settings', 'واتساب — انفصل رقم المتجر عن أبعاد من جهة التطبيق ('.$event.($reason !== '' ? ' · '.$reason : '').')', [
                    'business_id' => (int) $c->business_id,
                ]);
            }
            $touched++;
        }

        return $touched;
    }

    /** ‏‏أردّ التاجرُ بنفسه من هاتفه على هذا الزبون خلال الساعات الماضية؟ — فلا يُردّ آليًّا فوقه */
    public static function repliedFromPhone(int $businessId, ?string $phone, int $hours): bool
    {
        $phone = WhatsAppPhone::normalize($phone);
        if ($phone === null || $hours <= 0) {
            return false;
        }

        return WhatsAppChatMessage::query()
            ->join('whatsapp_chats as c', 'c.id', '=', 'whatsapp_chat_messages.chat_id')
            ->where('whatsapp_chat_messages.business_id', $businessId)
            ->where('c.customer_phone', $phone)
            ->where('whatsapp_chat_messages.direction', WhatsAppChatMessage::OUT)
            ->where('whatsapp_chat_messages.sender_name', self::PHONE_SENDER)
            ->where('whatsapp_chat_messages.sent_at', '>', now()->subHours($hours))
            ->exists();
    }

    /**
     * ‏‏حالُ المزامنة كما تعرضها بطاقةُ الربط — و`null` لرقمٍ ليس في تطبيق الأعمال.
     *
     * @return ?array{started_at: ?string, deadline: ?string, contacts: array{state: string, count: int, error: ?string}, history: array{state: string, progress: ?int, error: ?string}, unlinked: ?array{event: string, reason: ?string, at: string}}
     */
    public static function view(?WhatsAppConnection $c): ?array
    {
        if (! self::applies($c)) {
            return null;
        }

        $s = self::state($c);
        $started = isset($s['started_at']) ? Carbon::parse($s['started_at']) : null;
        $expired = $started !== null && $started->copy()->addHours(self::SYNC_HOURS)->isPast();
        $step = function (string $type) use ($s, $expired): array {
            $t = (array) ($s[$type] ?? []);

            return match (true) {
                filled($t['request_id'] ?? null) => ['state' => 'requested', 'error' => null],
                $expired => ['state' => 'expired', 'error' => $t['error'] ?? null],
                filled($t['error'] ?? null) => ['state' => 'failed', 'error' => $t['error']],
                default => ['state' => 'pending', 'error' => null],
            };
        };

        $contacts = $step(self::CONTACTS);
        $history = $step(self::HISTORY);
        $progress = isset($s['history_progress']) ? (int) $s['history_progress'] : null;
        if (filled($s['history_declined_at'] ?? null)) {
            $history['state'] = 'declined';
        } elseif ($progress !== null) {
            $history['state'] = $progress >= 100 ? 'done' : 'importing';
        }

        $event = (array) ($s['account_event'] ?? []);
        $unlinked = in_array($event['event'] ?? null, [self::PARTNER_REMOVED, self::OFFBOARDED], true) && $c->last_error_code === self::UNLINKED
            ? ['event' => (string) $event['event'], 'reason' => self::REASONS[$event['reason'] ?? ''] ?? null, 'at' => (string) $event['at']]
            : null;

        return [
            'started_at' => $started?->toIso8601String(),
            'deadline' => $started?->copy()->addHours(self::SYNC_HOURS)->toIso8601String(),
            'contacts' => $contacts + ['count' => WhatsAppContact::where('business_id', $c->business_id)->whereNull('removed_at')->count()],
            'history' => $history + ['progress' => $progress],
            'unlinked' => $unlinked,
        ];
    }

    /* ───────────────────────── داخليّ ───────────────────────── */

    /** ‏‏المتجرُ إن كان صندوقُه مفتوحًا وهذه وصلتُه الحاليّة — كما في `WhatsAppInbox::receiveOwn` */
    private static function inboxFor(WhatsAppConnection $c): ?Business
    {
        if (! self::applies($c) || $c->status === WhatsAppConnection::INACTIVE || blank($c->phone_number_id)) {
            return null;
        }

        $business = Business::find($c->business_id);

        return WhatsAppInbox::available($business) && WhatsAppConnections::forBusiness((int) $c->business_id)?->id === $c->id
            ? $business
            : null;
    }

    /**
     * ‏‏يكتب رسالةً من الهاتف أو من التاريخ في محادثتها — مرّةً واحدة بمعرّفها، وبوقتها الأصليّ.
     *
     * ولا يمسّ نافذةَ الردّ (`last_inbound_at`)، وملخّصُ المحادثة لا يرجع إلى الوراء: رسالةٌ
     * أقدمُ ممّا فيه تُكتب ولا تُعرض أخيرةً.
     */
    private static function write(Business $business, WhatsAppConnection $c, string $phone, array $m, string $direction, string $status, ?string $profileName): bool
    {
        $wamid = trim((string) ($m['id'] ?? ''));
        if ($wamid === '' || WhatsAppChatMessage::where('wamid', $wamid)->exists()) {
            return false;
        }

        [$body, $mediaType, $file] = CrmWhatsApp::inbound($c, $m);

        $at = isset($m['timestamp']) ? now()->setTimestamp((int) $m['timestamp']) : now();
        $at = $at->isFuture() ? now() : $at;

        $chat = Contention::retry(fn () => WhatsAppChat::firstOrCreate(
            ['business_id' => $business->id, 'phone_number_id' => (string) $c->phone_number_id, 'customer_phone' => $phone],
            ['whatsapp_connection_id' => $c->id, 'source_mode' => WhatsAppMode::BUSINESS_OWN],
        ));

        $row = Contention::attempt(function () use ($business, $c, $chat, $wamid, $body, $mediaType, $file, $at, $direction, $status, $profileName) {
            $row = WhatsAppChatMessage::create([
                'business_id' => $business->id,
                'chat_id' => $chat->id,
                'direction' => $direction,
                'body' => $body,
                'media_type' => $mediaType,
                'wamid' => $wamid,
                'whatsapp_connection_id' => $c->id,
                'phone_number_id' => (string) $c->phone_number_id,
                'sender_name' => $direction === WhatsAppChatMessage::OUT ? self::PHONE_SENDER : null,
                'status' => $status,
                'received_at' => $direction === WhatsAppChatMessage::IN ? $at : null,
                'sent_at' => $direction === WhatsAppChatMessage::OUT ? $at : null,
                // ‏‏والصندوقُ يرتّب بالوقت: رسالةُ الأمس المستوردة اليوم تقع في الأمس
                'created_at' => $at,
            ]);

            if ($file !== null) {
                WhatsAppInbox::keep($row, $file['contents'], $file['mime'], $file['name']);
            }

            $newer = $chat->last_message_at === null || $chat->last_message_at->lte($at);
            $chat->forceFill(array_filter([
                'whatsapp_connection_id' => $c->id,
                'profile_name' => blank($chat->profile_name) && filled($profileName) ? mb_substr((string) $profileName, 0, 150) : null,
                'last_message_at' => $newer ? $at : null,
                'last_preview' => $newer ? WhatsAppInbox::preview($body) : null,
                'last_direction' => $newer ? $direction : null,
                'customer_id' => $chat->customer_id ?? WhatsAppInbox::customerFor($business->id, (string) $chat->customer_phone),
            ], fn ($v) => $v !== null))->save();

            return $row;
        });

        return $row !== null;
    }

    /** ‏‏ملفُّ رسالةٍ من التاريخ وصل بعدها — يُلحق بها مرّةً واحدة */
    private static function attach(Business $business, WhatsAppConnection $c, array $m): bool
    {
        $wamid = trim((string) ($m['id'] ?? ''));
        $type = (string) ($m['type'] ?? '');
        $part = (array) ($m[$type] ?? []);
        if ($wamid === '' || blank($part['id'] ?? null)) {
            return false;
        }

        $row = WhatsAppChatMessage::where('business_id', $business->id)->where('wamid', $wamid)->first();
        if ($row === null || WhatsAppChatAttachment::where('message_id', $row->id)->exists()) {
            return false;
        }

        [, , $file] = CrmWhatsApp::inbound($c, $m);
        if ($file === null) {
            return false;
        }

        WhatsAppInbox::keep($row, $file['contents'], $file['mime'], $file['name']);

        return true;
    }

    private static function sentStatus(string $status): string
    {
        return match ($status) {
            'read', 'played' => WhatsAppStatus::READ,
            'delivered' => WhatsAppStatus::DELIVERED,
            'error' => WhatsAppStatus::FAILED,
            default => WhatsAppStatus::SENT,
        };
    }

    private static function state(WhatsAppConnection $c): array
    {
        return (array) (((array) $c->metadata)[self::KEY] ?? []);
    }

    /** ‏‏يقرأ الحالَ ويكتبها تحت قفل صفّ الوصلة — إشعاراتٌ تصل معًا لا تمحو إحداها الأخرى */
    private static function patch(WhatsAppConnection $c, callable $fn): array
    {
        return DB::transaction(function () use ($c, $fn) {
            $row = WhatsAppConnection::whereKey($c->id)->lockForUpdate()->first();
            if ($row === null) {
                return [];
            }

            $meta = (array) $row->metadata;
            $state = (array) $fn((array) ($meta[self::KEY] ?? []));
            $meta[self::KEY] = $state;
            $row->forceFill(['metadata' => $meta])->save();
            $c->setAttribute('metadata', $meta);
            $c->syncOriginalAttribute('metadata');

            return $state;
        });
    }
}
