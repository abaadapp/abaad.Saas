<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * تسويةٌ رُفضت بسببٍ يُقال للتاجر — لا بعطبٍ في النظام.
 *
 * ═══ ولمَ صنفٌ خاصٌّ لا `RuntimeException` ═══
 *
 * `QueryException` في لارافيل يرث `PDOException` وهو يرث `RuntimeException`.
 * فمتحكّمٌ يلتقط `RuntimeException` ليعرض سببًا مفهومًا يلتقط معها **كلَّ
 * خطأ قاعدة** — فيقرأ التاجر «SQLSTATE[23000]: Integrity constraint
 * violation…» في موضع الرسالة، ويُخفى العطبُ الحقيقيّ عن السجلّ لأنّه
 * عُولج كأنّه جواب.
 *
 * فالصنفُ يفصل الاثنين: هذا يُعرض، وما عداه يصعد.
 */
class SettlementRefused extends RuntimeException
{
}
