import React from 'react';

/**
 * الشاشة البيضاء ليست عطلًا يُبلَّغ عنه — هي عطلٌ يُخفي نفسه.
 *
 * خطأٌ واحد في مكوّنٍ واحد يُسقط شجرة React كلّها، فلا يبقى في الصفحة شيء:
 * لا رسالة، ولا شريط، ولا زرّ رجوع. والتاجر يرى بياضًا فيظنّ النظام تعطّل
 * كلّه، ويتّصل ليقول «الشاشة بيضاء» — وهي أقلّ جملةٍ تفيد في التشخيص.
 *
 * فيُمسك الخطأ هنا ويُعرض نصّه. الرسالة للمستخدم أولًا: أعد التحميل. وتحتها
 * سطرٌ تقنيّ يُنسخ ويُرسل — هو الفرق بين بلاغٍ يُصلَح وبلاغٍ يُخمَّن.
 */
interface State {
    error: Error | null;
}

export default class ErrorBoundary extends React.Component<{ children: React.ReactNode }, State> {
    state: State = { error: null };

    static getDerivedStateFromError(error: Error): State {
        return { error };
    }

    componentDidCatch(error: Error, info: React.ErrorInfo): void {
        // يبقى في سجلّ المتصفّح كاملًا بأثره — المعروض أعلاه مختصرٌ للقراءة
        console.error('[abaad] تعطّلت الشاشة:', error, info.componentStack);
    }

    render(): React.ReactNode {
        const { error } = this.state;

        if (!error) {
            return this.props.children;
        }

        return (
            <div
                dir="rtl"
                style={{
                    // أنماطٌ مباشرة لا أصنافٌ من الورقة: العطل قد يكون في التنسيق نفسه،
                    // فشاشةُ الخطأ لا تعتمد على ما لا تثق به
                    minHeight: '100dvh',
                    display: 'flex',
                    alignItems: 'center',
                    justifyContent: 'center',
                    padding: '24px',
                    background: '#fff',
                    fontFamily: 'inherit',
                }}
            >
                <div style={{ maxWidth: '520px', textAlign: 'center' }}>
                    <p style={{ fontSize: '18px', fontWeight: 700, color: '#111' }}>
                        تعذّر عرض هذه الشاشة
                    </p>
                    <p style={{ marginTop: '8px', fontSize: '14px', color: '#6b7280' }}>
                        أعد تحميل الصفحة. وإن تكرّر، أرسل السطر التالي للدعم.
                    </p>

                    <pre
                        style={{
                            marginTop: '16px',
                            padding: '12px',
                            background: '#f7f7f7',
                            borderRadius: '10px',
                            fontSize: '12px',
                            color: '#b91c1c',
                            textAlign: 'start',
                            direction: 'ltr',
                            whiteSpace: 'pre-wrap',
                            wordBreak: 'break-word',
                        }}
                    >
                        {error.message}
                    </pre>

                    <div style={{ marginTop: '16px', display: 'flex', gap: '8px', justifyContent: 'center' }}>
                        <button
                            type="button"
                            onClick={() => window.location.reload()}
                            style={{
                                padding: '10px 20px', borderRadius: '9999px', border: 'none',
                                background: '#111', color: '#fff', fontSize: '14px', cursor: 'pointer',
                            }}
                        >
                            إعادة تحميل
                        </button>
                        <button
                            type="button"
                            onClick={() => { window.location.href = '/'; }}
                            style={{
                                padding: '10px 20px', borderRadius: '9999px',
                                border: '1px solid #dcdcdc', background: '#fff',
                                color: '#111', fontSize: '14px', cursor: 'pointer',
                            }}
                        >
                            الرئيسية
                        </button>
                    </div>
                </div>
            </div>
        );
    }
}
