import { render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import InstalledAppHome from '@/Components/InstalledAppHome';
import { isInstalledApp } from '@/lib/installed-app';

/**
 * التطبيقُ المثبَّت لا شريطَ متصفّحٍ فيه — فعلى الهاتف زرُّ «الرئيسية» يعيد إلى `/app`.
 *
 * ولا يُرسم في المتصفّح العاديّ (له زرُّ رجوعه)، ولا على الآيباد والحاسوب
 * (رئيسيّتُهما اللوحة وقائمتُها ظاهرة).
 */

function displayMode(mode: 'browser' | 'standalone' | 'fullscreen') {
    vi.stubGlobal('matchMedia', (query: string) => ({ matches: query === `(display-mode: ${mode})`, media: query }));
}

function onScreen(width: number, height: number) {
    Object.defineProperty(window, 'screen', { configurable: true, value: { width, height } });
}

afterEach(() => {
    vi.unstubAllGlobals();
    delete (window as { screen?: unknown }).screen;
    delete (navigator as { standalone?: unknown }).standalone;
});

describe('the installed app finds its way home', () => {
    it('knows it runs installed — by display mode, or by Safari\'s own flag on iPhone', () => {
        displayMode('browser');
        expect(isInstalledApp()).toBe(false);

        displayMode('standalone');
        expect(isInstalledApp()).toBe(true);

        displayMode('fullscreen');
        expect(isInstalledApp()).toBe(true);

        displayMode('browser');
        Object.defineProperty(navigator, 'standalone', { configurable: true, value: true });
        expect(isInstalledApp()).toBe(true);
    });

    it('puts a home button on the installed app on a phone, leading to /app', () => {
        displayMode('standalone');
        onScreen(390, 844);
        render(<InstalledAppHome />);

        expect(screen.getByRole('link', { name: 'الرئيسية' })).toHaveAttribute('href', '/admin.dashboard.app');
    });

    it('draws nothing in an ordinary browser, nor on an installed iPad or laptop', () => {
        for (const [mode, width, height] of [['browser', 390, 844], ['standalone', 820, 1180], ['standalone', 1440, 900]] as const) {
            displayMode(mode);
            onScreen(width, height);
            const { container, unmount } = render(<InstalledAppHome />);

            expect(container.querySelector('[data-installed-app-home]')).toBeNull();
            unmount();
        }
    });
});
