/*
 * الشروطُ والأحكام قبل تأكيد الطلب في RIBBON — نصُّ صاحب المتجر وخانةُ الموافقة.
 *
 * والملفُّ يُضمَّن في القالب كما هو، ويستورده Vitest — انظر `ribbon-gallery.js`.
 *
 *   - النصُّ كاملًا في صندوقٍ يُمرَّر، وزرٌّ يبسطه كلَّه أو يطويه — لا تُخفى فقرة.
 *   - الخانةُ غيرُ محدّدةٍ حتّى يضغطها الزبونُ بنفسه.
 *   - لا يُرسَل الطلبُ بلا موافقة (`ready`)، ويُرسَل معه رقمُ النسخة التي رآها.
 *   - شروطٌ حدّثها صاحبُها والصفحةُ مفتوحة يعيدها الخادم (`TermsChanged`)، فتحلّ
 *     محلَّ القديمة وتُفرغ الخانة (`replace`): لا يُوافَق على نصٍّ لم يُقرأ.
 *
 * والخادمُ يحرس الشيءَ نفسَه ولو عُطّل هذا الملفّ (`StoreTerms::check`).
 */
window.RBTerms = (function () {
    function mount(box, T) {
        var text = box.querySelector('[data-rb-terms-text]');
        var check = box.querySelector('[data-rb-terms-accept]');
        var toggle = box.querySelector('[data-rb-terms-toggle]');
        var error = box.querySelector('[data-err="terms"]');
        var version = box.getAttribute('data-version') || '';
        var open = false;

        function say(msg) {
            if (error) error.textContent = msg || '';
        }

        if (toggle) {
            toggle.addEventListener('click', function () {
                open = !open;
                text.style.maxHeight = open ? 'none' : '';
                toggle.textContent = open ? T.termsLess : T.termsMore;
                toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
            });
        }

        check.addEventListener('change', function () { if (check.checked) say(''); });

        return {
            payload: function () {
                return { terms_version: version, terms_accept: check.checked === true };
            },
            ready: function () {
                if (check.checked) return true;
                say(T.termsNeeded);
                if (check.focus) check.focus();

                return false;
            },
            replace: function (terms, message) {
                version = String(terms.id);
                box.setAttribute('data-version', version);
                text.textContent = terms.text;
                check.checked = false;
                say(message || '');
            },
        };
    }

    return { mount: mount };
})();
