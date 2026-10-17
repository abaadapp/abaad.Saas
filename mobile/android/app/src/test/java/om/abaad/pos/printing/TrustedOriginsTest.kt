package om.abaad.pos.printing

import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test

class TrustedOriginsTest {

    @Test
    fun everyReceiptUrlVector() {
        val receipt = Fixtures.vectors.getJSONObject("receiptUrl")
        val list = receipt.getJSONArray("trustedOrigins")
        val configured = StringBuilder()
        for (i in 0 until list.length()) {
            if (i > 0) configured.append(',')
            configured.append(list.getString(i))
        }
        val origins = TrustedOrigins.parse(configured.toString())

        val cases = receipt.getJSONArray("cases")
        assertTrue("no receiptUrl vectors", cases.length() > 0)
        for (i in 0 until cases.length()) {
            val vector = cases.getJSONArray(i)
            val page = vector.getString(0)
            val url = vector.getString(1)
            assertEquals("page=$page receipt=$url", vector.getBoolean(2), origins.isReceiptUrlAllowed(page, url))
        }
    }

    @Test
    fun originsAreNormalizedForComparisonAndWrittenAsTheBrowserDoes() {
        val origins = TrustedOrigins.parse(" https://POS.example.test:443/ , https://app.example.test:8443,http://lan.example.test, nonsense ,")
        assertEquals(
            listOf("https://pos.example.test", "https://app.example.test:8443", "http://lan.example.test"),
            origins.webOrigins,
        )
        assertEquals(origins.webOrigins.toSet(), origins.allowedOriginRules())

        assertTrue(origins.isTrusted("https://pos.example.test"))
        assertTrue(origins.isTrusted("https://pos.example.test/pos/sale?x=1#y"))
        assertTrue(origins.isTrusted("http://lan.example.test:80/"))
        assertFalse(origins.isTrusted("http://pos.example.test"))
        assertFalse(origins.isTrusted("https://app.example.test"))
        assertFalse(origins.isTrusted("https://pos.example.test.evil.test"))
        assertFalse(origins.isTrusted("https://user@pos.example.test/"))
        assertFalse(origins.isTrusted("null"))
        assertFalse(origins.isTrusted(null))

        assertEquals("https://pos.example.test:443", TrustedOrigins.normalizeOrigin("HTTPS://Pos.Example.Test/x"))
        assertEquals("https://app.example.test:8443", TrustedOrigins.normalizeOrigin("https://app.example.test:8443"))
        assertNull(TrustedOrigins.normalizeOrigin("javascript:alert(1)"))
        assertNull(TrustedOrigins.normalizeOrigin("/relative/path"))
    }

    @Test
    fun anEmptyListFallsBackToTheBaseUrlOriginOnly() {
        assertEquals(listOf("https://pos.example.test"), TrustedOrigins.fromConfig("", "https://pos.example.test/login").webOrigins)
        assertEquals(listOf("https://pos.example.test:8443"), TrustedOrigins.fromConfig("  ", "https://pos.example.test:8443").webOrigins)
        // قائمةٌ مكتوبةٌ لا يصحّ منها شيء لا تُستبدل بأصل baseUrl
        assertTrue(TrustedOrigins.fromConfig("pos.example.test", "https://pos.example.test").isEmpty)
        assertTrue(TrustedOrigins.fromConfig("", "").isEmpty)
    }

    @Test
    fun logsKeepThePathOnly() {
        assertEquals("/pos/receipt/INV-1/pdf", PrintLog.pathOf("https://u:p@pos.example.test/pos/receipt/INV-1/pdf?expected_business_id=3#x"))
        assertEquals("/", PrintLog.pathOf("https://pos.example.test?token=secret/abc"))
        assertEquals("-", PrintLog.pathOf(null))
    }
}
