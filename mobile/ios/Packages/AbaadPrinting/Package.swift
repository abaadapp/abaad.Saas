// swift-tools-version:5.9
//
// جسرُ الطباعة المباشرة في تطبيق أبعاد — بلا UIKit ولا AppKit، فيُبنى ويُختبر
// على macOS كما يعمل على iOS. والعقدُ في mobile/PROTOCOL.md.
import PackageDescription

let package = Package(
    name: "AbaadPrinting",
    platforms: [.iOS(.v15), .macOS(.v13)],
    products: [
        .library(name: "AbaadPrinting", targets: ["AbaadPrinting"]),
    ],
    targets: [
        .target(name: "AbaadPrinting"),
        .testTarget(name: "AbaadPrintingTests", dependencies: ["AbaadPrinting"]),
    ]
)
