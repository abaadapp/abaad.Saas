import CoreGraphics
import Foundation
import Testing
@testable import AbaadPrinting

@Suite("PDF and test page rendering")
struct RasterTests {
    /*
     * صفحةٌ 200×300 نقطة، ومستطيلٌ أسود في ثلثها الأعلى (y = 200…250 بإحداثيّات
     * PDF، والأصلُ أسفل). على 576 نقطة: المقياسُ 2.88، والارتفاع 864، والمستطيلُ
     * x = 144…432 وصفوفُه من الأعلى 144…288. فلو انقلبت الصورةُ لظهر في الأسفل.
     */
    @Test("PDF page is drawn at 576 dots wide with the right orientation")
    func pdfRaster() throws {
        let pdf = makePDF(pages: [(CGSize(width: 200, height: 300), CGRect(x: 50, y: 200, width: 100, height: 50))])
        let image = try PDFRasterizer.rasterize(pdf, widthDots: 576)

        #expect(image.width == 576)
        #expect(image.height == 864)
        #expect(image.bytesPerRow == 72)

        // داخل المستطيل أسود
        for (x, y) in [(150, 150), (288, 216), (425, 280)] {
            #expect(image.isBlack(x: x, y: y), "inside (\(x), \(y))")
        }
        // الهوامش بيضاء — ومنها أسفلُ الصفحة حيث كان سيقع المستطيلُ لو انقلب
        for (x, y) in [(5, 5), (570, 5), (288, 100), (288, 300), (100, 216), (500, 216), (288, 650), (5, 860)] {
            #expect(!image.isBlack(x: x, y: y), "margin (\(x), \(y))")
        }
        // ولا شيءَ أسود خارج المستطيل مع هامشٍ للتنعيم
        var stray = 0
        for y in 0..<image.height {
            for x in 0..<image.width where image.isBlack(x: x, y: y) {
                if !(142...434).contains(x) || !(142...290).contains(y) { stray += 1 }
            }
        }
        #expect(stray == 0)
    }

    @Test("PDF pages are stacked vertically, and a 58mm paper is 384 dots")
    func stackedPages() throws {
        let pdf = makePDF(pages: [
            (CGSize(width: 100, height: 100), CGRect(x: 0, y: 0, width: 100, height: 10)),
            (CGSize(width: 100, height: 50), CGRect(x: 0, y: 40, width: 100, height: 10)),
        ])
        let image = try PDFRasterizer.rasterize(pdf, widthDots: 384)
        #expect(image.width == 384)
        #expect(image.height == 384 + 192)
        // آخرُ الصفحة الأولى أسود، وأوّلُ الثانية أسود، وبينهما أبيض
        #expect(image.isBlack(x: 192, y: 380))
        #expect(!image.isBlack(x: 192, y: 200))
        #expect(image.isBlack(x: 192, y: 386))
        #expect(!image.isBlack(x: 192, y: 500))
    }

