/*
 * نصُّ كرت الهدية على صفحة صنفه في RIBBON — مطلوبٌ قبل السلّة.
 *
 * والملفُّ يُضمَّن في `product.blade.php` كما هو، ويستورده Vitest — انظر
 * `ribbon-gallery.js` لِمَ ملفٌّ لا سطورٌ في القالب.
 *
 * `take()` تعطي النصَّ مقصوصَ الأطراف، أو `null` إن كان فارغًا: فيُقال
 * تحت الخانة، ويُنقل إليها المؤشّر، وتُساق الصفحةُ إليها. والخادمُ يردّ
 * الكرتَ بلا نصٍّ كذلك (`GiftCardProduct`) — الشاشةُ ترشد ولا تحرس.
 */
window.RBCardNote = (function () {
    function mount(text, error) {
        text.addEventListener('input', function () { if (text.value.trim() !== '') error.hidden = true; });

        return {
            take: function () {
                var v = text.value.trim();
                if (v !== '') { error.hidden = true; return v; }
                error.hidden = false;
                text.focus();
                if (text.scrollIntoView) text.scrollIntoView({ block: 'center', behavior: 'smooth' });
                return null;
            },
        };
    }

    return { mount: mount };
})();
