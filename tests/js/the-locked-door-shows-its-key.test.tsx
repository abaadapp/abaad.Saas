import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it } from 'vitest';
import Login from '@/Pages/Auth/Login';
import { pageProps } from './setup';

/**
 * البابُ المقفلُ يُري مفتاحَه.
 *
 * ═══ العطب ═══
 *
 * كاشيرٌ على جهازٍ هو صندوقُ متجرٍ آخر يُخرَج عند الباب — وهذا حارسُ عزلٍ
 * صحيح: لا يبيع أحدٌ على صندوق غيره. لكنّ مخرجَه كان رابطًا رماديًّا أسفل
 * الشاشة: «ليس هذا متجرك؟». فوقعت على موظّفٍ حقيقيّ — دخل ببريده الصحيح
 * وكلمته الصحيحة، فأُخرج مرّةً بعد مرّة، وظنّ صاحبُ المتجر أنّ كلمة المرور
 * هي العطب فأعاد تعيينها مرارًا وليست هي.
 *
 * فصار الزرُّ في الرسالة نفسِها — ولا يُعرض إلّا حين يكون السببُ جهازَ
 * متجرٍ آخر، ويفتح التأكيدَ نفسَه فتبقى وقفةُ «جهازٌ مفعَّل يحتاج مديرًا».
 */

const DEVICE = { business: 'متجرٌ آخر', branch: 'الرئيسي', device: 'لاب', activated: true };

function draw(props: Record<string, unknown>) {
    Object.assign(pageProps, {
        device: null,
        foreignDevice: false,
        year: 2026,
        canRecover: false,
        canRegister: false,
        errors: {},
        ...props,
    });

    return render(<Login />);
}

describe('شاشةُ الدخول تُري مخرجَها', () => {
    it('تعرض زرَّ النسيان حين يردّه جهازُ متجرٍ آخر', () => {
        draw({ device: DEVICE, foreignDevice: true, errors: { email: 'هذا الجهاز صندوقٌ لمتجرٍ آخر — انسَه ليفتح صندوقُ متجرك عليه.' } });

        expect(screen.getByRole('button', { name: 'انسَ هذا الجهاز' })).toBeInTheDocument();
    });

    /** ورسالةُ الخطأ العاديّة لا تحمل زرًّا: كلمةٌ خاطئةٌ لا تُصلَح بنسيان جهاز */
    it('لا تعرضه على خطأ بياناتٍ عاديّ', () => {
        draw({ device: DEVICE, foreignDevice: false, errors: { email: 'بيانات الدخول غير صحيحة.' } });

        expect(screen.queryByRole('button', { name: 'انسَ هذا الجهاز' })).not.toBeInTheDocument();
    });

    /** ولا تعرضه بلا جهازٍ مربوطٍ أصلًا — لا شيءَ يُنسى */
    it('لا تعرضه بلا جهازٍ مربوط', () => {
        draw({ device: null, foreignDevice: true, errors: { email: 'خطأ' } });

        expect(screen.queryByRole('button', { name: 'انسَ هذا الجهاز' })).not.toBeInTheDocument();
    });

    /** والضغطةُ تفتح التأكيد لا تنسى من فورها: جهازٌ مفعَّل يحتاج مديرًا لإعادة تفعيله */
    it('تفتح التأكيدَ قبل النسيان', async () => {
        draw({ device: DEVICE, foreignDevice: true, errors: { email: 'هذا الجهاز صندوقٌ لمتجرٍ آخر — انسَه ليفتح صندوقُ متجرك عليه.' } });

        await userEvent.click(screen.getByRole('button', { name: 'انسَ هذا الجهاز' }));

        expect(screen.getByText('نسيان هذا الجهاز')).toBeInTheDocument();
        expect(screen.getByText(/يحتاج مديرًا لإعادة تفعيله/)).toBeInTheDocument();
    });
});