    /*
     * وطولُ الإيصال لم يعد سببًا للرفض.
     *
     * كان يُرفض ما زاد على 40000 صفّ: نحو خمسة أمتار ونصف على 80. والآن تُوصل
     * الصفحاتُ بلا عدد. وما يُرفض صفحةٌ واحدةٌ أطولُ ممّا تسمح به مواصفةُ PDF
     * (200 بوصة) — قبل أن يُرسم منها شيء.
     */
    @Test("a single page beyond the PDF page limit is refused before drawing")
    func pageBeyondTheLimit() throws {
        let pdf = makePDF(pages: [(CGSize(width: 200, height: 14_401), CGRect(x: 0, y: 0, width: 10, height: 10))])
        #expect(throws: PrintingError(.receiptRenderFailed)) {
            try PDFRasterizer.pages(pdf, widthDots: 576)
        }
        let tallest = makePDF(pages: [(CGSize(width: 200, height: 14_400), CGRect(x: 0, y: 0, width: 10, height: 10))])
        #expect(try PDFRasterizer.pages(tallest, widthDots: 576).map(\.height) == [41_472])
    }

    /*
     * إيصالٌ أطولُ من الحدّ القديم (40000 صفّ) يُطبع كلُّه.
     *
     * صفحتان بعرض ورق 80 وارتفاع 10000 وحدة: 25400 صفٍّ لكلٍّ، 50800 معًا. في كلّ
     * صفحةٍ شريطٌ أسود عند أعلاها وآخرُ عند أسفلها، والصفحةُ الثانية أعرضُ شريطًا —
     * فيُعرف من الورق أنّ الصفحتين خرجتا كاملتين وبترتيبهما.
     */
    @Test("a receipt longer than the old 40000-row limit prints whole", .timeLimit(.minutes(2)))
    func longerThanTheOldLimit() throws {
        let width = 80.0 / 25.4 * 72
        let pdf = makePDF(pages: [
            (CGSize(width: width, height: 10_000), CGRect(x: 0, y: 9_990, width: width / 4, height: 10)),
            (CGSize(width: width, height: 10_000), CGRect(x: 0, y: 0, width: width / 2, height: 10_000)),
        ])
        let spool = try ReceiptSpool.make(pdf: pdf, widthDots: 576)
        defer { spool.remove() }
        let printed = try parseEscPos(try spool.payload.allBytes())

        #expect(printed.cuts == 1)
        #expect(printed.commands.last == "cut")
        // الصفحةُ الأولى: شريطٌ أعلاها ثمّ فراغٌ يلمس الوصلة فيُطوى؛ والثانيةُ سوداءُ نصفُها كلُّها
        #expect(printed.rows.count > 25_400 && printed.rows.count < 25_400 + 30 + SeamJoiner.maxSeamBlankRows)
        let firstPage = printed.rows.prefix(20).filter { $0.contains { $0 != 0 } }
        #expect(!firstPage.isEmpty && firstPage.allSatisfy { $0[0] == 0xFF && $0[17] == 0xFF && $0[18] == 0 })
        #expect(printed.rows.suffix(25_400).allSatisfy { $0[35] == 0xFF && $0[36] == 0 })
    }

    @Test("not a PDF → RECEIPT_RENDER_FAILED")
    func notPDF() {
        #expect(throws: PrintingError(.receiptRenderFailed)) {
            try PDFRasterizer.rasterize(Data("<html>login</html>".utf8), widthDots: 576)
        }
    }

    @Test("strips join seamlessly across 2048-row boundaries")
    func strips() throws {
        // 100 × 1000 على 576 ← 5760 صفًّا: ثلاثةُ شرائط
        let pdf = makePDF(pages: [(CGSize(width: 100, height: 1000), CGRect(x: 0, y: 0, width: 50, height: 1000))])
        let image = try PDFRasterizer.rasterize(pdf, widthDots: 576)
        #expect(image.height == 5760)
        for y in [0, 2047, 2048, 4095, 4096, 5759] {
            #expect(image.isBlack(x: 10, y: y), "row \(y) left half")
            #expect(!image.isBlack(x: 500, y: y), "row \(y) right half")
        }
    }

    /*
     * وكلُّ شريطٍ في موضعه من الصفحة: شريطٌ أسود عرضيّ يعبر الحدَّ بين الشريحتين
     * الأولى والثانية (الصفوف 2000…2100)، وما سواه أبيض. فلو أُزيح شريطٌ عن
     * موضعه لظهر الأسودُ في غير مكانه.
     */
    @Test("each strip shows its own part of a tall page")
    func stripPlacement() throws {
        // 576 نقطة لـ100 = 5.76 صفًّا للنقطة: y من الأسفل = (5760 − الصفّ) / 5.76
        let top = (5760.0 - 2100.0) / 5.76
        let bottom = (5760.0 - 2000.0) / 5.76
        let pdf = makePDF(pages: [(CGSize(width: 100, height: 1000), CGRect(x: 0, y: top, width: 100, height: bottom - top))])
        let image = try PDFRasterizer.rasterize(pdf, widthDots: 576)
        #expect(image.height == 5760)
        for y in [2002, 2047, 2048, 2098] {
            #expect(image.isBlack(x: 288, y: y), "row \(y) is inside the band")
        }
        for y in [0, 1000, 1990, 2110, 3000, 4096, 5759] {
            #expect(!image.isBlack(x: 288, y: y), "row \(y) is outside the band")
        }
    }

    @Test("test page on 58mm paper is a non-empty 384-dot raster")
    func testPage58() throws {
        let content = TestPageContent(
            deviceName: "الصندوق 1",
            printerName: "طابعة الكاشير",
            address: "192.168.1.50",
            port: 9100,
            paper: .mm58,
            date: Date(timeIntervalSince1970: 0)
        )
        let image = try TestReceiptRenderer.render(content, timeZone: TimeZone(identifier: "UTC")!)
        #expect(image.width == 384)
        #expect(image.height > 100)
        #expect(image.data.contains { $0 != 0 })
        // وأولُ الصفوف وآخرُها هامشٌ أبيض
        #expect(image.isRowBlank(0))
        #expect(image.isRowBlank(image.height - 1))

        let bytes = EscPosEncoder.encode(image)
        // GS v 0 بعرض 48 بايتًا
        #expect(Array(bytes[0..<8]) == [0x1B, 0x40, 0x1D, 0x76, 0x30, 0x00, 48, 0])
    }

    @Test("test page text follows §6")
    func testPageLines() {
        let content = TestPageContent(
            deviceName: nil,
            printerName: "طابعة الكاشير",
            address: "printer.local",
            port: 9101,
            paper: .mm80,
            date: Date(timeIntervalSince1970: 86_399)
        )
        #expect(TestReceiptRenderer.bodyLines(content, timeZone: TimeZone(identifier: "UTC")!) == [
            "اسم الجهاز: —",
            "الطابعة: طابعة الكاشير",
            "IP: printer.local",
            "Port: 9101",
            "الورق: 80mm (576 نقطة)",
            "التاريخ والوقت: 1970-01-01 23:59:59",
            "إن قرأت هذا فالطابعة جاهزة.",
        ])
    }

    @Test("BitImage trims only trailing blank rows")
    func trimming() {
        let image = BitImage.fromRows(["00000000", "10000000", "00000000"], width: 8)
        let trimmed = image.trimmingTrailingBlank()
        #expect(trimmed.height == 2)
        #expect(trimmed.isBlack(x: 0, y: 1))
        #expect(BitImage.fromRows(["0000", "0000"], width: 4).trimmingTrailingBlank().height == 0)
    }
}
