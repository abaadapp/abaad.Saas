import { useRef, useState } from 'react';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeAll, beforeEach, describe, expect, it, vi } from 'vitest';
import { pageProps } from './setup';

/**
 * حقلُ ملفٍّ لم يُختر لا يُرسَل.
 *
 * نموذجُ الشركة يُجبَر على FormData لأنّ فيه ملفًّا، وFormData لا يعرف
 * `null`: يكتبها نصًّا فارغًا. فكان مفتاح `logo` يصل الخادمَ **حاضرًا
 * فارغًا** في كلّ حفظ — وحضورُه عنده يعني «غيِّر الشعار»، فيُمحى.
 *
 * والخادمُ يحرس نفسه من هذا الآن (انظر `ALogoOutlivesTheNextSaveTest`)،
 * لكنّ الطلبَ لا يقول ما لا يقصده: ما لم يُختر لا يُذكر.
 */

const sent: { url: string; payload: Record<string, unknown> }[] = [];

vi.mock('@inertiajs/react', async () => {
    const actual = await vi.importActual<Record<string, unknown>>('@inertiajs/react');

    /* بديلٌ أصغرُ ما يصدق: يمسك ما يُحوَّل وما يُرسَل، ولا يفتح شبكة */
    const useForm = (initial: Record<string, unknown>) => {
        const [data, setData] = useState(initial);
        const shape = useRef<(d: Record<string, unknown>) => Record<string, unknown>>((d) => d);

        const send = (url: string) => {
            sent.push({ url, payload: shape.current({ ...data }) });
        };

        return {
            data,
            errors: {} as Record<string, string>,
            processing: false,
            setData: (key: unknown, value?: unknown) =>
                setData((d) =>
                    typeof key === 'function'
                        ? (key as (x: Record<string, unknown>) => Record<string, unknown>)(d)
                        : typeof key === 'string'
                          ? { ...d, [key]: value }
                          : { ...d, ...(key as Record<string, unknown>) },
                ),
            transform: (fn: (d: Record<string, unknown>) => Record<string, unknown>) => {
                shape.current = fn;
            },
            post: send,
            put: send,
            patch: send,
            reset: () => setData(initial),
            clearErrors: () => {},
            setError: () => {},
            recentlySuccessful: false,
            wasSuccessful: false,
            isDirty: false,
        };
    };

    return {
        ...actual,
        usePage: () => ({ props: pageProps, url: '/', component: 'Test' }),
        Head: () => null,
        useForm,
    };
});

const BusinessForm = (await import('@/Pages/Platform/Businesses/partials/BusinessForm')).default;

const options = {
    types: ['عام', 'محل ورود'],
    cities: ['مسقط'],
    statuses: ['نشط', 'معطل'],
    plans: [{ label: 'الأساسية', value: 1 }],
};

const initial = {
    name: 'متجر الشعار',
    type: 'عام',
    country: 'عُمان',
    city: 'مسقط',
    address: '',
    owner_name: '',
    phone: '',
    email: '',
    plan_id: '',
    status: 'نشط',
    starts_at: '',
    ends_at: '',
    tier: '',
    storefront_theme: '',
};

function editScreen(logoUrl: string | null = '/storage/logos/shop.png') {
    return render(
        <BusinessForm
            options={options}
            initial={initial}
            logoUrl={logoUrl}
            ownerEmail="sahib@abaadapp.om"
            businessId={7}
            action="/super-admin/businesses/7"
            method="put"
            submitLabel="حفظ التعديلات"
            cancelHref="/super-admin/businesses"
        />,
    );
}

const save = async (user: ReturnType<typeof userEvent.setup>) =>
    user.click(screen.getByRole('button', { name: 'حفظ التعديلات' }));

const fileInput = (container: HTMLElement) =>
    container.querySelector('input[type="file"]') as HTMLInputElement;

const png = () => new File(['\u0089PNG'], 'shop.png', { type: 'image/png' });

beforeAll(() => {
    // jsdom لا يملكها، والمعاينة تناديها لحظة الاختيار
    Object.defineProperty(URL, 'createObjectURL', { value: () => 'blob:shop', writable: true });
});

beforeEach(() => {
    sent.length = 0;
});

describe('شعار الشركة في لوحة المنصّة', () => {
    it('لا يُرسل حقل الشعار حين لا يُختار ملف', async () => {
        const user = userEvent.setup();
        editScreen();

        await save(user);

        expect(sent).toHaveLength(1);
        expect(sent[0].payload).not.toHaveProperty('logo');
    });

    it('ويُرسله ملفًّا حين يُختار', async () => {
        const user = userEvent.setup();
        const { container } = editScreen(null);

        await user.upload(fileInput(container), png());
        await save(user);

        expect(sent[0].payload.logo).toBeInstanceOf(File);
        expect((sent[0].payload.logo as File).name).toBe('shop.png');
        expect(sent[0].payload.remove_logo).toBe(false);
    });

    it('و«حذف الشعار» يرسل نيّة الحذف بلا حقل ملف', async () => {
        const user = userEvent.setup();
        editScreen();

        await user.click(screen.getByRole('button', { name: 'حذف الشعار' }));
        await save(user);

        expect(sent[0].payload.remove_logo).toBe(true);
        expect(sent[0].payload).not.toHaveProperty('logo');
    });

    it('والتعديل يمرّ بـPUT ولا يحمل بيانات حسابٍ قائم', async () => {
        const user = userEvent.setup();
        editScreen();

        await save(user);

        expect(sent[0].payload._method).toBe('put');
        expect(sent[0].payload).not.toHaveProperty('login_username');
        expect(sent[0].payload).not.toHaveProperty('login_password');
    });

    it('والمعاينة تعرض الشعار المحفوظ ثم تختفي عند حذفه', async () => {
        const user = userEvent.setup();
        editScreen();

        expect(screen.getByAltText('معاينة الشعار')).toHaveAttribute('src', '/storage/logos/shop.png');

        await user.click(screen.getByRole('button', { name: 'حذف الشعار' }));

        expect(screen.queryByAltText('معاينة الشعار')).toBeNull();
        expect(screen.getByText('سيُحذف الشعار عند الحفظ.')).toBeInTheDocument();
    });
});
