<?php

namespace App\Support;

use RuntimeException;

/** تسميةُ طلبٍ لإشعارٍ دائن رُدّت — ورسالتُها تُقال على حقل الطلب (`CreditNoteOrders`) */
final class CreditNoteOrderRefused extends RuntimeException {}
