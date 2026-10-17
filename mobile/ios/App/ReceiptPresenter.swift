import AbaadPrinting
import UIKit

/*
 * حوارُ الطباعة في النظام — `openReceipt` و`window.open` لملفّ PDF (§3 و§10).
 *
 * بديلُ «فتح PDF في نافذة»: النوافذُ المنبثقة لا تعمل داخل التطبيق. ويُعيد
 * فورَ عرض الحوار ولا ينتظر إغلاقَه، فالصفحةُ تنتظر الردّ بمهلة.
 */
@MainActor
final class ReceiptPresenter: ReceiptPresenting {
    /// الواجهةُ التي يخرج منها الحوار على iPad
    weak var hostView: UIView?

    func present(pdf: Data, jobName: String) async -> Bool {
        guard UIPrintInteractionController.isPrintingAvailable, UIPrintInteractionController.canPrint(pdf) else {
            return false
        }
        let controller = UIPrintInteractionController.shared
        // حوارٌ واحد في كلّ وقت: الجديدُ يحلّ محلّ القديم
        controller.dismiss(animated: false)

        let info = UIPrintInfo.printInfo()
        info.outputType = .general
        info.jobName = jobName
        controller.printInfo = info
        controller.printingItem = pdf

        if let view = hostView, view.window != nil, view.traitCollection.userInterfaceIdiom == .pad {
            // على iPad يخرج من أعلى الشاشة في المنتصف
            let anchor = CGRect(x: view.bounds.midX, y: view.safeAreaInsets.top + 8, width: 1, height: 1)
            return controller.present(from: anchor, in: view, animated: true, completionHandler: nil)
        }
        return controller.present(animated: true, completionHandler: nil)
    }
}
