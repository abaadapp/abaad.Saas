import Foundation

/*
 * طابورُ الطابعة — §5: مهمّةٌ واحدة تعمل، وخلفها مهمّتان على الأكثر.
 *
 * طابعةُ الإيصالات تقبل اتّصالًا واحدًا في الغالب، وإيصالان يتداخلان على مقبسين
 * يخرجان ممزوجين أو يُرفض أحدُهما. فتُسلسَل المهامّ. وما زاد على اثنتين ينتظران
 * يُرفض فورًا بـQUEUE_FULL بدل أن يتراكم خلف طابعةٍ معطّلة ثمّ يخرج دفعةً واحدة.
 */
public actor PrintJobQueue {
    public nonisolated let maxWaiting: Int
    private var busy = false
    private var waiters: [CheckedContinuation<Void, Never>] = []

    public init(maxWaiting: Int = 2) {
        self.maxWaiting = maxWaiting
    }

    public var waitingCount: Int { waiters.count }
    public var isBusy: Bool { busy }

    /// nil ← QUEUE_FULL، ولم يُنفَّذ من `work` شيء
    public func submit<T: Sendable>(_ work: @Sendable () async -> T) async -> T? {
        if busy {
            guard waiters.count < maxWaiting else { return nil }
            await withCheckedContinuation { waiters.append($0) }
        } else {
            busy = true
        }

        let value = await work()

        // تُسلَّم الطابعةُ للتالي مباشرةً، فلا يسبقه طلبٌ جديد
        if waiters.isEmpty {
            busy = false
        } else {
            waiters.removeFirst().resume()
        }
        return value
    }
}
