import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { router } from '@inertiajs/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { pageProps } from './setup';
import WhatsappSender, { type SenderView } from '@/Components/WhatsappSender';
import ClientLinkBox, { type ClientLinkView } from '@/Pages/Platform/Businesses/partials/ClientLinkBox';
import OwnNumbersHealth, { type OwnNumberRow } from '@/Pages/Platform/Settings/partials/OwnNumbersHealth';
import WhatsAppMerchantLinkSwitch, { type WhatsAppLinkState } from '@/Pages/Platform/Settings/partials/WhatsAppMerchantLinkSwitch';

/**
 * ‏‏رقمُ الإرسال عند التاجر وعند فريق أبعاد — يُقال ما بقي ومن يفعله، ولا يُرجَع إلى رقم أبعاد إلّا بضغطةٍ وتأكيد.
 */

const NONE: SenderView = {
    sends_from: 'shared', phone: '+968 9000 0001', own_app: false, state: 'none', steps: [], blocking: null,
    checked_at: null, adopted_at: null, problem: null, shared_possible: true, history: [],
};

const STEPS: SenderView['steps'] = [
    { key: 'link', label: 'الرقم مربوط والتفويض سليم', state: 'ok', note: null },
    { key: 'billing', label: 'حساب واتساب للمتجر لا لأبعاد', state: 'ok', note: null },
    { key: 'send', label: 'الرقم متصل ويُرسل', state: 'fail', note: 'الرقم غير متصل عند واتساب' },
    { key: 'receive', label: 'رسائل العملاء تصل أبعاد', state: 'unknown', note: null },
    { key: 'templates', label: 'قوالب الإشعارات معتمدة في حساب المتجر', state: 'wait', note: 'بقي 3 من 4' },
    { key: 'queue', label: 'لا رسالة في الطريق من رقم أبعاد', state: 'ok', note: null },
];

const checking = (over: Partial<SenderView> = {}): SenderView => ({
    ...NONE, state: 'checking', steps: STEPS, blocking: { text: 'الرقم غير متصل عند واتساب', who: 'merchant' }, checked_at: '2026-11-25 10:00', ...over,
});

const broken = (over: Partial<SenderView> = {}): SenderView => ({
    ...NONE, sends_from: 'own', state: 'problem', problem: { text: 'تفويض واتساب لم يعد صالحًا — أعد الربط.', who: 'merchant', since: '2026-11-25 09:00' }, ...over,
});

beforeEach(() => {
    vi.mocked(router.post).mockClear();
});

