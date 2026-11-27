<!doctype html>
<html>
<head>
<meta charset="utf-8">
<meta name="robots" content="noindex, nofollow">
<title></title>
</head>
<body>
{{--
    جسرُ السلّة القديمة — انظر `StoreOldCartBridgeController`.

    تقرأ سلّةَ هذا العنوان وترسلها إلى نطاق المتجر وحده، ثمّ لا شيء:
    لا تكتب ولا تمحو، ولا تُرسل إن فُتحت وحدها خارج إطار.
--}}
<script>
(function () {
    if (window.parent === window) return;
    var items = [];
    try { items = JSON.parse(localStorage.getItem(@json($key)) || '[]'); } catch (e) { items = []; }
    window.parent.postMessage({ type: 'abaad-old-cart', items: Array.isArray(items) ? items : [] }, @json($to));
})();
</script>
</body>
</html>
