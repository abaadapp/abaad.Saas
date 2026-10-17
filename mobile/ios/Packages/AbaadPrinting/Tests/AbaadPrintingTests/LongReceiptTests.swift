import CoreGraphics
import Foundation
import PDFKit
import Testing
@testable import AbaadPrinting

/*
 * الإيصالُ الطويل من الـPDF إلى القصّ — على إيصالاتٍ رسمها الخادمُ نفسُه.
 *
 * `mobile/shared/fixtures/receipts/` يكتبها `ALongReceiptPrintsWholeOnOneStripTest`
 * (PHP): طلباتٌ بـ1 و50 و100 و200 و1000 صنف على ورق 58 و80، بأسماءٍ عربيّة
 * وإنجليزيّة طويلة وملاحظاتٍ ورمزٍ وتذييل. وإيصالُ الألف صنف صفحتان.
 *
 * وكلُّ إيصالٍ يمرّ بالمسار كلِّه: `PrintJobRunner` الحقيقيّ يرسمه إلى ملفّ التخزين،
 * و`NetworkPrinterDriver` الحقيقيّ يرسله بـTCP إلى طابعةٍ على 127.0.0.1. ثمّ يُقرأ ما
 * وصل الطابعةَ أمرًا أمرًا (§7):
 *
 *   - صفوفُه هي صفوفُ الصفحات مرسومةً كلٌّ وحدها، بالترتيب، لا ينقص منها صفٌّ فيه
 *     حبر — وفراغُ الوصلة وحده مطويّ (§7 والمتّجهات `seams`).
 *   - وقصٌّ واحد، في آخر المهمّة.
 *   - وأوّلُ صنفٍ وأوسطُه وآخرُه والمجموعُ والرمزُ والتذييل: مواضعُها من الـPDF
 *     (PDFKit) مُسقطةً على الورق — فيها حبرٌ، وبترتيبها.
 */
@Suite("Long receipts end to end")
struct LongReceiptTests {
    static let fixtures: [(mm: Int, items: Int)] = [
        (58, 1), (58, 50), (58, 100), (58, 200), (58, 1000),
        (80, 1), (80, 50), (80, 100), (80, 200), (80, 1000),
    ]

    @Test("every receipt prints whole, in page order, with one cut at the end", .timeLimit(.minutes(5)), arguments: fixtures)
    func printsWhole(mm: Int, items: Int) async throws {
        let url = Vectors.mobileDirectory().appendingPathComponent("shared/fixtures/receipts/receipt-\(mm)mm-\(items).pdf")
        let pdf = try Data(contentsOf: url)
        let paper: PaperProfile = mm == 58 ? .mm58 : .mm80
        let dots = paper.dots

        // المسارُ كلُّه: تنزيلٌ مزيَّف بالـPDF، ثمّ الرسمُ والإرسالُ الحقيقيّان
        let printer = PacedPrinter(pace: .fast)
        let port = try printer.start()
        let runner = PrintJobRunner(fetcher: FakeFetcher(.success(pdf)), driver: NetworkPrinterDriver(), resolver: FakeResolver(answer: nil))
        let target = PrinterTarget(address: .ipv4("127.0.0.1"), displayAddress: "127.0.0.1", port: port, paper: paper)
        let job = BridgeJob(
            id: "long-\(mm)-\(items)",
            kind: .printReceipt(invoice: "INV-\(mm)-\(items)", receiptURL: URL(string: "https://pos.example.test/r.pdf")!, printer: target),
            pageOrigin: "https://pos.example.test:443"
        )
        let result = await runner.run(job, cookies: { _ in [:] }, presenter: nil) { _ in }
        #expect(result.ok, "\(mm)mm · \(items): \(String(describing: result.code))")
        let received = await printer.waitUntilFinished(timeout: 60).received

        // §7: تهيئةٌ مرّة، ثمّ صور، ثمّ تغذيةٌ وقصٌّ مرّةً واحدة في الآخر
        let printed = try parseEscPos(received)
        #expect(printed.commands.first == "init")
        #expect(printed.commands.filter { $0 == "init" }.count == 1)
        #expect(printed.commands.suffix(2) == ["feed 4", "cut"])
        #expect(printed.cuts == 1)
        #expect(printed.bytesPerRow == dots / 8)

        // الصفحاتُ مرسومةً كلٌّ وحدها، ثمّ موصولةً بالقاعدة كما في العقد
        let pages = try PDFRasterizer.pages(pdf, widthDots: dots).map { page in
            try RasterCanvas.render(width: dots, height: page.height) { page.draw(in: $0, width: dots) }
        }
        let reference = Self.join(pages, maxSeamBlank: SeamJoiner.maxSeamBlankRows)
        #expect(printed.rows.count == reference.rows.count, "\(mm)mm · \(items): rows")
        #expect(printed.rows == reference.rows, "\(mm)mm · \(items): every inked row, in order")
        #expect(items < 1000 || pages.count >= 2, "a thousand items is longer than one 2 m page")

        // والنصوصُ في مواضعها من الورق المطبوع، وبترتيبها
        let document = try #require(PDFDocument(data: pdf))
        var markers = ["Item 1 Premium", "Item \((items + 1) / 2) Premium", "Item \(items) Premium"]
        markers = markers.enumerated().filter { !markers.prefix($0.offset).contains($0.element) }.map(\.element)
        markers += ["Subtotal", "Scan to view the invoice", "END OF RECEIPT"]
        var last = -1
        for marker in markers {
            let rows = try #require(Self.rows(of: marker, in: document, pages: pages, reference: reference), "\(marker) not found")
            #expect(rows.contains { !printed.rows[$0].allSatisfy { $0 == 0 } }, "\(mm)mm · \(items): «\(marker)» has ink on paper")
            #expect(rows.lowerBound > last, "\(mm)mm · \(items): «\(marker)» comes after what precedes it")
            last = rows.lowerBound
        }
        let text = (0..<document.pageCount).compactMap { document.page(at: $0)?.string }.joined(separator: "\n")
        #expect(Self.normalised(text).components(separatedBy: "Item Price Qty Total").count == 2, "the items header prints once")
    }