describe('رقم الإرسال', () => {
    it('لا يظهر لمن لا شيء يُقال عنه — ووصلةُ تطبيق التاجر كما كانت', () => {
        const { container } = render(<WhatsappSender sender={{ ...NONE, own_app: true, sends_from: 'own' }} audience="merchant" />);
        expect(container).toBeEmptyDOMElement();
    });

    it('وهو يُتحقَّق منه: كلُّ مرحلةٍ بحالها وسببها، ومن يفعل ما بقي', () => {
        render(<WhatsappSender sender={checking()} audience="merchant" mayManage />);

        expect(screen.getByTestId('wa-sender-checking')).toHaveTextContent('ما زالت تخرج من رقم أبعاد');
        for (const s of STEPS) expect(screen.getByTestId(`wa-sender-step-${s.key}`)).toHaveAttribute('data-state', s.state);
        expect(screen.getByTestId('wa-sender-step-templates')).toHaveTextContent('بقي 3 من 4');
        expect(screen.getByTestId('wa-sender-who')).toHaveTextContent('على صاحب المتجر');
        expect(screen.queryByTestId('wa-sender-use-shared')).toBeNull();
    });

    it('ومن يبدأ على رقمه لا يُقال له إنّ رسائله من رقم أبعاد', () => {
        render(<WhatsappSender sender={checking({ sends_from: 'own' })} audience="merchant" />);
        expect(screen.getByTestId('wa-sender-checking')).not.toHaveTextContent('رقم أبعاد');
    });

    it('وما ينتظر Meta لا يُطلب من أحد', () => {
        render(<WhatsappSender sender={checking({ blocking: { text: 'ننتظر اعتماد القوالب', who: 'meta' } })} audience="platform" />);
        expect(screen.getByTestId('wa-sender-who')).toHaveTextContent('نتابعه تلقائيًّا');
    });

    it('وبعد الاعتماد: رسائلُه من رقمه، ومعه متى كان', () => {
        render(<WhatsappSender sender={{ ...NONE, sends_from: 'own', state: 'adopted', history: [{ at: '2026-11-25 10:05', from: 'رقم أبعاد', to: 'رقم المتجر', phone: '+968 9000 0001', by: 'تلقائيًّا' }] }} audience="merchant" />);

        expect(screen.getByTestId('wa-sender-adopted')).toHaveTextContent('تخرج من رقمك');
        expect(screen.getByTestId('wa-sender-history')).toHaveTextContent('رقم أبعاد ← رقم المتجر');
    });

    it('ورقمٌ تعطّل: السببُ، ومنذ متى، وأنّ رسائله لا تخرج من رقمٍ آخر', () => {
        render(<WhatsappSender sender={broken()} audience="merchant" mayManage />);

        const box = screen.getByTestId('wa-sender-problem');
        expect(box).toHaveTextContent('لم يعد صالحًا');
        expect(box).toHaveTextContent('ولا تخرج من رقم أبعاد ولا من رقم آخر');
        expect(box).toHaveTextContent('2026-11-25 09:00');
    });

    it('والرجوعُ إلى رقم أبعاد بتأكيدٍ لا بضغطة', async () => {
        const user = userEvent.setup();
        render(<WhatsappSender sender={broken()} audience="merchant" mayManage />);

        await user.click(screen.getByTestId('wa-sender-use-shared'));
        expect(router.post).not.toHaveBeenCalled();

        const dialog = await screen.findByRole('alertdialog').catch(() => screen.findByRole('dialog'));
        expect(dialog).toHaveTextContent('رقمًا غير رقمك');
        await user.click(within(dialog).getByRole('button', { name: 'أرسل من رقم أبعاد' }));
        expect(router.post).toHaveBeenCalledWith('/admin.integrations.whatsapp.mode', { mode: 'abaad_shared' }, expect.anything());
    });

    it('ولا يُعرض الرجوعُ لمن لا يملكه: موظّفٌ، أو من أُغلق عنه رقم أبعاد، أو فريق أبعاد', () => {
        const { rerender } = render(<WhatsappSender sender={broken()} audience="merchant" />);
        expect(screen.queryByTestId('wa-sender-use-shared')).toBeNull();
        rerender(<WhatsappSender sender={broken({ shared_possible: false })} audience="merchant" mayManage />);
        expect(screen.queryByTestId('wa-sender-use-shared')).toBeNull();
        rerender(<WhatsappSender sender={broken()} audience="platform" mayManage />);
        expect(screen.queryByTestId('wa-sender-use-shared')).toBeNull();
        expect(screen.getByTestId('wa-sender-problem')).toHaveTextContent('رسائل المتجر متوقّفة');
    });
});

