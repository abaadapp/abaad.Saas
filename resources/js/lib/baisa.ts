/**
 * المالُ بالبيسات الصحيحة (`BigInt`) — مرآةُ `App\Support\Baisa` حرفًا بحرف.
 *
 * الريالُ ألفُ بيسة، وكلُّ مبلغٍ ثلاثُ منازل لا رابعة لها. فالحسابُ على عددٍ
 * صحيح، والرقمُ العائم يُقرأ من أقصر تمثيلٍ عشريٍّ له (`String(x)`) كما يقرؤه
 * الخادم (`json_encode`) — فـ0.1 عُشرٌ تامّ، ولا يقع نصفُ بيسةٍ مرّةً فوق الحدّ
 * ومرّةً تحته بحسب تمثيل الرقم.
 */
export type Ratio = [bigint, bigint];

/** العددُ كما يُكتب بأقصر صورةٍ تعيده هو نفسَه ← بسطٌ ومقام */
export function exact(x: number): Ratio {
    if (!Number.isFinite(x)) return [0n, 1n];
    const m = /^(-?)(\d+)(?:\.(\d+))?(?:e([+-]?\d+))?$/i.exec(String(x));
    if (!m) return [0n, 1n];

    const fraction = m[3] ?? '';
    let num = BigInt(m[2] + fraction);
    const scale = fraction.length - Number(m[4] ?? 0);
    let den = 1n;
    if (scale >= 0) den = 10n ** BigInt(scale);
    else num *= 10n ** BigInt(-scale);

    return [m[1] === '-' ? -num : num, den];
}

/** `a ÷ b` نصفًا إلى أعلى — لما ليس سالبًا */
export const halfUp = (a: bigint, b: bigint): bigint => (2n * a + b) / (2n * b);

/** مبلغٌ بالبيسات — `round(x, 3) × 1000`، والسالبُ صفر */
export function milli(x: number): bigint {
    const [n, d] = exact(Math.max(0, x));
    return halfUp(n * 1000n, d);
}

/** البيساتُ ريالاتٍ */
export const toMoney = (b: bigint): number => Number(b) / 1000;

/**
 * يقسم مبلغًا صحيحًا على أوزانٍ بنسبتها — والحصصُ تجمع إليه بالضبط.
 *
 * `⌊المبلغ × الوزن ÷ المجموع⌋`، وما بقي من القصّ لأكبر الأوزان ما اتّسع له ثمّ
 * لما يليه (والتساوي بالترتيب)، ولا تتجاوز حصّةٌ وزنَها. مرآةُ `Baisa::spread`.
 */
export function spread(amount: bigint, weights: bigint[]): bigint[] {
    const w = weights.map((x) => (x > 0n ? x : 0n));
    const shares = w.map(() => 0n);
    const sum = w.reduce((a, b) => a + b, 0n);
    if (amount <= 0n || sum <= 0n) return shares;

    const total = amount < sum ? amount : sum;
    w.forEach((x, i) => {
        shares[i] = (total * x) / sum;
    });

    let left = total - shares.reduce((a, b) => a + b, 0n);
    const order = w.map((x, i) => [x, i] as const).sort((a, b) => (a[0] === b[0] ? a[1] - b[1] : a[0] > b[0] ? -1 : 1));
    for (const [x, i] of order) {
        if (left <= 0n) break;
        const room = x - shares[i];
        const add = room < left ? room : left;
        shares[i] += add;
        left -= add;
    }

    return shares;
}