    // MARK: - المرجع

    /*
     * وصلُ الصفحات من نصّ العقد (§7) مكتوبًا هنا وحده — لا من `SeamJoiner`:
     * فراغُ الوصلة ≤ maxSeamBlank، وفراغُ الصفحة يبقى، وفراغُ الآخر يُحذف.
     * و`map[صفحة][صفّ]` موضعُ الصفّ على الورق، أو nil إن طُوي.
     */
    static func join(_ pages: [BitImage], maxSeamBlank: Int) -> (rows: [[UInt8]], map: [[Int?]]) {
        var rows: [[UInt8]] = []
        var map: [[Int?]] = pages.map { [Int?](repeating: nil, count: $0.height) }
        var blank: [(page: Int, row: Int)] = []
        var acrossSeam = false
        for (p, page) in pages.enumerated() {
            if p > 0 { acrossSeam = true }
            for y in 0..<page.height {
                let row = Array(page.data[(y * page.bytesPerRow)..<((y + 1) * page.bytesPerRow)])
                if row.allSatisfy({ $0 == 0 }) {
                    blank.append((p, y))
                    continue
                }
                let keep = acrossSeam ? min(blank.count, maxSeamBlank) : blank.count
                for (i, spot) in blank.enumerated() where i < keep {
                    map[spot.page][spot.row] = rows.count
                    rows.append(row.map { _ in 0 })
                }
                blank = []
                acrossSeam = false
                map[p][y] = rows.count
                rows.append(row)
            }
        }
        return (rows, map)
    }

    /// صفوفُ النصّ على الورق: موضعُه في صفحته (PDFKit) مُسقطًا عبر `map`
    static func rows(of marker: String, in document: PDFDocument, pages: [BitImage], reference: (rows: [[UInt8]], map: [[Int?]])) -> ClosedRange<Int>? {
        for index in 0..<document.pageCount {
            guard let page = document.page(at: index), let raw = page.string else { continue }
            let text = (raw.replacingOccurrences(of: "\u{E28D}", with: " ")) as NSString
            let found = text.range(of: marker)
            guard found.location != NSNotFound else { continue }
            // والمسافةُ في خطّ الإيصال رمزٌ واحد كذلك، فالمواضعُ في النصّين واحدة
            guard let selection = page.selection(for: found) else { return nil }
            let bounds = selection.bounds(for: page)
            guard !bounds.isEmpty else { return nil }
            let box = page.bounds(for: .mediaBox)
            let scale = CGFloat(pages[index].height) / box.height
            let top = max(0, Int(((box.maxY - bounds.maxY) * scale).rounded(.down)))
            let bottom = min(pages[index].height - 1, Int(((box.maxY - bounds.minY) * scale).rounded(.up)))
            let printed = (top...bottom).compactMap { reference.map[index][$0] }
            guard let first = printed.min(), let lastRow = printed.max() else { return nil }
            return first...lastRow
        }
        return nil
    }

    static func normalised(_ text: String) -> String {
        text.replacingOccurrences(of: "\u{E28D}", with: " ")
            .components(separatedBy: .whitespacesAndNewlines).filter { !$0.isEmpty }.joined(separator: " ")
    }
}