describe('عند فريق أبعاد', () => {
    const LINK: ClientLinkView = {
        can: false, why: null, hours: 72,
        link: { state: 'used', label: 'رُبط الرقم', created_by: 'أبعاد', created_at: '2026-11-25 09:00', expires_at: null, opened_at: null, attempts: 1, problem: null, connected_at: '2026-11-25 09:30', phone: '+968 9000 0001' },
    };

    it('لا يُطلب من الفريق تبديلٌ يقع تلقائيًّا', () => {
        Object.assign(pageProps as unknown as Record<string, unknown>, { flash: { toast: null, status: null }, errors: {} });
        const { rerender } = render(<ClientLinkBox businessId={7} data={LINK} stillShared switchesItself />);
        expect(screen.getByTestId('client-link-still-shared')).toHaveTextContent('تلقائيًّا');
        expect(screen.getByTestId('client-link-still-shared')).not.toHaveTextContent('واحفظ');

        rerender(<ClientLinkBox businessId={7} data={LINK} stillShared />);
        expect(screen.getByTestId('client-link-still-shared')).toHaveTextContent('واحفظ');
        for (const key of ['flash', 'errors']) delete (pageProps as unknown as Record<string, unknown>)[key];
    });

    it('وجدولُ أرقام المتاجر يقول من أيّ رقمٍ تخرج رسائلُ كلٍّ منها', () => {
        const health = { key: 'ready', label: 'جاهز', hint: '', tone: 'info', checked_at: null } as OwnNumberRow['health'];
        render(<OwnNumbersHealth rows={[
            { business_id: 1, business: 'أ', phone: null, inbox: true, health, sender: { state: 'problem', text: 'تفويض واتساب لم يعد صالحًا' } },
            { business_id: 2, business: 'ب', phone: null, inbox: true, health },
        ]} />);

        expect(screen.getAllByTestId('own-number-sender')).toHaveLength(1);
        expect(screen.getByTestId('own-number-sender')).toHaveAttribute('data-state', 'problem');
    });

    const STATE: WhatsAppLinkState = {
        mode: 'own_app', default: 'own_app', platform: null, readable: true, config_id: '2177821789438050', config_source: 'platform',
        app_id_ready: true, app_secret_ready: true, ready: true, coexistence: false, pilot: [], pilot_max: 20, coexistence_fields: ['history'], history: [],
    };

    it('متاجرُ التجربة تُكتب بأرقامها وتُحفظ وحدها', async () => {
        const user = userEvent.setup();
        render(<WhatsAppMerchantLinkSwitch state={STATE} />);

        await user.type(screen.getByTestId('wa-pilot-input'), '7، 9');
        await user.click(screen.getByTestId('wa-pilot-save'));
        expect(router.post).toHaveBeenCalledTimes(1);
        expect(router.post).toHaveBeenCalledWith('/super-admin.whatsapp.merchant-link.pilot', { pilot: '7، 9' }, expect.anything());
    });

    it('ومتاجرُها تُعرض بأسمائها، ويُفتح لها خيارُ البقاء في التطبيق', () => {
        render(<WhatsAppMerchantLinkSwitch state={{ ...STATE, pilot: [{ id: 7, name: 'متجر التجربة' }] }} />);
        expect(screen.getByTestId('wa-pilot-list')).toHaveTextContent('متجر التجربة');
        expect(screen.getByTestId('wa-coex-flip')).toBeEnabled();
    });

    it('واعتمادُ الأرقام يُوقف ويُعاد بتأكيد', async () => {
        const user = userEvent.setup();
        const { rerender } = render(<WhatsAppMerchantLinkSwitch state={STATE} />);
        expect(screen.getByTestId('wa-auto-state')).toHaveTextContent('يعمل');
        await user.click(screen.getByTestId('wa-auto-flip'));
        expect(router.post).not.toHaveBeenCalled();
        const dialog = await screen.findByRole('alertdialog').catch(() => screen.findByRole('dialog'));
        await user.click(within(dialog).getByRole('button', { name: 'إيقاف' }));
        expect(router.post).toHaveBeenCalledWith('/super-admin.whatsapp.merchant-link.auto-sender', { enabled: false }, expect.anything());

        rerender(<WhatsAppMerchantLinkSwitch state={{ ...STATE, auto_sender: false }} />);
        expect(screen.getByTestId('wa-auto-state')).toHaveTextContent('موقوف');
    });

    it('ولا تجربةَ والربطُ غيرُ جاهز، ولا معنى لها والجميعُ على تطبيق أبعاد', () => {
        const { rerender } = render(<WhatsAppMerchantLinkSwitch state={{ ...STATE, ready: false }} />);
        expect(screen.getByTestId('wa-pilot-save')).toBeDisabled();
        expect(screen.getByTestId('wa-coex-flip')).toBeDisabled();
        rerender(<WhatsAppMerchantLinkSwitch state={{ ...STATE, mode: 'abaad_app' }} />);
        expect(screen.queryByTestId('wa-pilot')).toBeNull();
    });
});
