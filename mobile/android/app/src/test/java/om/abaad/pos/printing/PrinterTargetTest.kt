package om.abaad.pos.printing

import org.junit.Assert.assertEquals
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test
import java.net.InetAddress
import java.net.UnknownHostException
import java.util.Locale

class PrinterTargetTest {

    @Test
    fun everyAddressVectorIsClassified() {
        val cases = Fixtures.vectors.getJSONObject("address").getJSONArray("cases")
        assertTrue("no address vectors", cases.length() > 0)
        for (i in 0 until cases.length()) {
            val vector = cases.getJSONArray(i)
            val input = vector.getString(0)
            val expected = vector.getString(1)
            assertEquals("address '$input'", expected, PrinterAddress.classify(input).name.lowercase(Locale.ROOT))
        }
    }

    @Test
    fun everyPortVectorIsAcceptedOrRefused() {
        val port = Fixtures.vectors.getJSONObject("port")
        assertEquals(port.getInt("default"), PrinterPort.DEFAULT)

        val valid = port.getJSONArray("valid")
        for (i in 0 until valid.length()) {
            val vector = valid.getJSONArray(i)
            val input = Fixtures.plain(vector.get(0))
            assertEquals("port $input", vector.getInt(1), PrinterPort.effective(input))
        }

        val invalid = port.getJSONArray("invalid")
        for (i in 0 until invalid.length()) {
            val input = Fixtures.plain(invalid.get(i))
            assertNull("port $input (${input?.javaClass?.simpleName})", PrinterPort.effective(input))
        }
    }

    @Test
    fun portAcceptsOnlyIntegralNumbersWhateverTheirJsonType() {
        assertEquals(9100, PrinterPort.effective(9100L))
        assertEquals(9100, PrinterPort.effective(9100.0))
        assertEquals(9100, PrinterPort.effective(java.math.BigDecimal("9100.0")))
        assertNull(PrinterPort.effective(9100.5))
        assertNull(PrinterPort.effective(Double.NaN))
        assertNull(PrinterPort.effective(false))
        assertNull(PrinterPort.effective(Long.MAX_VALUE))
    }

    @Test
    fun validationFollowsTheProtocolOrder() {
        // الاتّصال قبل العنوان، والعنوان قبل المنفذ
        assertCode(ErrorCode.UNSUPPORTED_CONNECTION, PrinterTarget.validate("usb", "8.8.8.8", "x", 80))
        assertCode(ErrorCode.UNSUPPORTED_CONNECTION, PrinterTarget.validate(null, "192.168.1.50", 9100, 80))
        assertCode(ErrorCode.INVALID_PRINTER_ADDRESS, PrinterTarget.validate("network", "8.8.8.8", "x", 80))
        assertCode(ErrorCode.INVALID_PRINTER_ADDRESS, PrinterTarget.validate("network", 1921681, 9100, 80))
        assertCode(ErrorCode.INVALID_PORT, PrinterTarget.validate("network", "192.168.1.50", "9100", 80))

        val check = PrinterTarget.validate("network", " PRINTER.local ", null, 58)
        assertTrue(check is PrinterCheck.Valid)
        val printer = (check as PrinterCheck.Valid).printer
        assertEquals("printer.local", printer.host)
        assertEquals(AddressKind.MDNS, printer.kind)
        assertEquals(9100, printer.port)
        assertEquals(384, printer.dots)
        assertEquals("printer.local:9100", printer.endpoint)
    }

    @Test
    fun literalAddressesResolveWithoutLookup() {
        val printer = valid("192.168.1.50", 9101)
        val resolved = PrinterAddress.resolve(printer) { throw AssertionError("no DNS for a literal") }
        assertTrue(resolved is PrinterAddress.Resolution.Ok)
        assertEquals("192.168.1.50", (resolved as PrinterAddress.Resolution.Ok).address.hostAddress)

        val v6 = PrinterAddress.resolve(valid("fd12:3456::1", null)) { throw AssertionError("no DNS for a literal") }
        assertTrue(v6 is PrinterAddress.Resolution.Ok)
    }

    @Test
    fun mdnsNamesMustResolveToPrivateAddressesOnly() {
        val printer = valid("printer.local", null)
        val lan = InetAddress.getByAddress(byteArrayOf(192.toByte(), 168.toByte(), 1, 5))
        val publicDns = InetAddress.getByAddress(byteArrayOf(8, 8, 8, 8))

        val ok = PrinterAddress.resolve(printer) { arrayOf(lan) }
        assertTrue(ok is PrinterAddress.Resolution.Ok)
        assertEquals(lan, (ok as PrinterAddress.Resolution.Ok).address)

        val mixed = PrinterAddress.resolve(printer) { arrayOf(lan, publicDns) }
        assertCode(ErrorCode.INVALID_PRINTER_ADDRESS, mixed)

        val unknown = PrinterAddress.resolve(printer) { throw UnknownHostException("printer.local") }
        assertCode(ErrorCode.PRINTER_UNREACHABLE, unknown)

        val empty = PrinterAddress.resolve(printer) { emptyArray() }
        assertCode(ErrorCode.PRINTER_UNREACHABLE, empty)
    }

    private fun valid(address: String, port: Any?): ValidatedPrinter {
        val check = PrinterTarget.validate("network", address, port, null)
        assertTrue("$address should be valid", check is PrinterCheck.Valid)
        return (check as PrinterCheck.Valid).printer
    }

    private fun assertCode(expected: ErrorCode, check: PrinterCheck) {
        assertTrue("expected $expected", check is PrinterCheck.Invalid)
        assertEquals(expected, (check as PrinterCheck.Invalid).code)
    }

    private fun assertCode(expected: ErrorCode, resolution: PrinterAddress.Resolution) {
        assertTrue("expected $expected", resolution is PrinterAddress.Resolution.Failed)
        assertEquals(expected, (resolution as PrinterAddress.Resolution.Failed).code)
    }
}
