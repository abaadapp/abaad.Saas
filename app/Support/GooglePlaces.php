<?php

namespace App\Support;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * النداء على «Places API (New)» — الموضع الوحيد الذي يعرف شكل ردّ Google.
 *
 * وهذا هو البابُ الذي تُسحب منه التقييمات فعلًا، لا «Business Profile API»:
 * ذاك يحتاج موافقةً مسبقة على النشاط نفسه، وهذا يحتاج مفتاحًا من مشروعٍ في
 * Google Cloud وحسب — فيقرأ الملفَّ العامّ لأيّ مكانٍ بمعرّفه، وفيه تقييماته.
 *
 * وحدُّه خمسةٌ: Google لا تُعيد أكثر من خمسة تقييماتٍ لمكانٍ مهما بلغ عددُها
 * الحقيقيّ، وتختارها هي. فالعددُ والمعدّل يُعرضان كاملَين من `userRatingCount`
 * و`rating`، والنصوصُ خمسةٌ لا غير — ويُقال ذلك في الشاشة صراحةً، لئلّا يظنّ
 * التاجر أنّ تقييماته ضاعت.
 *
 * ولا يُكتب المفتاح في سجلٍّ ولا في رسالة خطأ: ما يُبلَّغ عنه حالةُ الردّ
 * ونصُّ Google، ولا شيء من الاعتماد.
 */
class GooglePlaces
{
    /** ما نطلبه من الحقول — والسعرُ يُحسب بها، فلا يُطلب ما لا يُعرض */
    private const FIELDS = 'id,displayName,rating,userRatingCount,googleMapsUri,reviews';

    private const URL = 'https://places.googleapis.com/v1/places/';

    /**
     * تفاصيلُ مكانٍ بمعرّفه.
     *
     * @return array{ok:bool, error:?string, place:?array}
     */
    public static function details(string $placeId, string $apiKey, string $language = 'ar'): array
    {
        try {
            $response = Http::withHeaders([
                'X-Goog-Api-Key' => $apiKey,
                'X-Goog-FieldMask' => self::FIELDS,
            ])->timeout(12)->acceptJson()->get(self::URL.$placeId, [
                'languageCode' => $language,
            ]);
        } catch (\Throwable $e) {
            /*
             * انقطاعُ شبكةٍ أو مهلة — لا يُكتب نصُّ الاستثناء للتاجر: يحمل
             * العنوان كاملًا وفيه المفتاح في بعض الصيغ.
             */
            return self::fail(__('تعذّر الوصول إلى Google. حاول بعد قليل.'));
        }

        if ($response->successful()) {
            return ['ok' => true, 'error' => null, 'place' => self::shape($response->json() ?? [])];
        }

        return self::fail(self::message($response->status(), (string) ($response->json('error.message') ?? '')));
    }

    /**
     * رسالةٌ تقول ما يُفعل، لا ما حدث.
     *
     * «403» وحدها لا تُصلح شيئًا: أشيع أسبابها ثلاثةٌ يعرفها صاحبُ المشروع في
     * Google Cloud — واجهةٌ غير مُفعَّلة، ومفتاحٌ مقيَّد بنطاقٍ لا يشمل خادمنا،
     * وفوترةٌ غير مربوطة. فتُذكر بأسمائها.
     */
    private static function message(int $status, string $detail): string
    {
        $said = trim($detail) === '' ? '' : ' ('.Str::limit($detail, 160).')';

        return match (true) {
            $status === 400 => __('رفضت Google معرّف المكان — تأكّد أنّه معرّفُ محلّك.').$said,
            $status === 401, $status === 403 => __('رفضت Google المفتاح. تأكّد من تفعيل «Places API (New)» في مشروعك، ومن أنّ قيود المفتاح تسمح لخادمنا، ومن ربط الفوترة.').$said,
            $status === 404 => __('لم تجد Google مكانًا بهذا المعرّف.'),
            $status === 429 => __('تجاوزتَ حصّة Google لهذه الفترة. حاول لاحقًا.'),
            $status >= 500 => __('عطلٌ عند Google. حاول بعد قليل.'),
            default => __('لم تُتِمّ Google الطلب (:code).', ['code' => $status]).$said,
        };
    }

    private static function fail(string $error): array
    {
        return ['ok' => false, 'error' => $error, 'place' => null];
    }

    /**
     * الردُّ كما نعرضه — لا كما ترسله Google.
     *
     * وشكلٌ ثابتٌ هنا يعني أنّ تغييرَ Google لأسماء حقولها يُصلَح في دالّةٍ
     * واحدة، لا في شاشةٍ وقالبٍ واختبار.
     */
    private static function shape(array $body): array
    {
        $reviews = [];

        foreach ((array) ($body['reviews'] ?? []) as $i => $review) {
            $text = trim((string) ($review['text']['text'] ?? $review['originalText']['text'] ?? ''));

            $reviews[] = [
                // مفتاحٌ ثابتٌ للصفّ: اسمُ المورد من Google، أو ترتيبُه إن غاب
                'id' => (string) ($review['name'] ?? 'r'.$i),
                'author' => (string) ($review['authorAttribution']['displayName'] ?? __('زائر')),
                'photo' => $review['authorAttribution']['photoUri'] ?? null,
                'rating' => (int) ($review['rating'] ?? 0),
                'text' => $text,
                'when' => (string) ($review['relativePublishTimeDescription'] ?? ''),
                'at' => (string) ($review['publishTime'] ?? ''),
            ];
        }

        return [
            'name' => (string) ($body['displayName']['text'] ?? ''),
            // المعدّل عشريٌّ بمنزلة — و`null` لمكانٍ لا تقييمَ له، لا صفرٌ يُقرأ سوءًا
            'rating' => isset($body['rating']) ? round((float) $body['rating'], 1) : null,
            'count' => (int) ($body['userRatingCount'] ?? 0),
            'maps_url' => $body['googleMapsUri'] ?? null,
            'reviews' => $reviews,
        ];
    }
}
