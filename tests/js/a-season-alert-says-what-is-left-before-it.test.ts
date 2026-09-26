import { describe, expect, it } from 'vitest';

import { alertHeadline, type SeasonAlert } from '@/Pages/Admin/Seasons/Index';

/** والترجمةُ تردّ المفتاحَ نفسَه مع بدائله — كما يفعل `useTranslate` بلا قاموس */
const t = (key: string, v?: Record<string, string | number>) =>
    Object.entries(v ?? {}).reduce((s, [k, val]) => s.replaceAll(':' + k, String(val)), key);

const alert = (over: Partial<SeasonAlert>): SeasonAlert => ({
    id: 1, seasonId: 1, season: 'رمضان', message: 'راجع المخزون',
    days: 30, startsAt: '2027-03-10', dueAt: null, ...over,
});

/**
 * رأسُ التنبيه — «باقي كذا على موسم كذا».
 *
 * ═══ وما يُحرَس ═══
 *
 * أنّ العدّ إلى **بداية الموسم**، وأنّ الصفرَ والسالبَ لا يُقالان بالعدد:
 * «باقي ٠ يومًا» جملةٌ لا تُقال، و«باقي ‎-٣ يومًا» أسوأ. وهي حالٌ تقع كلَّ
 * موسمٍ: من لم يقرأ تنبيهَه حتّى بدأ الموسمُ يجده بعد بدايته.
 */
describe('رأسُ تنبيه الموسم', () => {
    it('يقول كم بقي على الموسم', () => {
        expect(alertHeadline(alert({ days: 30 }), t)).toBe('باقي 30 يومًا على موسم رمضان');
        expect(alertHeadline(alert({ days: 1, season: 'العيد' }), t)).toBe('باقي 1 يومًا على موسم العيد');
    });

    /** واليومُ نفسُه يُقال بلا عدد */
    it('ويومَ البداية يقول إنّه يبدأ اليوم', () => {
        expect(alertHeadline(alert({ days: 0 }), t)).toBe('موسم رمضان يبدأ اليوم');
    });

    /**
     * والموسمُ الجاري لا يُقال فيه عددٌ بالسالب.
     *
     * من غاب حتّى بدأ موسمُه يجد تنبيهَه واقفًا — فيُقال له إنّه جارٍ، لا
     * «باقي ‎-٣ يومًا».
     */
    it('والجاري يُقال إنّه جارٍ لا بعددٍ سالب', () => {
        const said = alertHeadline(alert({ days: -3 }), t);

        expect(said).toBe('موسم رمضان جارٍ الآن');
        expect(said).not.toContain('-3');
    });
});
