import AbaadPrinting
import Foundation

/*
 * إعدادُ البناء كما وصل إلى Info.plist — لا عنوانَ مفترضًا في الشيفرة.
 *
 *   AbaadBaseURL        = $(ABAAD_BASE_URL)         ← ما يفتحه التطبيق، https وحده
 *   AbaadTrustedOrigins = $(ABAAD_TRUSTED_ORIGINS)  ← مفصولةً بفواصل، وفارغةً ← أصلُ العنوان
 *
 * والقيمتان من Config/Abaad.local.xcconfig أو من CI. فإن غابتا قال التطبيقُ ذلك
 * ولم يفتح شيئًا.
 */
struct AppConfig {
    let baseURL: URL?
    let trustedOrigins: TrustedOrigins

    static func load(from bundle: Bundle = .main) -> AppConfig {
        let info = bundle.infoDictionary ?? [:]
        let baseURL = httpsURL(info["AbaadBaseURL"] as? String)
        return AppConfig(
            baseURL: baseURL,
            trustedOrigins: TrustedOrigins(configured: info["AbaadTrustedOrigins"] as? String, fallbackBaseURL: baseURL)
        )
    }

    /// العنوانُ الأساسيّ صالحٌ إن كان https بمضيف، وأصلُه ضمن الموثوقة
    var isUsable: Bool {
        guard let baseURL else { return false }
        return trustedOrigins.isTrusted(url: baseURL)
    }

    private static func httpsURL(_ raw: String?) -> URL? {
        let value = raw?.trimmingCharacters(in: .whitespacesAndNewlines) ?? ""
        guard !value.isEmpty, let url = URL(string: value),
              url.scheme?.lowercased() == "https", let host = url.host, !host.isEmpty
        else { return nil }
        return url
    }
}
