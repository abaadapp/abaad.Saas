import CoreGraphics
import CoreText
import Foundation

/*
 * صفحةُ اختبار الطابعة — §6.
 *
 * يرسمها التطبيقُ بنفسه بخطّ النظام وباتّجاه RTL على عرض الطباعة، ثمّ تمرّ
 * بالعتبة والترميز نفسَيهما كالإيصال. فما يخرج من الطابعة دليلٌ على المسار
 * كلِّه: العنوان، والمنفذ، وعرض الورق، والترميز.
 *
 *               أبعاد
 *           اختبار الطابعة
 *   اسم الجهاز: <deviceName>
 *   الطابعة: <printerName>
 *   IP: <address>
 *   Port: <effectivePort>
 *   الورق: 80mm (576 نقطة)
 *   التاريخ والوقت: yyyy-MM-dd HH:mm:ss   ← وقت الجهاز
 *   إن قرأت هذا فالطابعة جاهزة.
 */
public struct TestPageContent: Equatable, Sendable {
    public var deviceName: String?
    public var printerName: String?
    public var address: String
    public var port: Int
    public var paper: PaperProfile
    public var date: Date

    public init(deviceName: String?, printerName: String?, address: String, port: Int, paper: PaperProfile, date: Date) {
        self.deviceName = deviceName
        self.printerName = printerName
        self.address = address
        self.port = port
        self.paper = paper
        self.date = date
    }
}

public enum TestReceiptRenderer {
    public static let title = "أبعاد"
    public static let subtitle = "اختبار الطابعة"

    /// الأسطرُ تحت العنوانين بالترتيب — نصًّا، ليُختبر المحتوى دون رسم
    public static func bodyLines(_ content: TestPageContent, timeZone: TimeZone = .current) -> [String] {
        [
            "اسم الجهاز: \(display(content.deviceName))",
            "الطابعة: \(display(content.printerName))",
            "IP: \(content.address)",
            "Port: \(content.port)",
            "الورق: \(content.paper.millimetres)mm (\(content.paper.dots) نقطة)",
            "التاريخ والوقت: \(timestamp(content.date, timeZone: timeZone))",
            "إن قرأت هذا فالطابعة جاهزة.",
        ]
    }

    /// وقتُ الجهاز بالأرقام اللاتينيّة أيًّا كانت لغتُه
    public static func timestamp(_ date: Date, timeZone: TimeZone = .current) -> String {
        let formatter = DateFormatter()
        formatter.locale = Locale(identifier: "en_US_POSIX")
        formatter.calendar = Calendar(identifier: .gregorian)
        formatter.timeZone = timeZone
        formatter.dateFormat = "yyyy-MM-dd HH:mm:ss"
        return formatter.string(from: date)
    }

    public static func render(_ content: TestPageContent, timeZone: TimeZone = .current) throws -> BitImage {
        let width = content.paper.dots
        let margin = CGFloat(max(8, width / 24))
        let bodySize = CGFloat(width) / 18
        let text = NSMutableAttributedString()

        append(title, to: text, font: font(size: bodySize * 1.8, bold: true), alignment: .center, spacingAfter: bodySize * 0.2)
        append(subtitle, to: text, font: font(size: bodySize * 1.15, bold: true), alignment: .center, spacingAfter: bodySize * 0.8)
        let body = font(size: bodySize, bold: false)
        for line in bodyLines(content, timeZone: timeZone) {
            append(line, to: text, font: body, alignment: .right, spacingAfter: bodySize * 0.35)
        }
        // لا سطرَ فارغ في الآخر: الفاصلُ بين الفقرات وحدها
        if text.length > 0, text.string.hasSuffix("\n") {
            text.deleteCharacters(in: NSRange(location: text.length - 1, length: 1))
        }

        let framesetter = CTFramesetterCreateWithAttributedString(text as CFAttributedString)
        let textWidth = CGFloat(width) - margin * 2
        let suggested = CTFramesetterSuggestFrameSizeWithConstraints(
            framesetter, CFRange(location: 0, length: 0), nil,
            CGSize(width: textWidth, height: .greatestFiniteMagnitude), nil
        )
        let frameHeight = ceil(suggested.height) + 2
        let height = Int(frameHeight + margin * 2)

        return try RasterCanvas.render(width: width, height: height) { context in
            // سياقُ CoreGraphics محورُه الصاديّ إلى الأعلى كما يفترض CoreText، فلا قلب.
            // والإطارُ يبدأ من أعلى المستطيل وينزل.
            context.textMatrix = .identity
            let path = CGPath(rect: CGRect(x: margin, y: margin, width: textWidth, height: frameHeight), transform: nil)
            let frame = CTFramesetterCreateFrame(framesetter, CFRange(location: 0, length: 0), path, nil)
            CTFrameDraw(frame, context)
        }
    }

    // MARK: - داخليّ

    private static func display(_ value: String?) -> String {
        let trimmed = value?.trimmingCharacters(in: .whitespacesAndNewlines) ?? ""
        return trimmed.isEmpty ? "—" : trimmed
    }

    private static func font(size: CGFloat, bold: Bool) -> CTFont {
        CTFontCreateUIFontForLanguage(bold ? .emphasizedSystem : .system, size, nil)
            ?? CTFontCreateWithName((bold ? "Helvetica-Bold" : "Helvetica") as CFString, size, nil)
    }

    private static func append(
        _ line: String,
        to text: NSMutableAttributedString,
        font: CTFont,
        alignment: CTTextAlignment,
        spacingAfter: CGFloat
    ) {
        let attributes: [NSAttributedString.Key: Any] = [
            NSAttributedString.Key(kCTFontAttributeName as String): font,
            NSAttributedString.Key(kCTForegroundColorAttributeName as String): CGColor(red: 0, green: 0, blue: 0, alpha: 1),
            NSAttributedString.Key(kCTParagraphStyleAttributeName as String): paragraphStyle(alignment: alignment, spacingAfter: spacingAfter),
        ]
        text.append(NSAttributedString(string: line + "\n", attributes: attributes))
    }

    /// فقرةٌ من اليمين إلى اليسار: العربيّةُ تتّصل حروفها، والأرقامُ والـIP تبقى بترتيبها
    private static func paragraphStyle(alignment: CTTextAlignment, spacingAfter: CGFloat) -> CTParagraphStyle {
        var alignment = alignment
        var direction = CTWritingDirection.rightToLeft
        var spacing = spacingAfter
        return withUnsafeBytes(of: &alignment) { alignmentBytes in
            withUnsafeBytes(of: &direction) { directionBytes in
                withUnsafeBytes(of: &spacing) { spacingBytes in
                    let settings = [
                        CTParagraphStyleSetting(spec: .alignment, valueSize: alignmentBytes.count, value: alignmentBytes.baseAddress!),
                        CTParagraphStyleSetting(spec: .baseWritingDirection, valueSize: directionBytes.count, value: directionBytes.baseAddress!),
                        CTParagraphStyleSetting(spec: .paragraphSpacing, valueSize: spacingBytes.count, value: spacingBytes.baseAddress!),
                    ]
                    return CTParagraphStyleCreate(settings, settings.count)
                }
            }
        }
    }
}
