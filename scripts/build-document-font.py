#!/usr/bin/env python3
"""
خطُّ الورقة لمحرّك الطباعة — مبنيًّا من مقاطع المتصفّح نفسِها.

═══ العطبُ الذي وُلد منه هذا الملفّ ═══

المعاينةُ في المتصفّح تُرسم بـ«IBM Plex Sans Arabic» من `public/fonts/*.woff2`،
والـPDF كان يُرسم بـ`xbriyaz` — خطٌّ عربيٌّ يأتي مضمّنًا مع mpdf. خطّان
مختلفان: مقاساتُ حروفهما تختلف، فينكسر السطرُ الطويل عند كلمةٍ في الشاشة
وعند أخرى على الورق، ويرى التاجر ورقةً ويطبع غيرَها.

وmpdf لا يقرأ woff2 — يريد TTF. ولا يقرأ عشرين مقطعًا — يريد ملفًّا واحدًا
لكلّ وزن.

═══ والحلُّ أن يُبنى من المقاطع نفسِها ═══

فتُدمج مقاطعُ الوزن الواحد في TTF واحد: العربيُّ واللاتينيُّ وما بينهما.
والنتيجةُ **الحروفُ ذاتُها بالمقاسات ذاتها** في المحرّكين — لا خطّان
متقاربان، بل خطٌّ واحد. وهو ما لا يبلغه تنزيلُ نسخةٍ ثانية من الخطّ من
مكانٍ آخر: نسخةٌ أخرى قد تكون إصدارًا آخر بمقاساتٍ أخرى.

ولا أصلَ جديد يُضاف إلى المستودع من الشبكة، ولا اعتماديّة تُثبَّت في الإنتاج:
المقاطعُ موجودةٌ أصلًا — تُشحن اليوم لمواقع التجّار.

═══ الاستعمال ═══

    pip install fonttools brotli
    python3 scripts/build-document-font.py

يقرأ `public/fonts/ibmpsa-*.woff2` ويكتب `resources/fonts/*.ttf`.

    python3 scripts/build-document-font.py --patch

يُصلح المسافةَ على الملفّين الملتزَمين وحدها، بلا إعادة بناء (`keep_spaces`).
وتُلتزَم المخرجاتُ في المستودع: الخادمُ لا يبني خطوطًا عند النشر.
"""

import os
import sys
import unicodedata

from fontTools.merge import Merger
from fontTools.ttLib import TTFont

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
SRC = os.path.join(ROOT, 'public', 'fonts')
OUT = os.path.join(ROOT, 'resources', 'fonts')

# أرقامُ مقاطع غوغل لكلّ وزن: العربيُّ أوّلًا ثمّ اللاتينيُّ وامتداداته.
#
# والعربيُّ أوّلًا عمدًا: عند تعارض حرفٍ بين مقطعين يُبقي الدامجُ الأوّلَ —
# والمقطعُ العربيُّ هو الذي يحمل جداولَ التشكيل (init/medi/fina) التي بها
# تتّصل الحروف. ولو سبقه اللاتينيُّ لخرجت الورقةُ بحروفٍ منفصلة.
#
# ٤٠٠ و٧٠٠ وحدهما: الورقةُ تكتب بوزنين — جسدٌ وعنوان. وmpdf لا يصطنع
# وزنًا وسيطًا، فوزنٌ لا يُطلب حِملٌ في كلّ ملفّ يُطبع.
WEIGHTS = {
    'IBMPlexSansArabic-Regular.ttf': [5, 6, 7, 8],
    'IBMPlexSansArabic-Bold.ttf': [17, 18, 19, 20],
}

#: اسمُ الأسرة كما ستُعرف في mpdf وفي القوالب
FAMILY = 'IBM Plex Sans Arabic'


def rename(font: TTFont, subfamily: str) -> None:
    """
    واسمٌ واحدٌ للوزنين — والوزنُ في `subfamily`.

    مقاطعُ غوغل تسمّي كلَّ وزنٍ أسرةً قائمة («IBM Plex Sans Arabic Bold»)،
    فيراهما mpdf أسرتين لا وزنين من واحدة، ولا يجد الغليظَ حين يُطلب.
    """
    full = FAMILY if subfamily == 'Regular' else f'{FAMILY} {subfamily}'
    ps = full.replace(' ', '')

    for record in font['name'].names:
        if record.nameID == 1:
            record.string = FAMILY
        elif record.nameID == 2:
            record.string = subfamily
        elif record.nameID == 4:
            record.string = full
        elif record.nameID == 6:
            record.string = ps


