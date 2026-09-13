import { type ReactNode } from 'react';
import { Head, router, usePage } from '@inertiajs/react';
import { Languages } from 'lucide-react';

import Logo from '@/Components/Logo';
import { cn } from '@/lib/utils';
import { useTranslate } from '@/lib/i18n';
import type { PageProps } from '@/types';

interface Props {
    /** عنوانُ اللسان في المتصفّح */
    title: string;
    /** السنةُ من الخادم — لا من ساعة الجهاز، انظر أدناه */
    year?: number;
    /** العمودُ الثاني على الشاشة الواسعة: عرضُ المنتج. وبدونه يتمركز النموذج */
    showcase?: ReactNode;
    children: ReactNode;
    className?: string;
}

/**
 * غلافُ أبواب الدخول — واحدٌ للدخول والتسجيل والتهيئة.
 *
 * ═══ ولمَ غلافٌ واحد ═══
 *
 * كانت كلُّ شاشةٍ من السبع تحمل خلفيّتَها وشعارَها ومبدّلَ لغتها وتذييلَها
 * بيدها: سبعُ نسخٍ من الشيء نفسه تفترق عند أوّل تعديل. فمن غيّر لونَ الخلفيّة
 * في الدخول يجدها على حالها في «نسيت كلمة المرور» — وهما بابان متتاليان
 * يمرّ بهما المستخدم في دقيقة.
 *
 * ═══ والخلفيّة ظلٌّ لا لون ═══
 *
 * أرضٌ شبهُ بيضاء، وفوقها هالتان سوداوان شفّافتان جدًّا وواسعتان. تعطيان
 * عمقًا يُحسّ ولا يُرى: لا تجعلان الصفحةَ رماديّةً ولا تزاحمان النصّ. والأسودُ
 * في هويّة أبعاد حبرٌ وأفعالٌ أساسيّة وظلٌّ محيط — لا كتلةٌ ثقيلةٌ خلف المحتوى.
 *
 * والهالاتُ في `::before` لا في صورة: لا ملفَّ يُحمَّل، ولا شيءَ يُطبع، وتتبع
 * مقاسَ الشاشة وحدها.
 */
export default function AuthLayout({ title, year, showcase, children, className }: Props) {
    const { locale } = usePage<PageProps>().props;
    const t = useTranslate();

    /**
     * اتجاه الصفحة (dir) يُحسم في قالب الجذر عند تحميلها، فلا يكفي تحديث
     * Inertia الجزئي لقلبه — نعيد التحميل بعد الحفظ كما تفعل بقية اللوحات.
     */
    const switchLocale = () => {
        const next = locale === 'en' ? 'ar' : 'en';
        router.post(
            route('language.guest'),
            { locale: next },
            { onSuccess: () => window.location.reload() },
        );
    };

    /*
        والعمودان عمودان من أعلى الصفحة إلى أسفلها.

        جُرّب أوّلًا أن تكون الترويسةُ والتذييلُ فوق العمودين وتحتهما، فخرجت
        لوحةُ العرض محصورةً بينهما: شريطٌ أبيضُ فوقها وآخرُ تحتها، تُقرأ
        بطاقةً وُضعت في الصفحة لا نصفًا منها.

        فصار الانقسامُ عند الجذر: الشعارُ ومبدّلُ اللغة والتذييل كلُّها داخل
        عمود النموذج، واللوحةُ تملأ نصفَها كاملًا. وعلى الشاشة الضيّقة يعود
        الترتيبُ عمودًا واحدًا بلا شيءٍ من ذلك.
    */
    const column = (
        <div
            className={cn(
                'relative flex min-h-dvh flex-1 flex-col overflow-hidden bg-[#f7f8f9]',
                /*
                    ومع لوحةِ العرض يتنازل العمودُ عن ارتفاعه الأدنى.

                    الجذرُ حينئذٍ `lg:h-dvh` فيقيس الارتفاعَ للاثنين معًا،
                    و`min-h-dvh` باقيةً على العمود تجعله يطلب ارتفاعَ الشاشة
                    **داخل** ارتفاعِ الشاشة — فيمتدّ الجذرُ ويظهر شريطُ
                    تمريرٍ لا محتوى تحته.
                */
                showcase && 'lg:min-h-0',
            )}
        >

            {/*
                الهالتان — ظلٌّ محيطٌ لا خلفيّةٌ ملوّنة.

                `pointer-events-none` لأنّها فوق مجرى الصفحة: بدونها تبتلع
                الضغطَ على أوّل حقلٍ تمرّ فوقه. و`aria-hidden` لأنّها لا تقول
                شيئًا لمن يقرأ بالصوت.
            */}
            <div
                aria-hidden
                className="pointer-events-none absolute inset-0"
                style={{
                    backgroundImage:
                        'radial-gradient(60rem 34rem at 18% -8%, rgba(17,17,17,0.055), transparent 62%),' +
                        'radial-gradient(52rem 30rem at 88% 108%, rgba(17,17,17,0.045), transparent 60%)',
                }}
            />

            {/* الترويسة: الشعار ومبدّلُ اللغة — وهما ثابتان في كلّ باب */}
            <header className="relative z-10 flex items-center justify-between gap-4 px-5 py-5 sm:px-8">
                <Logo className="h-8 w-auto text-[#111]" />

                <button
                    type="button"
                    onClick={switchLocale}
                    lang={locale === 'en' ? 'ar' : 'en'}
                    className="flex items-center gap-1.5 rounded-[10px] px-3 py-1.5 text-[13px] font-medium text-[#6b7280] transition-colors hover:bg-white hover:text-[#111] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#111]"
                >
                    <Languages className="size-4" />
                    {/* اسم اللغة الأخرى بلغتها: زرٌّ واحد لا قائمة، والخياران اثنان */}
                    {locale === 'en' ? 'العربية' : 'English'}
                </button>
            </header>

            <main className={cn('relative z-10 flex flex-1 items-center justify-center px-5 py-6 sm:px-8', className)}>
                <div className="w-full max-w-[420px]">{children}</div>
            </main>

            {/*
                والسنةُ من الخادم لا من ساعة الجهاز.

                `new Date()` تقرأ ساعةَ الزائر: حاسوبٌ ساعتُه مغلوطةٌ يطبع
                «© 2019» تحت شعار النظام. وحيث لا تصل — شاشةُ صيانةٍ مثلًا —
                لا يُطبع سطرُ الحقوق أصلًا بدل أن يُخترع له رقم.
            */}
            <footer className="relative z-10 shrink-0 px-5 pb-6 text-center sm:px-8">
                {year !== undefined && (
                    <p className="text-[12px] text-[#9ca3af]">
                        © {year} Abaad — {t('جميع الحقوق محفوظة')}
                    </p>
                )}
            </footer>
        </div>
    );

    if (! showcase) {
        return (
            <>
                <Head title={title} />
                {column}
            </>
        );
    }

    /*
        وترتيبُ الشيفرة هو ترتيبُ القراءة على الشاشة الضيّقة.

        عمودُ النموذج أوّلًا — فمفتاحُ Tab وقارئُ الشاشة يبلغانه قبل العرض،
        ولا يُحتاج `order` مقلوبٌ يفترق فيه ما يُرى عمّا يُقرأ.
    */
    return (
        <>
            <Head title={title} />
            <div className="flex min-h-dvh flex-col lg:grid lg:h-dvh lg:grid-cols-2 lg:items-stretch">
                {column}
                {showcase}
            </div>
        </>
    );
}
