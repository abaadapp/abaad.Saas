<?php

namespace App\Support;

use RuntimeException;

/**
 * توقيعٌ ثانٍ على مستندٍ موقَّع — يُردّ ولا يُكتب.
 *
 * منفصلٌ عن بقيّة الرفض لأنّ جوابَه مختلف: الطلبُ الأوّل ربما نجح وضاع ردُّه
 * في الشبكة، فصفحةُ العميل تُعيد التحميل لتُريه نسختَه الموقَّعة — لا خطأً.
 */
class AlreadySigned extends RuntimeException {}
