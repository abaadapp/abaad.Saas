import { useForm, usePage } from '@inertiajs/react';

import { SettingsPage } from '@/Components/Settings';
import AdminLayout from '@/Layouts/AdminLayout';
import { useTranslate } from '@/lib/i18n';
import type { PageProps } from '@/types';
import SaveBar from './theme/SaveBar';
import Checkout from './theme/sections/Checkout';
import Fields from './theme/sections/Fields';
import Gateway, { type GatewayState } from './theme/sections/Gateway';
import Orders, { type OrderDesk } from './theme/sections/Orders';
import Terms, { type TermsSeed } from './theme/sections/Terms';
import { SCREEN_KEYS, only, seed, type ThemeSeed, type ThemeSettingsData } from './theme/sections/form';
import ThemeHeader, { type ThemeShell } from './theme/Shell';

interface Props extends ThemeShell, ThemeSeed {
    gateway: GatewayState;
    orderDesk: OrderDesk;
    terms: TermsSeed;
}

/**
 * المتجر والطلبات — أثقلُ التبويبات الستّة، وقد قِيس ثقلُه.
 *
 * ═══ العطبُ الذي جمع هذه الشاشاتِ مرّةً ═══
 *
 * حين كانت الستُّ شاشاتٍ قائمة، كان في هذه **اثنان وثلاثون مقبضًا** ومعها
 * **زرّا حفظٍ متجاوران** لا يحفظ أحدُهما ما يحفظه الآخر: من ضبط التوصيل
 * وضغط زرَّ الحقول خسر ما كتب.
 *
 * ═══ وما يمنع عودته ═══
 *
 * زرُّ حفظٍ **واحد** أسفل الشاشة يحمل مقابضَها كلَّها (`SCREEN_KEYS.store`).
 * والمجموعاتُ ثلاثٌ تحته لا ثلاثُ نماذج: «الدفع والاستلام» و«حقول إتمام
 * الطلب» و«كرت الهدية».
 *
 * وبوّابةُ البطاقة لا تُحرَّر هنا: مفاتيحُها في «التطبيقات التكاملية ←
 * Paymob» وحدها، وهنا حالُها ورابطٌ إليها (`sections/Gateway`).
 */
export default function ThemeStore() {
    const props = usePage<PageProps<Props>>().props;
    const { site, gateway, orderDesk, terms } = props;
    const t = useTranslate();

    const form = useForm<ThemeSettingsData>(seed(props));

    const save = () => {
        // النداءان منفصلان: `transform` تُثبِّت المحوّلَ على النموذج ولا تردّه
        form.transform((data) => only(data, SCREEN_KEYS.store));
        form.post(route('admin.marketing.store.save'), { preserveScroll: true });
    };

    return (
        <AdminLayout title="الموقع الإلكتروني">
            <ThemeHeader
                site={site}
                current="admin.website.shop"
                /*
                    ولا تُعيد أوّلَ بطاقةٍ تحتها: «الدفع والاستلام» تقول
                    «ما يقبضه متجرك من زبونه» — فقالت الترويسةُ ما يجمع
                    البطاقاتِ الأربع بدل أن تُكرّر أولاها.
                */
                subtitle={t('ما يستطيع زبونك أن يطلبه، وكيف يدفع ويستلم')}
            />

            <SettingsPage>
                <div className="space-y-6">
                    {/* متى يُستقبل الطلبُ أوّلًا — هو ما يُسأل عنه قبل كيف يُدفع ويُسلَّم */}
                    <Orders form={form} desk={orderDesk} />

                    <Checkout form={form} gatewayReady={gateway.online} />

                    <Fields form={form} />

                    {/* والشروطُ آخرَ ما يُضبط وأقربَ إلى زرّ الحفظ — هي آخرُ ما يقرؤه الزبونُ قبل التأكيد */}
                    <Terms form={form} terms={terms} />

                    {/*
                        والحفظُ قبل حال البوّابة لا بعدها: ما فوقه يحفظه،
                        والبوّابةُ تُحرَّر في بيتها. وزرُّ حفظٍ أسفلَ الصفحة
                        كلِّها يقول للناظر إنّه يحفظ البوّابةَ أيضًا — وهو لا يفعل.
                    */}
                    <SaveBar
                        dirty={form.isDirty}
                        processing={form.processing}
                        onSave={save}
                        onReset={() => form.reset()}
                    />

                    {/* حالُ Paymob ورابطٌ إلى بيتها — لكلّ متجر، بلا قائمة */}
                    <Gateway gateway={gateway} />
                </div>
            </SettingsPage>
        </AdminLayout>
    );
}
