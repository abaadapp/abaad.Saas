import type { SVGProps } from 'react';

/**
 * أيقونات مرسومة هنا لا مستوردة.
 *
 * طبقة الرسم هذه تعيش في مستودعين — في أبعاد معاينةً وفي العارض موقعًا —
 * فأيّ حزمةٍ تستوردها تصير شرطًا على الاثنين. وهي أربع عشرة أيقونة، رسمُها
 * أهون من ربطهما بحزمة.
 *
 * والأيقونة زينةٌ لا معنى: `aria-hidden` على كلٍّ منها، والمعنى في النصّ
 * المجاور أو في `aria-label` على الرابط.
 */

type Props = SVGProps<SVGSVGElement> & { size?: number };

function Svg({ size = 20, children, ...rest }: Props) {
    return (
        <svg
            width={size}
            height={size}
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            strokeWidth={1.8}
            strokeLinecap="round"
            strokeLinejoin="round"
            aria-hidden="true"
            focusable="false"
            {...rest}
        >
            {children}
        </svg>
    );
}

export const Truck = (p: Props) => (
    <Svg {...p}>
        <path d="M14 17V5H2v12h2" />
        <path d="M14 9h4l3 3v5h-2" />
        <circle cx="7" cy="17" r="2" />
        <circle cx="17" cy="17" r="2" />
        <path d="M9 17h6" />
    </Svg>
);

export const ShieldCheck = (p: Props) => (
    <Svg {...p}>
        <path d="M12 3 4 6v6c0 5 3.4 8.2 8 9 4.6-.8 8-4 8-9V6l-8-3Z" />
        <path d="m9 12 2 2 4-4" />
    </Svg>
);

export const Headphones = (p: Props) => (
    <Svg {...p}>
        <path d="M4 15v-3a8 8 0 0 1 16 0v3" />
        <path d="M20 16a2 2 0 0 1-2 2h-1v-5h1a2 2 0 0 1 2 2v1ZM4 16a2 2 0 0 0 2 2h1v-5H6a2 2 0 0 0-2 2v1Z" />
    </Svg>
);

export const Clock = (p: Props) => (
    <Svg {...p}>
        <circle cx="12" cy="12" r="9" />
        <path d="M12 7v5l3 2" />
    </Svg>
);

export const Star = ({ filled, ...p }: Props & { filled?: boolean }) => (
    <Svg {...p} fill={filled ? 'currentColor' : 'none'}>
        <path d="m12 3 2.7 5.6 6.1.9-4.4 4.3 1 6.1-5.4-2.9-5.4 2.9 1-6.1L3.2 9.5l6.1-.9L12 3Z" />
    </Svg>
);

export const Phone = (p: Props) => (
    <Svg {...p}>
        <path d="M15.5 21A12.5 12.5 0 0 1 3 8.5 2.5 2.5 0 0 1 5.5 6h1.8a1 1 0 0 1 1 .8l.7 3a1 1 0 0 1-.3.95l-1.3 1.2a11 11 0 0 0 4.65 4.65l1.2-1.3a1 1 0 0 1 .95-.3l3 .7a1 1 0 0 1 .8 1v1.8A2.5 2.5 0 0 1 15.5 21Z" />
    </Svg>
);

export const Mail = (p: Props) => (
    <Svg {...p}>
        <rect x="3" y="5" width="18" height="14" rx="2" />
        <path d="m3.5 7 8.5 6 8.5-6" />
    </Svg>
);

export const MapPin = (p: Props) => (
    <Svg {...p}>
        <path d="M12 21s7-5.5 7-11a7 7 0 1 0-14 0c0 5.5 7 11 7 11Z" />
        <circle cx="12" cy="10" r="2.5" />
    </Svg>
);

export const ShoppingBag = (p: Props) => (
    <Svg {...p}>
        <path d="M5 8h14l-1 12H6L5 8Z" />
        <path d="M9 8V6a3 3 0 0 1 6 0v2" />
    </Svg>
);

export const ChevronLeft = (p: Props) => (
    <Svg {...p}>
        <path d="m14 6-6 6 6 6" />
    </Svg>
);

export const AtSign = (p: Props) => (
    <Svg {...p}>
        <circle cx="12" cy="12" r="4" />
        <path d="M16 8v5a3 3 0 0 0 6 0v-1a10 10 0 1 0-4 8" />
    </Svg>
);

export const Menu = (p: Props) => (
    <Svg {...p}>
        <path d="M4 6h16M4 12h16M4 18h16" />
    </Svg>
);

export const Close = (p: Props) => (
    <Svg {...p}>
        <path d="M6 6l12 12M18 6L6 18" />
    </Svg>
);

export const Search = (p: Props) => (
    <Svg {...p}>
        <circle cx="11" cy="11" r="7" />
        <path d="m20 20-3.5-3.5" />
    </Svg>
);

export const Play = (p: Props) => (
    <Svg {...p} fill="currentColor" stroke="none">
        <path d="M8 5.5v13l11-6.5-11-6.5Z" />
    </Svg>
);

/** أيقونات المزايا — مفتاحُها ما يُحفظ في بيانات القسم */
export const BENEFIT_ICONS: Record<string, (p: Props) => React.JSX.Element> = {
    truck: Truck,
    'shield-check': ShieldCheck,
    headphones: Headphones,
    clock: Clock,
    star: Star,
};
