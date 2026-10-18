<?php

namespace App\Support;

use RuntimeException;

/**
 * محاولةُ رابطٍ سابقةٌ لا تُعرف نتيجتُها — لا يُعاد الطلبُ وحده بعدها.
 *
 * تُرمى من `PaymentLinks::forInvoice` ليُسأل من طلب: «قد يكون الأوّلُ عند
 * Paymob — أتطلب جديدًا وترسله وحده؟».
 */
final class UncertainPaymentLink extends RuntimeException {}
