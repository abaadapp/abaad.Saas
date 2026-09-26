import { useForm, usePage } from '@inertiajs/react';

import { SettingsPage } from '@/Components/Settings';
import AdminLayout from '@/Layouts/AdminLayout';
import { useTranslate } from '@/lib/i18n';
import type { PageProps } from '@/types';
import SaveBar from './theme/SaveBar';
import Checkout from './theme/sections/Checkout';
import Fields from './theme/sections/Fields';
import Gateway, { type GatewayState } from './theme/sections/Gateway';
import { SCREEN_KEYS, only, seed, type ThemeSeed, type ThemeSettingsData } from './theme/sections/form';
import ThemeHeader, { type ThemeShell } from './theme/Shell';

interface Props extends ThemeShell, ThemeSeed {
    gateway: GatewayState;
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
 * ويبقى لبوّابة البطاقة زرُّها وحدَها — وذلك عن حقّ لا سهو: أسرارُها تُكتب
 * في `payment_gateways` لا في إعدادات المتجر، وبابُها آخر. وخلطُ سرٍّ
 * مشفَّرٍ في حمولةِ ضبطٍ عامّة بابُ تسريبٍ لا اختصارُ نقرة.
 */
export default function ThemeStore() {
    const props = usePage<PageProps<Props>>().props;
    const { site, gateway } = props;
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
                subtitle={t('ما يقبضه متجرك من زبونه، وما يُسأل عنه قبل أن يؤكّد طلبه')}
            />

            <SettingsPage>
                <div className="space-y-6">
                    <Checkout form={form} gatewayReady={gateway.ready} />

                    <Fields form={form} />

                    {/*
                        والحفظُ قبل بوّابة البطاقة لا بعدها: ما فوقه يحفظه،
                        وللبوّابة زرُّها. وزرُّ حفظٍ أسفلَ الصفحة كلِّها يقول
                        للناظر إنّه يحفظ البوّابةَ أيضًا — وهو لا يفعل.
                    */}
                    <SaveBar
                        dirty={form.isDirty}
                        processing={form.processing}
                        onSave={save}
                        onReset={() => form.reset()}
                    />

                    <Gateway gateway={gateway} />
                </div>
            </SettingsPage>
        </AdminLayout>
    );
}
