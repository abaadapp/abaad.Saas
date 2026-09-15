import { render } from '@testing-library/react';
import type { ReactElement } from 'react';
import storeFixture from './fixtures/store.json';
import profileFixture from './fixtures/profile.json';
import type { DocSection, SiteDocument } from '@/site/types';

/**
 * الاختبارات تُقرأ من مستندٍ حقيقيّ لا من مستندٍ مكتوبٍ هنا.
 *
 * `tests/fixtures/*.json` مولَّدةٌ من أبعاد نفسها (`DumpContractFixtureTest`)
 * بتشغيل `Builder::create` ثمّ `Publisher::publish` ثمّ `Preview::resolve`.
 * ومستندٌ أكتبه بيدي يوافق فهمي للعقد لا العقدَ نفسه — فيبقى أخضرَ بينما
 * الموقع الحقيقيّ لا يُرسم.
 */

export const store = storeFixture as unknown as SiteDocument;
export const profile = profileFixture as unknown as SiteDocument;

/** نسخةٌ من المستند بعد تعديل — الأصل لا يُمسّ بين اختبارٍ وآخر */
export function clone(doc: SiteDocument): SiteDocument {
    return JSON.parse(JSON.stringify(doc)) as SiteDocument;
}

/** الصفحة الرئيسية من مستند */
export function homeOf(doc: SiteDocument) {
    return doc.pages.find((p) => p.is_home) ?? doc.pages[0];
}

/** مستندٌ بصفحةٍ واحدة فيها هذه الأقسام وحدها */
export function withSections(doc: SiteDocument, sections: DocSection[]): SiteDocument {
    const next = clone(doc);
    const home = homeOf(next);

    home.sections = sections;
    next.pages = [home];

    return next;
}

/** قسمٌ من المستند بنوعه — لبناء حالاتٍ من أقسامٍ حقيقية */
export function sectionOf(doc: SiteDocument, type: string): DocSection {
    for (const page of doc.pages) {
        const found = page.sections.find((s) => s.type === type);

        if (found) return JSON.parse(JSON.stringify(found)) as DocSection;
    }

    throw new Error(`لا قسم من نوع ${type} في المستند`);
}

export function draw(node: ReactElement) {
    return render(node);
}
