<?php

namespace App\Support;

use RuntimeException;

/**
 * التعديلُ صحيحٌ ومأذونٌ لصاحبه، لكنّه ينتظر شخصًا ثانيًا — لا خطأ.
 *
 * يرميه `CustomerInvoiceEdits::apply` قبل أوّل كتابة، فيحوّله المتحكّمُ
 * طلبًا معلّقًا (`FinancialCorrections::request`) بدل أن يعرضه رفضًا.
 */
final class NeedsApproval extends RuntimeException {}
