package om.abaad.pos

import om.abaad.pos.printing.TrustedOrigins

/**
 * إعدادُ البناء — `abaad.baseUrl` و`abaad.trustedOrigins` (انظر app/build.gradle.kts).
 * لا نطاقَ مكتوبًا في الكود: البناءُ بلا إعدادٍ يُخرج تطبيقًا يقول ذلك على الشاشة.
 */
object AppConfig {
    val baseUrl: String = BuildConfig.ABAAD_BASE_URL.trim()

    /** القائمةُ من الإعداد، وإن تُركت فارغةً فأصلُ `baseUrl` وحده */
    val trustedOrigins: TrustedOrigins = TrustedOrigins.fromConfig(BuildConfig.ABAAD_TRUSTED_ORIGINS, baseUrl)

    val hasBaseUrl: Boolean get() = baseUrl.isNotEmpty()

    /** الصفحةُ الأولى نفسُها يجب أن تكون على أصلٍ موثوق، وإلّا خرج كلُّ تنقّلٍ منها إلى المتصفّح */
    val isBaseUrlTrusted: Boolean get() = hasBaseUrl && trustedOrigins.isTrusted(baseUrl)
}
