<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * المستخدم يُحذف — لم يكن إلا أن يُعطَّل.
 *
 * كل ما سواه نال حذفًا قابلًا للتراجع: المنتج والمصروف والفرع والعميل.
 * أما المستخدم فزرُّه الوحيد «تعطيل»، فيبقى الصفُّ الخطأ في القائمة أبدًا —
 * حسابُ تجربةٍ اسمه حرفان، أو موظّفٌ أُدخل مرّتين — يُقرأ في كل عدٍّ ويُبحث
 * فيه في كل بحث، ولا سبيل إلى إزالته إلا بفتح قاعدة البيانات.
 *
 * والحذف ناعم: من حُذف خطأً يُستعاد، ومن حُذف عمدًا يخرج من كل شاشة —
 * والمصادقة نفسها تستثنيه، فلا يدخل بعد حذفه ولو كانت جلسته مفتوحة.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};