def keep_spaces(font: TTFont) -> int:
    """
    والمسافةُ تبقى مسافة — لا يستبدلها الدمجُ بنسخةٍ لا رقمَ لها.

    ═══ العطب ═══

    كلُّ مقطعٍ من مقاطع غوغل يحمل مسافتَه، والدامجُ يُبقي واحدةً في جدول
    الحروف (`cmap`) ويكتب قاعدة `locl` تُبدّل المسافةَ بنسخة المقطع الآخر
    (`space` ← `space.3`): الشكلُ نفسُه (لا خطوط) والعرضُ نفسُه. ولا رقمَ
    يونيكود للنسخة، فيعطيها mpdf رقمًا في المنطقة الخاصّة (U+E28D، والغليظ
    U+E288) — فلا تبقى في السطر المُشكَّل مسافةٌ واحدة.

    ومُقطِّعُ الأسطر في mpdf لا يكسر إلّا عند `' '`: فلا يجد موضعَ كسرٍ في
    العبارة العربيّة كلِّها، ويقطع الكلمةَ حيث امتلأ السطر («تغلي» ثمّ «ف
    ذهبي»)، ويرى العبارةَ في الجدول كلمةً واحدة فيُصغّر الجدولَ كلَّه بدل أن
    يلفّها.

    فتُحذف من قواعد الاستبدال المفردة مسافاتُ الدمج وحدها: مصدرٌ رقمُه في
    `cmap` مسافةٌ أو حرفُ تحكّم، ونسختُه بلا خطوط وبعرضه نفسِه. وعلاماتُ
    التشكيل في القاعدة نفسِها تبقى — نسخُها قد تحمل مراسيَ تموضعٍ أخرى.
    """
    cmap = font.getBestCmap()
    glyf = font['glyf']
    hmtx = font['hmtx']
    blank = {
        name for code, name in cmap.items()
        if unicodedata.category(chr(code)) in ('Zs', 'Cc')
    } | {'.null'}

    def same_blank(a: str, b: str) -> bool:
        return (b in glyf.keys() and glyf[a].numberOfContours == 0 and glyf[b].numberOfContours == 0
                and hmtx[a] == hmtx[b])

    dropped = 0
    for lookup in font['GSUB'].table.LookupList.Lookup:
        for sub in lookup.SubTable:
            table = sub.ExtSubTable if lookup.LookupType == 7 else sub
            mapping = getattr(table, 'mapping', None)
            if lookup.LookupType not in (1, 7) or not mapping:
                continue
            for src in [s for s in mapping if s in blank and same_blank(s, mapping[s])]:
                del mapping[src]
                dropped += 1

    return dropped


def build(out_name: str, parts: list[int]) -> None:
    paths = [os.path.join(SRC, f'ibmpsa-{n}.woff2') for n in parts]

    missing = [p for p in paths if not os.path.exists(p)]
    if missing:
        sys.exit('مقاطعُ ناقصة: ' + ', '.join(os.path.basename(p) for p in missing))

    """
    وwoff2 يُفكّ إلى TTF قبل الدمج — الدامجُ لا يقرأ المضغوط.

    والفكُّ لا يمسّ الحروف: woff2 غلافُ ضغطٍ حول جداول TTF نفسِها.
    """
    tmp = []
    for path in paths:
        font = TTFont(path)
        font.flavor = None
        ttf = path.replace('.woff2', '.tmp.ttf')
        font.save(ttf)
        tmp.append(ttf)

    try:
        merged = Merger().merge(tmp)
        subfamily = 'Bold' if 'Bold' in out_name else 'Regular'
        rename(merged, subfamily)
        keep_spaces(merged)

        os.makedirs(OUT, exist_ok=True)
        target = os.path.join(OUT, out_name)
        merged.save(target)

        cmap = merged.getBestCmap()
        print('%-34s glyphs=%-5d cmap=%-5d %dK' % (
            out_name, merged['maxp'].numGlyphs, len(cmap), os.path.getsize(target) // 1024))
    finally:
        for path in tmp:
            os.path.exists(path) and os.remove(path)


def patch(out_name: str) -> None:
    """
    `--patch`: إصلاحُ المسافة على الملفّ الملتزَم نفسِه، بلا إعادة بنائه.

    إعادةُ البناء من المقاطع قد تُخرج جداولَ أخرى بإصدارٍ آخر من fontTools —
    والمطلوبُ قاعدةٌ واحدة تُحذف، لا خطٌّ يتبدّل كلُّه.
    """
    target = os.path.join(OUT, out_name)
    font = TTFont(target)
    dropped = keep_spaces(font)
    font.save(target)
    print('%-34s dropped=%d %dK' % (out_name, dropped, os.path.getsize(target) // 1024))


if __name__ == '__main__':
    for name, parts in WEIGHTS.items():
        patch(name) if '--patch' in sys.argv else build(name, parts)
