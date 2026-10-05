/**
 * خلفيّةُ الخيط — نقشٌ خفيفٌ متكرّر بروح واتساب ويب، مرسومٌ هنا لا منسوخ.
 *
 * بلاطةٌ واحدةٌ من رموز الرسائل (فقاعة، قلب، نجمة، هاتف، ساعة، ظرف…) بخطٍّ
 * رفيع، تتكرّر فوق لون الخيط. ولونان: داكنٌ خفيفٌ للّوح الفاتح، وفاتحٌ خفيفٌ
 * للّوح الداكن — فالمتغيّرُ `--cv-doodle` يختار، والفاتحُ بديلُه.
 */
const tile = (stroke: string) => {
    const svg = `<svg xmlns="http://www.w3.org/2000/svg" width="180" height="180" viewBox="0 0 180 180" fill="none" stroke="${stroke}" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round">
<path d="M18 20h22a6 6 0 0 1 6 6v12a6 6 0 0 1-6 6H28l-7 6v-6h-3a6 6 0 0 1-6-6V26a6 6 0 0 1 6-6z"/>
<path d="M78 30c-3-6-13-5-13 3 0 6 13 13 13 13s13-7 13-13c0-8-10-9-13-3z"/>
<path d="M140 14l3.5 7.2 8 1.1-5.8 5.6 1.4 7.9-7.1-3.8-7.1 3.8 1.4-7.9-5.8-5.6 8-1.1z"/>
<rect x="22" y="78" width="16" height="26" rx="3"/><path d="M28 99h4"/>
<circle cx="88" cy="92" r="11"/><path d="M88 85v7l5 3"/>
<rect x="128" y="80" width="28" height="20" rx="3"/><path d="M128 82l14 10 14-10"/>
<path d="M30 140c0-8 12-8 12 0 0 6-6 10-6 14M36 160h.01"/>
<path d="M78 136l12 12M90 136l-12 12"/><circle cx="84" cy="142" r="13"/>
<path d="M134 134c6 0 10 4 10 10s-4 10-10 10c-2 0-4-.5-5.5-1.5L124 154l1.5-4.5A10 10 0 0 1 134 134z"/>
<circle cx="58" cy="60" r="2.5"/><circle cx="114" cy="58" r="2"/><circle cx="60" cy="120" r="2"/><circle cx="112" cy="118" r="2.5"/><circle cx="160" cy="62" r="2"/><circle cx="10" cy="118" r="2"/>
</svg>`;

    return `url("data:image/svg+xml,${encodeURIComponent(svg)}")`;
};

/** على الخيط الفاتح — رماديٌّ داكنٌ بشفافيّةٍ خفيفة */
export const DOODLE_LIGHT = tile('rgba(84,101,111,0.13)');

/** على الخيط الداكن — فاتحٌ بشفافيّةٍ أخفّ */
export const DOODLE_DARK = tile('rgba(233,237,239,0.06)');
