'use client';

import { useState } from 'react';
import { Media } from './primitives';

/**
 * معرضُ صور المنتج — صورةٌ كبيرة وشريطُ مصغَّرات.
 *
 * ولا يُستدعى إلّا حين تكون الصور أكثر من واحدة: صفحةُ منتجٍ بصورةٍ واحدة
 * لا تحتاج حالةً ولا JavaScript، فتُرسم على الخادم وحدها (انظر
 * `ProductView`). وهذا هو الفرق بين «صفحةٌ تفاعلية» و«جزءٌ منها يتفاعل».
 */
export default function ProductGallery({
    images,
    alt,
    ratio,
}: {
    images: string[];
    alt: string;
    ratio: string;
}) {
    const [current, setCurrent] = useState(0);
    const shown = images[current] ?? images[0];

    return (
        <div style={{ display: 'grid', gap: 12 }}>
            <Media src={shown} alt={alt} ratio={ratio} eager />

            <div className="w-rail" role="group" aria-label="صور المنتج">
                {images.map((src, i) => (
                    <button
                        key={src + i}
                        type="button"
                        onClick={() => setCurrent(i)}
                        aria-label={`${alt} — ${i + 1}`}
                        aria-pressed={i === current}
                        style={{
                            width: 74,
                            padding: 0,
                            border: `2px solid ${i === current ? 'var(--w-primary)' : 'var(--w-border)'}`,
                            borderRadius: 'var(--w-radius)',
                            overflow: 'hidden',
                            background: 'var(--w-surface)',
                            cursor: 'pointer',
                            lineHeight: 0,
                        }}
                    >
                        <img
                            src={src}
                            alt=""
                            loading="lazy"
                            decoding="async"
                            style={{ width: '100%', aspectRatio: '1 / 1', objectFit: 'cover', display: 'block' }}
                        />
                    </button>
                ))}
            </div>
        </div>
    );
}
