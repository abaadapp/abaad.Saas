import CoreGraphics
import Foundation

/*
 * رسمُ الـPDF على عرض الطباعة — §5 الخطوتان 2 و3.
 *
 * كلُّ صفحةٍ تُرسم بعرض الطباعة بالنقاط، وارتفاعُها بالنسبة نفسها
 * (round(h × dots / w))، ثمّ تُوصل الصفحاتُ رأسيًّا (`SeamJoiner`). والخلفيّةُ تُملأ
 * بالأبيض قبل الرسم: الـPDF قد يكون شفّافًا، والشفّافُ في بتماب RGBX أسود.
 *
 * ويجري على خيطٍ غير الرئيسيّ: المستدعي (`PrintJobRunner`) يضمن ذلك.
 */
public enum PDFRasterizer {
    /*
     * أطولُ صفحةٍ تُقبل: حدُّ مواصفة PDF (14400 وحدة = 200 بوصة).
     *
     * وليس حدًّا لطول الإيصال: الإيصالُ الأطولُ من مترين صفحاتٌ (`MpdfDriver::strip`)،
     * والصفحاتُ تُوصل بلا عدد. هو حدُّ صفحةٍ واحدةٍ تدّعي ما لا يكون — فتُرفض قبل
     * أن يُرسم منها شيء، لا بعد أن يخرج نصفُها.
     */
    public static let maxPageUnits: CGFloat = 14_400

    /// الصفحاتُ مفحوصةً كلُّها قبل أن يُرسم منها شيء
    static func pages(_ pdf: Data, widthDots: Int) throws -> [RasterPage] {
        guard widthDots > 0,
              let provider = CGDataProvider(data: pdf as CFData),
              let document = CGPDFDocument(provider)
        else { throw PrintingError(.receiptRenderFailed) }

        if document.isEncrypted, !document.isUnlocked {
            _ = document.unlockWithPassword("")
        }
        guard document.isUnlocked, document.numberOfPages > 0 else {
            throw PrintingError(.receiptRenderFailed)
        }

        var pages: [RasterPage] = []
        for index in 1...document.numberOfPages {
            guard let page = document.page(at: index) else { throw PrintingError(.receiptRenderFailed) }
            pages.append(try RasterPage(page: page, widthDots: widthDots))
        }
        return pages
    }

    /*
     * الإيصالُ كلُّه إلى `stream`: صفحةً صفحة، وكلُّ صفحةٍ شرائط (`RasterCanvas`)،
     * والصفحاتُ موصولةٌ بلا فراغٍ بينها (`SeamJoiner`). لا يبقى في الذاكرة إلّا شريط.
     * و`onBand` بعد كلّ شريط — منه تخرج نبضاتُ «rendering» إلى الصفحة.
     */
    public static func encode(_ pdf: Data, widthDots: Int, into stream: EscPosStream, onBand: () -> Void = {}) throws {
        let pages = try pages(pdf, widthDots: widthDots)
        let joiner = SeamJoiner(sink: stream, keepTrailingBlank: !stream.options.trimTrailingBlank)
        for page in pages {
            joiner.beginPage()
            try RasterCanvas.renderStrips(width: widthDots, height: page.height) { context in
                page.draw(in: context, width: widthDots)
            } each: { strip in
                try joiner.append(strip)
                onBand()
            }
        }
        try joiner.finish()
    }

    /// الصفحاتُ مكدّسةً صورةً واحدة — للاختبارات وحدها؛ الطباعةُ تمرّ بـ`encode`
    static func rasterize(_ pdf: Data, widthDots: Int) throws -> BitImage {
        let images = try pages(pdf, widthDots: widthDots).map { page in
            try RasterCanvas.render(width: widthDots, height: page.height) { context in
                page.draw(in: context, width: widthDots)
            }
        }
        return BitImage.stacked(images, width: widthDots)
    }
}

/// صفحةُ PDF بعرض الطباعة: ارتفاعُها بالصفوف، ورسمُها في لوح
struct RasterPage {
    let page: CGPDFPage
    let box: CGRect
    let rotation: Int
    /// أبعادُ الصفحة كما تُعرض — بعد الدوران
    let displayedSize: CGSize
    let height: Int

