<?php

namespace App\Support;

use RuntimeException;

/**
 * رفضُ مرحلةٍ في سند نقلٍ أو طلب تزويد — برسالةٍ تُقرأ على الشاشة.
 *
 * يُرمى داخل المعاملة فيُرجعها كلَّها: لا نصفُ سندٍ يُرسَل ولا نصفُ طلبٍ يُعتمد.
 */
class TransferRefused extends RuntimeException {}
