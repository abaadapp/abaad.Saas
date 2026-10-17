import java.util.Properties

plugins {
    id("com.android.application")
    id("org.jetbrains.kotlin.android")
}

// عنوانُ أبعاد وأصولُه الموثوقة لا تُكتب في الكود: خاصيّةُ Gradle أوّلًا
// (-Pabaad.baseUrl=…)، ثمّ mobile/android/local.properties، وإلّا فارغة.
// والبناءُ ينجح فارغًا — والتطبيقُ حينها يعرض شاشةَ «لم يُضبط».
val localAbaadProperties = Properties().apply {
    val file = rootProject.file("local.properties")
    if (file.isFile) {
        file.inputStream().use { load(it) }
    }
}

val abaadBaseUrl: String = (
    providers.gradleProperty("abaad.baseUrl").orNull
        ?: localAbaadProperties.getProperty("abaad.baseUrl")
        ?: ""
    ).trim()

val abaadTrustedOrigins: String = (
    providers.gradleProperty("abaad.trustedOrigins").orNull
        ?: localAbaadProperties.getProperty("abaad.trustedOrigins")
        ?: ""
    ).trim()

fun javaStringLiteral(value: String): String =
    "\"" + value
        .replace("\\", "\\\\")
        .replace("\"", "\\\"")
        .replace("\n", "")
        .replace("\r", "") + "\""

android {
    namespace = "om.abaad.pos"
    compileSdk = 35

    defaultConfig {
        applicationId = "om.abaad.pos"
        minSdk = 26
        targetSdk = 35
        versionCode = 1
        versionName = "1.0.0"

        buildConfigField("String", "ABAAD_BASE_URL", javaStringLiteral(abaadBaseUrl))
        buildConfigField("String", "ABAAD_TRUSTED_ORIGINS", javaStringLiteral(abaadTrustedOrigins))
    }

    compileOptions {
        sourceCompatibility = JavaVersion.VERSION_17
        targetCompatibility = JavaVersion.VERSION_17
    }

    kotlinOptions {
        jvmTarget = "17"
    }

    buildFeatures {
        buildConfig = true
    }

    sourceSets {
        getByName("main") {
            // الـshim المشترك يُشحن كما هو: assets/abaad-printer-shim.js
            assets.srcDir("../../shared/js")
        }
    }
}

dependencies {
    implementation("androidx.core:core-ktx:1.15.0")
    implementation("androidx.appcompat:appcompat:1.7.0")
    implementation("androidx.webkit:webkit:1.12.1")

    testImplementation("junit:junit:4.13.2")
    // org.json في android.jar مجرّدُ هياكل في اختبارات JVM
    testImplementation("org.json:json:20240303")
}
