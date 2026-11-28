import { useState } from 'react';
import { usePage } from '@inertiajs/react';
import { Plus, RefreshCw } from 'lucide-react';
import PlatformLayout from '@/Layouts/PlatformLayout';
import PageHeader from '@/Components/PageHeader';
import SmartLink from '@/Components/SmartLink';
import { Button } from '@/Components/ui/button';
import { useTranslate } from '@/lib/i18n';
import PlanCards, { type CapabilityOption, type PlanCard, type SectorChoice } from './partials/PlanCards';
import type { Currency, PageProps } from '@/types';

interface Props {
    plans: PlanCard[];
    capabilityOptions: CapabilityOption[];
    /** القطاعاتُ — قطاعُ الباقة الجديدة يُختار منها حين يتعدّد */
    sectors?: SectorChoice[];
    currency: Currency;
}

export default function Plans() {
    const { plans, capabilityOptions, sectors = [], currency } = usePage<PageProps<Props>>().props;
    const t = useTranslate();
    const [creating, setCreating] = useState(false);

    return (
        <PlatformLayout title="الباقات">
            <PageHeader
                title="الباقات"
                subtitle={t('إدارة باقات الاشتراك والأسعار والمزايا المتاحة للشركات')}
                actions={
                    <>
                        <Button variant="outline" asChild>
                            <SmartLink
                                routeName="super-admin.subscriptions.index"
                                href={route('super-admin.subscriptions.index')}
                            >
                                <RefreshCw />
                                {t('الاشتراكات')}
                            </SmartLink>
                        </Button>
                        <Button onClick={() => setCreating(true)}>
                            <Plus />
                            {t('باقة جديدة')}
                        </Button>
                    </>
                }
            />

            <PlanCards
                plans={plans}
                capabilityOptions={capabilityOptions}
                currency={currency}
                sectors={sectors}
                creating={creating}
                onCreatingChange={setCreating}
            />
        </PlatformLayout>
    );
}
