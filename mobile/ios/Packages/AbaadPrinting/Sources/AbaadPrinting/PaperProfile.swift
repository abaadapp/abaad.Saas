import Foundation

/*
 * عرضُ الورق ← عرضُ الطباعة بالنقاط (203 نقطة في البوصة) — §4.6.
 *
 * هذا المكانُ الوحيد الذي يُعرَّف فيه التحويل في iOS: 58 ← 384، و80 ← 576،
 * وأيُّ قيمةٍ أخرى (ومنها null) ← 576.
 */
public struct PaperProfile: Equatable, Sendable {
    /// العرضُ الاسميّ بالمليمتر كما يُعرض على صفحة الاختبار
    public let millimetres: Int
    /// عرضُ الطباعة بالنقاط = عرضُ البتماب
    public let dots: Int

    public static let mm58 = PaperProfile(millimetres: 58, dots: 384)
    public static let mm80 = PaperProfile(millimetres: 80, dots: 576)
    public static let `default` = mm80

    public static func forPaperWidth(_ width: Int?) -> PaperProfile {
        width == 58 ? .mm58 : .default
    }

    /// كما تصل في JSON: عددٌ صحيح، وما سواه (null، نصّ، منطقيّ، كسر) ← الافتراضيّ
    public static func fromJSON(_ value: Any?) -> PaperProfile {
        forPaperWidth(JSONNumbers.integer(value))
    }
}

/// قراءةُ الأعداد من `JSONSerialization` دون أن يمرّ `true` على أنّه 1
enum JSONNumbers {
    static func isBoolean(_ number: NSNumber) -> Bool {
        CFGetTypeID(number) == CFBooleanGetTypeID()
    }

    /// عددٌ صحيحٌ من JSON، أو nil لكلّ ما سواه
    static func integer(_ value: Any?) -> Int? {
        guard let number = value as? NSNumber, !isBoolean(number) else { return nil }
        let double = number.doubleValue
        guard double.isFinite, double.rounded(.towardZero) == double else { return nil }
        return Int(exactly: double)
    }
}
