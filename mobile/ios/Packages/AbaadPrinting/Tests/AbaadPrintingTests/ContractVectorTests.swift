import Foundation
import Testing
@testable import AbaadPrinting

/*
 * كلُّ متّجهٍ في printing-vectors.json — هي نفسُها التي يمرّ بها Android.
 * فإن اختلفت المنصّتان في بايتٍ أو عنوانٍ أو منفذ، سقط أحدُهما هنا.
 */
@Suite("Shared contract vectors")
struct ContractVectorTests {
    @Test("escpos: every case byte for byte")
    func escposVectors() throws {
        let cases = try #require(try Vectors.section("escpos")["cases"] as? [[String: Any]])
        #expect(cases.count >= 7)
        for vector in cases {
            let name = try #require(vector["name"] as? String)
            let width = try #require(vector["width"] as? Int)
            let rows = try #require(vector["rows"] as? [String])
            let options = try #require(vector["options"] as? [String: Any])
            let expected = try #require(vector["hex"] as? String)

            let image = BitImage.fromRows(rows, width: width)
            let bytes = EscPosEncoder.encode(image, options: EscPosOptions(
                bandHeight: try #require(options["bandHeight"] as? Int),
                feedLines: try #require(options["feedLines"] as? Int),
                cut: try #require(options["cut"] as? Bool),
                trimTrailingBlank: try #require(options["trimTrailingBlank"] as? Bool)
            ))
            #expect(hex(bytes) == expected, "escpos case \(name)")
        }
    }

    @Test("escpos: receipt defaults are 128 / 4 / cut / trim")
    func receiptDefaults() {
        #expect(EscPosOptions.receipt == EscPosOptions(bandHeight: 128, feedLines: 4, cut: true, trimTrailingBlank: true))
    }

    @Test("threshold: luminance list and RGB luminance")
    func thresholdVectors() throws {
        let section = try Vectors.section("threshold")
        #expect(try #require(section["threshold"] as? Int) == Monochrome.threshold)

        let luminance = try #require(section["luminance"] as? [Int])
        let luminanceBlack = try #require(section["luminanceBlack"] as? [Int])
        #expect(luminance.count == luminanceBlack.count && !luminance.isEmpty)
        for (value, black) in zip(luminance, luminanceBlack) {
            #expect(Monochrome.isBlack(luminance: value) == (black == 1), "luminance \(value)")
        }

        let rgb = try #require(section["rgb"] as? [[Int]])
        let rgbLuminance = try #require(section["rgbLuminance"] as? [Int])
        let rgbBlack = try #require(section["rgbBlack"] as? [Int])
        #expect(rgb.count == rgbLuminance.count && rgb.count == rgbBlack.count && !rgb.isEmpty)
        for (index, pixel) in rgb.enumerated() {
            let value = Monochrome.luminance(r: pixel[0], g: pixel[1], b: pixel[2])
            #expect(value == rgbLuminance[index], "rgb \(pixel)")
            #expect(Monochrome.isBlack(luminance: value) == (rgbBlack[index] == 1), "rgb \(pixel)")

            // والمسارُ الفعليّ: بكسل RGBA واحد ← نقطة
            let bytes: [UInt8] = [UInt8(pixel[0]), UInt8(pixel[1]), UInt8(pixel[2]), 255]
            let image = bytes.withUnsafeBytes { BitImage.fromRGBA(width: 1, height: 1, bytesPerRow: 4, pixels: $0) }
            #expect(image.isBlack(x: 0, y: 0) == (rgbBlack[index] == 1), "rgba \(pixel)")
        }
    }

    @Test("paper: width in mm to printable dots")
    func paperVectors() throws {
        let section = try Vectors.section("paper")
        #expect(try #require(section["default"] as? Int) == PaperProfile.default.dots)
        let cases = try #require(section["cases"] as? [[Any]])
        #expect(cases.count >= 5)
        for pair in cases {
            let dots = try #require(pair[1] as? Int)
            #expect(PaperProfile.fromJSON(pair[0]).dots == dots, "paper \(pair[0])")
        }
        // ما ليس عددًا صحيحًا ← الافتراضيّ
        #expect(PaperProfile.fromJSON(try jsonValue("\"58\"")).dots == 576)
        #expect(PaperProfile.fromJSON(try jsonValue("true")).dots == 576)
        #expect(PaperProfile.fromJSON(nil).dots == 576)
    }

    @Test("address: every case")
    func addressVectors() throws {
        let cases = try #require(try Vectors.section("address")["cases"] as? [[String]])
        #expect(cases.count >= 33)
        for pair in cases {
            #expect(PrinterAddress.kind(of: pair[0]).rawValue == pair[1], "address \"\(pair[0])\"")
        }
    }

    @Test("address: classified values are trimmed and normalised")
    func addressValues() {
        #expect(PrinterAddress.parse(" 192.168.1.50 ") == .ipv4("192.168.1.50"))
        #expect(PrinterAddress.parse("PRINTER.LOCAL") == .mdns("printer.local"))
        #expect(PrinterAddress.parse("FD12:3456::1") == .ipv6("fd12:3456::1"))
        // inet_aton كان سيقبلها — هنا تُرفض
        #expect(PrinterAddress.parse("0xC0.168.1.1") == nil)
        #expect(PrinterAddress.parse("192.168.1.50.") == nil)
        #expect(PrinterAddress.parse("fe80::1%en0") == nil)
        #expect(PrinterAddress.parse("printer_1.local") == nil)
        #expect(PrinterAddress.parse("printer-.local") == nil)
    }

    @Test("port: valid values as they arrive from JSON")
    func validPorts() throws {
        let section = try Vectors.section("port")
        #expect(try #require(section["default"] as? Int) == PrinterPort.defaultRaw)
        let valid = try #require(section["valid"] as? [[Any]])
        #expect(valid.count >= 5)
        for pair in valid {
            let expected = try #require(pair[1] as? Int)
            #expect(PrinterPort.parse(pair[0]) == expected, "port \(pair[0])")
        }
        // الغائب كالـnull
        #expect(PrinterPort.parse(nil) == 9100)
    }

    @Test("port: invalid values are refused")
    func invalidPorts() throws {
        let invalid = try #require(try Vectors.section("port")["invalid"] as? [Any])
        #expect(invalid.count >= 6)
        for value in invalid {
            #expect(PrinterPort.parse(value) == nil, "port \(value)")
        }
        // من نصّ JSON صريح، كما يصل من الصفحة
        for literal in ["9100.5", "true", "false", "\"9100\"", "0", "65536", "-1", "[9100]", "{}"] {
            #expect(PrinterPort.parse(try jsonValue(literal)) == nil, "port \(literal)")
        }
        #expect(PrinterPort.parse(try jsonValue("null")) == 9100)
        #expect(PrinterPort.parse(try jsonValue("9100")) == 9100)
    }

    @Test("receiptUrl: every case")
    func receiptURLVectors() throws {
        let section = try Vectors.section("receiptUrl")
        let trusted = TrustedOrigins(try #require(section["trustedOrigins"] as? [String]))
        let cases = try #require(section["cases"] as? [[Any]])
        #expect(cases.count >= 14)
        for item in cases {
            let page = try #require(item[0] as? String)
            let url = try #require(item[1] as? String)
            let expected = try #require(item[2] as? Bool)
            #expect(trusted.isReceiptURLAllowed(pageOrigin: page, receiptURL: url) == expected, "receiptUrl \(page) ← \(url)")
        }
    }
}
