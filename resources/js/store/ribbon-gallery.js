/*
 * معرضُ صفحة الصنف في RIBBON — مصغّراتٌ تُبدّل الصورةَ الكبيرة.
 *
 * ═══ ولمَ ملفٌّ لا سطورٌ في القالب ═══
 *
 * صفحاتُ RIBBON بلا حزمة Vite، وما داخل `<script>` في بليد لا يبلغه اختبار.
 * فيُكتب هنا ويُضمَّن في الصفحة كما هو (`product.blade.php`)، ويستورده Vitest.
 *
 * ═══ والقاعدة ═══
 *
 * المصغّراتُ أزرارٌ: تُضغط بالفأرة وباللمس، وبـEnter والمسافة من لوحة
 * المفاتيح بلا سطرٍ زائد. والسهمان يمينًا ويسارًا ينقلان بينها — بالترتيب
 * المرئيّ لا بترتيب المستند، فالصفحةُ العربيّة تُقرأ من اليمين. والمختارةُ
 * تُعلَّم (`is-on` و`aria-pressed`).
 */
window.RBGallery = (function () {
    function mount(row, main) {
        if (!row || !main) return null;
        var thumbs = Array.prototype.slice.call(row.querySelectorAll('[data-src]'));

        function pick(i) {
            var b = thumbs[i]; if (!b) return;
            main.src = b.dataset.src;
            thumbs.forEach(function (t, j) {
                t.classList.toggle('is-on', j === i);
                t.setAttribute('aria-pressed', j === i ? 'true' : 'false');
            });
        }

        thumbs.forEach(function (b, i) {
            b.addEventListener('click', function () { pick(i); });
            b.addEventListener('keydown', function (e) {
                if (e.key !== 'ArrowLeft' && e.key !== 'ArrowRight') return;
                var rtl = getComputedStyle(row).direction === 'rtl';
                var step = (e.key === 'ArrowRight') !== rtl ? 1 : -1;
                var next = thumbs[(i + step + thumbs.length) % thumbs.length];
                e.preventDefault();
                next.focus();
                next.click();
            });
        });

        return { pick: pick };
    }

    return { mount: mount };
})();