    init(page: CGPDFPage, widthDots: Int) throws {
        let box = page.getBoxRect(.mediaBox).standardized
        let rotation = ((Int(page.rotationAngle) % 360) + 360) % 360
        let size = rotation == 90 || rotation == 270
            ? CGSize(width: box.height, height: box.width)
            : box.size
        guard size.width > 0, size.height > 0, size.width.isFinite, size.height.isFinite,
              size.height <= PDFRasterizer.maxPageUnits
        else {
            throw PrintingError(.receiptRenderFailed)
        }
        let height = (size.height * CGFloat(widthDots) / size.width).rounded()
        guard height >= 1, height < CGFloat(Int32.max) else {
            throw PrintingError(.receiptRenderFailed)
        }
        self.page = page
        self.box = box
        self.rotation = rotation
        self.displayedSize = size
        self.height = Int(height)
    }

    func draw(in context: CGContext, width: Int) {
        context.saveGState()
        context.scaleBy(x: CGFloat(width) / displayedSize.width, y: CGFloat(height) / displayedSize.height)
        // دورانُ الصفحة (/Rotate) باتّجاه عقارب الساعة كما يعرضها القارئ
        switch rotation {
        case 90:
            context.translateBy(x: 0, y: box.width)
            context.rotate(by: -.pi / 2)
        case 180:
            context.translateBy(x: box.width, y: box.height)
            context.rotate(by: .pi)
        case 270:
            context.translateBy(x: box.height, y: 0)
            context.rotate(by: .pi / 2)
        default:
            break
        }
        context.translateBy(x: -box.minX, y: -box.minY)
        context.clip(to: box)
        context.drawPDFPage(page)
        context.restoreGState()
    }
}

/*
 * لوحٌ أبيض يُرسم عليه ثمّ يُحوَّل أحاديًّا — يتقاسمه الـPDF وصفحةُ الاختبار.
 *
 * يُرسم شرائطَ من 2048 صفًّا لا دفعةً واحدة: صفحةٌ بطول مترين على 576 نقطة
 * بأربعة بايتات للبكسل نحو 33MB، والشريطُ أقلُّ من 5MB. وكلُّ شريطٍ نافذةٌ على
 * الصفحة كلِّها، فيرسم `draw` بإحداثيّات الصفحة كاملةً (الأصلُ أسفلَ اليسار)
 * ولا يدري بالشرائط.
 */
enum RasterCanvas {
    static let stripRows = 2048

    /// اللوحُ كلُّه صورةً واحدة — لصفحة الاختبار والصفحات القصيرة
    static func render(width: Int, height: Int, draw: (CGContext) -> Void) throws -> BitImage {
        var data: [UInt8] = []
        data.reserveCapacity(((width + 7) / 8) * max(0, height))
        try renderStrips(width: width, height: height, draw: draw) { strip in
            data.append(contentsOf: strip.data)
        }
        return BitImage(width: width, height: height, data: data)
    }

    /// شريطًا بعد شريط من الأعلى، ولا يبقى في الذاكرة إلّا الشريطُ الحاليّ
    static func renderStrips(width: Int, height: Int, draw: (CGContext) -> Void, each: (BitImage) throws -> Void) throws {
        guard width > 0, height > 0 else { throw PrintingError(.receiptRenderFailed) }
        let stride = width * 4
        let space = CGColorSpaceCreateDeviceRGB()
        let white = CGColor(red: 1, green: 1, blue: 1, alpha: 1)

        var top = 0
        while top < height {
            let rows = min(stripRows, height - top)
            var pixels = [UInt8](repeating: 255, count: stride * rows)
            let strip: BitImage? = pixels.withUnsafeMutableBytes { buffer -> BitImage? in
                guard let context = CGContext(
                    data: buffer.baseAddress,
                    width: width,
                    height: rows,
                    bitsPerComponent: 8,
                    bytesPerRow: stride,
                    space: space,
                    bitmapInfo: CGImageAlphaInfo.noneSkipLast.rawValue
                ) else { return nil }
                context.setFillColor(white)
                context.fill(CGRect(x: 0, y: 0, width: width, height: rows))
                // صفوفُ هذا الشريط من أعلى الصفحة: [top, top + rows)
                context.translateBy(x: 0, y: -CGFloat(height - top - rows))
                draw(context)
                context.flush()
                return BitImage.fromRGBA(width: width, height: rows, bytesPerRow: stride, pixels: UnsafeRawBufferPointer(buffer))
            }
            guard let strip else { throw PrintingError(.receiptRenderFailed) }
            try each(strip)
            top += rows
        }
    }
}
