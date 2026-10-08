/*
 * ملاحظةُ المنتج وملاحظاتُ الطلب في RIBBON — نصٌّ حرٌّ بالإنجليزيّة وحدها.
 *
 * والملفُّ يُضمَّن في القالب كما هو، ويستورده Vitest — انظر
 * `ribbon-gallery.js` لِمَ ملفٌّ لا سطورٌ في القالب.
 *
 * العنوانُ ورسالةُ الخطأ بلغة الموقع (`RibbonTexts`)، والنصُّ نفسُه بالإنجليزيّة.
 * والقاعدةُ هي قاعدةُ الخادم حرفًا (`NotesAndEdits::NOT_ENGLISH`): حرفٌ غيرُ
 * A-Z، أو شيءٌ من الكتابة العربيّة، يُردّ. والخادمُ يحرسها ولو تُجووزت هنا.
 *
 * `take()` تعطي النصَّ مقصوصَ الأطراف، أو `''` لخانةٍ فارغة، أو `null` لنصٍّ
 * مردود — وحينها يُقال الخطأ تحت الخانة ولا يدخل شيءٌ السلّة.
 */
window.RBNotes = (function () {
    // و`Script_Extensions` لا `Script`: الفاصلةُ العربيّة «،» مشتركةٌ في الثانية،
    // وPCRE في الخادم تقرأ `\p{Arabic}` بامتداداتها — فالقاعدتان واحدة
    var NOT_ENGLISH = /[^\P{L}A-Za-z]|\p{Script_Extensions=Arabic}/u;

    function english(text) {
        return !NOT_ENGLISH.test(text);
    }

    function mount(field, error, message, max) {
        function say(text) {
            if (!error) return;
            error.textContent = text || '';
            error.hidden = !text;
        }

        if (field) field.addEventListener('input', function () { say(''); });

        return {
            take: function () {
                var text = field ? field.value.trim() : '';
                if (text === '') { say(''); return ''; }
                if (!english(text) || (max && text.length > max)) {
                    say(message);
                    if (field.focus) field.focus();

                    return null;
                }
                say('');

                return text;
            },
            clear: function () { if (field) field.value = ''; say(''); },
        };
    }

    return { english: english, mount: mount };
})();
