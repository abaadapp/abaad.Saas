<?php

namespace App\Support;

use App\Models\Business;
use App\Models\WhatsAppConnection;

/**
 * أيّ وصلةٍ تُرسل هذه الرسالة — وما الذي يُعرض منها للناس.
 *
 * وصلةٌ واحدة لأبعاد تخدم كلّ المتاجر، ووصلةٌ لكلّ محلٍّ ربط رقمه. والقرار
 * هنا لا في المتحكّمات: متحكّمٌ يختار الوصلة بنفسه هو متحكّمٌ يستطيع أن
 * يختار وصلة متجرٍ آخر.
 */
class WhatsAppConnections
{
    /** وصلة أبعاد المشتركة — واحدةٌ نشطة لا غير */
    public static function platform(): ?WhatsAppConnection
    {
        return WhatsAppConnection::query()->platform()
            ->where('status', WhatsAppConnection::ACTIVE)
            ->orderByDesc('id')->first();
    }

    /** وصلة رقم المحلّ — أيًّا كان حالها، فالحال يُقرأ لا يُخفى */
    public static function forBusiness(int $businessId): ?WhatsAppConnection
    {
        return WhatsAppConnection::query()->forBusiness($businessId)
            ->orderByDesc('id')->first();
    }

    /**
     * الوصلة التي يُرسل بها هذا المتجر في وضعه الحاليّ.
     *
     * ولا احتياطَ تلقائيّ: متجرٌ اختار رقمه ثمّ انقطعت وصلتُه **لا** تخرج
     * رسائله من رقم أبعاد. لأنّ الزبون يقرأ الرقم فيظنّه رقم المحلّ ويردّ
     * عليه، ولأنّ التاجر يظنّ رسائله تخرج من رقمه وهي تُخصم من حصّةٍ مشتركة.
     * فتُمتنع الرسالة ويُقيَّد سببها، ويُصلح التاجر وصلته.
     */
    public static function resolve(Business $business): ?WhatsAppConnection
    {
        $connection = WhatsAppFeature::effectiveMode($business) === WhatsAppMode::BUSINESS_OWN
            ? self::forBusiness($business->id)
            : self::platform();

        return $connection && $connection->isUsable() ? $connection : null;
    }

    /**
     * ما يُعرض من الوصلة في الشاشات — بلا رمزٍ ولا سرّ.
     *
     * والدالّة هي الباب: لو مُرّر النموذج نفسه إلى Inertia لَخرج الرمز إلى
     * المتصفّح مهما كان `$hidden` — فـ`$hidden` يحمي `toArray` ولا يحمي من
     * يكتب `$connection->access_token` بيده.
     *
     * ومعرّف حساب الأعمال (WABA) لا يُعرض لصاحب المحلّ في الوضع المشترك:
     * هو معرّف حساب أبعاد لا حسابه، ولا يفعل به شيئًا.
     */
    public static function publicView(?WhatsAppConnection $c, bool $withIds = false): ?array
    {
        if (! $c) {
            return null;
        }

        $out = [
            'status' => $c->status,
            'usable' => $c->isUsable(),
            'display_phone_number' => $c->display_phone_number,
            'connected_at' => optional($c->connected_at)->format('Y-m-d H:i'),
            'disconnected_at' => optional($c->disconnected_at)->format('Y-m-d H:i'),
            'expires_at' => optional($c->token_expires_at)->format('Y-m-d'),
        ];

        if ($withIds) {
            $out['waba_id'] = $c->waba_id;
            $out['phone_number_id'] = $c->phone_number_id;
        }

        return $out;
    }
}
