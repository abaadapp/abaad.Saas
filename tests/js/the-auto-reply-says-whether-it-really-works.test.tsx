import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import WhatsappAutoReply, { type AutoReplySettings } from '@/Pages/Admin/Integrations/partials/WhatsappAutoReply';

/**
 * الردُّ التلقائيّ يقول حالَه كما تقع — لا كما يقف المقبض.
 *
 *  • «يعمل» حين يُرسل فعلًا لو كتب زبونٌ الآن (`WhatsAppAutoReply::state`).
 *  • «بانتظار اكتمال الربط/الفوترة» حين يُشعَل ويمنعه ربطٌ أو فوترةٌ لم تُراجَع — وسببُه مكتوب.
 *  • «متوقف» لمن أطفأه.
 *  • والنصُّ العامّ باسم المتجر يُرى في الحقل الفارغ، ويُقال إنّه يُرسل ما لم يُكتب غيرُه.
 *
 * والخادمُ يحكم بها: `EveryOwnNumberAnswersTheFirstMessageTest`.
 */

const SETTINGS: AutoReplySettings = { enabled: true, explicit: false, ar: '', en: '', branch_id: null, cooldown_hours: 12 };

const DEFAULTS = {
    ar: 'أهلًا بك في محل الورد! شكرًا لتواصلك معنا — وصلتنا رسالتك وسنردّ عليك قريبًا.',
    en: 'Welcome to Rose Shop! Thank you for reaching out — we have received your message and will reply shortly.',
};

function show(state: 'working' | 'waiting' | 'off', reason: string | null = null, settings = SETTINGS) {
    return render(
        <WhatsappAutoReply settings={settings} state={{ state, reason }} defaults={DEFAULTS} branches={[]} linked mayManage />,
    );
}

describe('الردّ التلقائيّ يقول حالَه الحقيقيّة', () => {
    it('يعمل حين يقول الخادم إنّه يُرسل — والنصُّ العامّ باسم المتجر في الحقل الفارغ', () => {
        show('working');

        expect(screen.getByText('يعمل')).toBeInTheDocument();
        expect(screen.queryByTestId('autoreply-waiting')).toBeNull();
        expect(screen.getByPlaceholderText(DEFAULTS.ar)).toBeInTheDocument();
        expect(screen.getByPlaceholderText(DEFAULTS.en)).toBeInTheDocument();
        expect(screen.getByTestId('autoreply-default-note')).toHaveTextContent('النص الافتراضي باسم متجرك');
        expect(screen.getByText(/مُشغَّل افتراضيًا/)).toBeInTheDocument();
    });

    it('مقبضٌ مُشعَلٌ وفوترةٌ لم تُراجَع: «بانتظار» وسببُه — لا «يعمل»', () => {
        show('waiting', 'بانتظار إثبات فوترة ميتا — يعمل الرد فور مراجعة الدليل.');

        expect(screen.queryByText('يعمل')).toBeNull();
        expect(screen.getByText('بانتظار اكتمال الربط/الفوترة')).toBeInTheDocument();
        expect(screen.getByTestId('autoreply-waiting')).toHaveTextContent('بانتظار إثبات فوترة ميتا');
    });

    it('من أطفأه: «متوقف» — ولا تنبيهُ انتظار', () => {
        show('off', null, { ...SETTINGS, enabled: false, explicit: true });

        expect(screen.getByText('متوقف')).toBeInTheDocument();
        expect(screen.queryByText('يعمل')).toBeNull();
        expect(screen.queryByTestId('autoreply-waiting')).toBeNull();
    });

    it('ونصٌّ كتبه التاجر يبقى كما كتبه', () => {
        show('working', null, { ...SETTINGS, ar: 'نصّي أنا', en: 'My text', explicit: true });

        expect(screen.getByDisplayValue('نصّي أنا')).toBeInTheDocument();
        expect(screen.getByDisplayValue('My text')).toBeInTheDocument();
    });
});
