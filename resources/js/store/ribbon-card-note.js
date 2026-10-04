/*
 * نصُّ كرت الهدية على صفحة صنفه في RIBBON — اختياريّ.
 *
 * والملفُّ يُضمَّن في `product.blade.php` كما هو، ويستورده Vitest — انظر
 * `ribbon-gallery.js` لِمَ ملفٌّ لا سطورٌ في القالب.
 *
 * `take()` تعطي النصَّ مقصوصَ الأطراف — أو `''` لكرتٍ بلا رسالة. ولا تمنع
 * السلّة أبدًا: كرتٌ بلا رسالةٍ يُشترى ويُحاسَب كما هو، والخادمُ يقبله كذلك
 * (`GiftCardProduct::settle`). و`''` لا يدخل هويّةَ البند: كرتان بلا رسالةٍ
 * بندٌ واحدٌ بكمّيّة اثنين (`ribbon-cart-lines.js`).
 */
window.RBCardNote = (function () {
    function mount(text) {
        return {
            take: function () {
                return text ? text.value.trim() : '';
            },
        };
    }

    return { mount: mount };
})();
