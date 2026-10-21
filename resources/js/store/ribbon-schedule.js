/*
 * أيّامُ التسليم وفتراتُه في إتمام RIBBON — من جدول المتجر، المتاحُ وحدَه.
 *
 * والملفُّ يُضمَّن في القالب كما هو، ويستورده Vitest — انظر `ribbon-gallery.js`
 * لِمَ ملفٌّ لا سطورٌ في القالب.
 *
 * ═══ الصفحةُ تعرض ولا تحسب ═══
 *
 * الخادمُ يرسل لكلّ طريقة استلامٍ أيّامَها المتاحة وفتراتِ كلِّ يوم، مكتوبةً
 * بلغة الصفحة (`DeliverySchedule::forPage`). فلا يُعرض يومٌ مُغلق ولا فترةٌ
 * مضت، ولا تُقرأ ساعةُ المتصفّح في شيء. والخادمُ يحرس الشيءَ نفسَه عند
 * الإتمام ولو أُرسل غيرُ هذا بيده.
 *
 *   - تبديلُ الطريقة (توصيل / استلام) يبدّل القائمة — والاستلامُ قد يكون له جدولُه.
 *   - اليومُ يفتح فتراتِه وحدَها، وفترةٌ واحدةٌ في يومٍ تُختار وحدَها.
 *   - والموعدُ المختارُ يُكتب في ملخّص الطلب.
 *   - فترةٌ قال الخادمُ إنّها لم تعد متاحة تُطرح (`drop`) من يومها وطريقتها
 *     وحدهما، فلا تُختار ثانية — وفترةُ الأيّام الأخرى بالمعرّف نفسِه باقية.
 */
window.RBSchedule = (function () {
    function option(value, text) {
        var o = document.createElement('option');
        o.value = value;
        o.textContent = text;

        return o;
    }

    function mount(form, days, T) {
        var daySel = form.querySelector('[data-rb-day]');
        var slotSel = form.querySelector('[data-rb-slot]');
        var none = form.querySelector('[data-rb-no-days]');
        var when = form.querySelector('[data-rb-when]');
        var whenRow = form.querySelector('[data-rb-when-row]');
        var fulfil = null;

        function list() {
            return (days && days[fulfil]) || [];
        }

        function today() {
            var picked = daySel.value;

            return list().filter(function (d) { return d.date === picked; })[0] || null;
        }

        function paintWhen() {
            var day = today();
            var slot = day ? day.slots.filter(function (s) { return s.id === slotSel.value; })[0] : null;
            var text = day && slot ? day.label + ' · ' + slot.label : '';
            if (when) when.textContent = text;
            if (whenRow) whenRow.style.display = text ? 'flex' : 'none';
        }

        function paintSlots() {
            var keep = slotSel.value;
            var day = today();
            slotSel.innerHTML = '';
            slotSel.appendChild(option('', T.pickSlot));
            (day ? day.slots : []).forEach(function (s) { slotSel.appendChild(option(s.id, s.label)); });
            slotSel.disabled = !day;

            var still = day && day.slots.some(function (s) { return s.id === keep; });
            slotSel.value = still ? keep : (day && day.slots.length === 1 ? day.slots[0].id : '');
            paintWhen();
        }

        function paintDays() {
            var keep = daySel.value;
            daySel.innerHTML = '';
            daySel.appendChild(option('', T.pickDay));
            list().forEach(function (d) { daySel.appendChild(option(d.date, d.label)); });
            daySel.value = list().some(function (d) { return d.date === keep; }) ? keep : '';
            if (none) none.hidden = list().length > 0;
            paintSlots();
        }

        daySel.addEventListener('change', paintSlots);
        slotSel.addEventListener('change', paintWhen);

        return {
            fulfil: function (f) { fulfil = f; paintDays(); },
            payload: function () { return { date: daySel.value, slot: slotSel.value }; },
            /*
             * فترةٌ ردّها الخادم (مضى وقتُها والصفحةُ مفتوحة) تُطرح من يومها
             * وطريقتها كما أُرسلت — `{fulfil, date, slot}` — ويومٌ لم تبقَ فيه
             * فترةٌ يُطرح معها. والمعرّفُ فريدٌ في يومه لا في أسبوعه
             * (`DeliverySchedule::normalize`): «المساء» نفسُه في السبت باقٍ.
             */
            drop: function (sent) {
                var mine = (days && days[sent.fulfil]) || [];
                for (var i = mine.length - 1; i >= 0; i--) {
                    if (mine[i].date !== sent.date) continue;
                    mine[i].slots = mine[i].slots.filter(function (s) { return s.id !== sent.slot; });
                    if (!mine[i].slots.length) mine.splice(i, 1);
                }
                paintDays();
            },
        };
    }

    return { mount: mount };
})();
