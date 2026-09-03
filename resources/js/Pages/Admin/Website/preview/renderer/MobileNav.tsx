'use client';

import { useEffect, useId, useRef, useState } from 'react';
import { Close, Menu } from './icons';
import { Link } from './primitives';
import type { Mode } from './types';

/**
 * قائمة الجوال — الشيءُ الوحيد في هذا العارض الذي يحتاج JavaScript.
 *
 * وكلُّ ما عداه يُرسم على الخادم: الأسئلة `details`، والخريطة `iframe`،
 * والفيديو `iframe`. فما يصل جهاز الزائر من شيفرةٍ هو هذا الملفّ وحده.
 *
 * وثلاثةٌ تجعلها قائمةً لا زرًّا يُظهر صندوقًا: `aria-expanded` يقول للقارئ
 * أمفتوحةٌ هي أم لا، و`Escape` يغلقها كما يتوقّع من اعتاد القوائم، والتركيز
 * يعود إلى الزرّ عند الإغلاق فلا يضيع من يتصفّح بلوحة المفاتيح.
 */
export default function MobileNav({
    links,
    mode,
    label = 'القائمة',
    closeLabel = 'إغلاق القائمة',
}: {
    links: { label: string; href: string }[];
    mode: Mode;
    label?: string;
    closeLabel?: string;
}) {
    const [open, setOpen] = useState(false);
    const button = useRef<HTMLButtonElement>(null);
    const id = useId();

    useEffect(() => {
        if (!open) return;

        const onKey = (e: KeyboardEvent) => {
            if (e.key === 'Escape') {
                setOpen(false);
                button.current?.focus();
            }
        };

        document.addEventListener('keydown', onKey);

        return () => document.removeEventListener('keydown', onKey);
    }, [open]);

    if (links.length === 0) return null;

    return (
        <>
            <button
                ref={button}
                type="button"
                className="w-nav-toggle"
                onClick={() => setOpen((v) => !v)}
                aria-expanded={open}
                aria-controls={id}
                aria-label={open ? closeLabel : label}
                style={{
                    alignItems: 'center',
                    justifyContent: 'center',
                    width: 44,
                    height: 44,
                    borderRadius: 'var(--w-radius)',
                    border: '1px solid var(--w-border)',
                    background: 'transparent',
                    color: 'inherit',
                    cursor: 'pointer',
                    flex: 'none',
                }}
            >
                {open ? <Close size={20} /> : <Menu size={20} />}
            </button>

            {open && (
                <nav
                    id={id}
                    className="w-nav-sheet"
                    aria-label={label}
                    style={{
                        flexBasis: '100%',
                        display: 'flex',
                        flexDirection: 'column',
                        gap: 2,
                        paddingTop: 12,
                        marginTop: 4,
                        borderTop: '1px solid var(--w-border)',
                    }}
                >
                    {links.map((l, i) => (
                        <Link
                            key={i}
                            href={l.href}
                            mode={mode}
                            style={{
                                padding: '12px 4px',
                                fontSize: 15,
                                fontWeight: 600,
                                display: 'flex',
                                alignItems: 'center',
                                minHeight: 44,
                            }}
                        >
                            {l.label}
                        </Link>
                    ))}
                </nav>
            )}
        </>
    );
}
