"""Offline Chromium regression: shared consent UI, without app/backend consumers.

Run: python app/frontend/tests/consent-ui-layout.test.py
Requires the existing host Playwright and chromium-browser; installs nothing.
"""
from pathlib import Path
import json
import unittest

from playwright.sync_api import sync_playwright


ROOT = Path(__file__).resolve().parents[3]


class ConsentLayoutTest(unittest.TestCase):
    def test_choices_footer_and_keyboard(self):
        with sync_playwright() as playwright:
            browser = playwright.chromium.launch(
                executable_path="/usr/bin/chromium-browser", headless=True
            )
            try:
                for surface in ("frontend", "web"):
                    assets = ROOT / "app" / surface / "public" / "consent"
                    script = (assets / "ui.mjs").read_text().replace(
                        "import { ALL, NONE, PURPOSES } from './core.mjs'",
                        "const PURPOSES = ['preferences', 'recovery', 'realtime'];"
                        "const ALL = Object.fromEntries(PURPOSES.map(p => [p, true]));"
                        "const NONE = Object.fromEntries(PURPOSES.map(p => [p, false]));",
                    ).replace("export function mountConsent", "function mountConsent")
                    for lang in ("es", "en"):
                        for width, height in ((320, 640), (1280, 800)):
                            with self.subTest(surface=surface, lang=lang, width=width):
                                page = browser.new_page(viewport={"width": width, "height": height})
                                # No server or remote requests; exercise exact UI/CSS bytes.
                                page.route("**/*", lambda route: route.abort())
                                page.set_content(f'<html lang="{lang}"><head><style>'
                                    + (assets / "consent.css").read_text()
                                    + '</style></head><body><main style="height:1200px">Product</main>'
                                    '<div id="mount"></div><div id="recovery" style="position:fixed;'
                                    'bottom:16px;right:16px;z-index:50;background:#fffbeb;padding:12px">'
                                    '<p>Recuperar cambios</p><button>Recuperar</button>'
                                    '<button>Descartar</button></div></body></html>')
                                page.add_script_tag(content=script + """
                                    let state = null, subscribers = [];
                                    window.choices = [];
                                    mountConsent(document.querySelector('#mount'), {
                                      getState: () => state,
                                      getStorageError: () => false,
                                      setChoice: (purposes, action) => {
                                        window.choices.push({purposes, action});
                                        state = {purposes, action};
                                        subscribers.forEach(fn => fn(state)); return true;
                                      },
                                      subscribe: fn => { subscribers.push(fn); return () => {}; },
                                      onReopen: () => () => {},
                                    });
                                """)
                                try:
                                    geometry = page.evaluate("""() => {
                                      const notice = document.querySelector('.airis-consent-notice');
                                      const n = notice.getBoundingClientRect();
                                      return [...notice.querySelectorAll('button')].map(b => {
                                        const r = b.getBoundingClientRect();
                                        return {text:b.textContent, width:r.width, height:r.height,
                                          visible:r.top >= Math.max(0,n.top) && r.bottom <= Math.min(innerHeight,n.bottom),
                                          hit:document.elementFromPoint(r.x+r.width/2,r.y+r.height/2) === b};
                                      });
                                    }""")
                                    # Gather both defects before asserting, so RED reports D3 and D4.
                                    notice_buttons = page.locator('.airis-consent-notice button')
                                    notice_buttons.nth(1).evaluate('(b) => b.click()')
                                    footer_position = page.locator('.airis-consent-controls').evaluate(
                                        '(e) => getComputedStyle(e).position')
                                    page.evaluate('scrollTo(0,document.body.scrollHeight)')
                                    recovery_clear = page.locator('#recovery button').evaluate_all("""buttons => buttons.every(b => {
                                      const r=b.getBoundingClientRect();
                                      return document.elementFromPoint(r.x+r.width/2,r.y+r.height/2) === b;
                                    })""")
                                    print(json.dumps({"surface":surface,"lang":lang,"viewport":[width,height],
                                        "decisions":geometry,"footer_position":footer_position,
                                        "recovery_clear":recovery_clear}))
                                    self.assertTrue(all(b['visible'] and b['hit'] for b in geometry), 'D3: decisions clipped/occluded')
                                    self.assertAlmostEqual(geometry[0]['width'], geometry[1]['width'], delta=1)
                                    self.assertEqual(geometry[0]['height'], geometry[1]['height'])
                                    self.assertEqual(footer_position, 'static', 'D4: controls must remain in document flow')
                                    self.assertTrue(recovery_clear, 'D4: recovery actions occluded')
                                    # Real browser keyboard traversal, native modal and focus restoration.
                                    reopen = page.locator('.airis-consent-controls button').nth(0)
                                    reopen.focus()
                                    page.keyboard.press('Enter')
                                    dialog = page.locator('dialog')
                                    self.assertTrue(dialog.evaluate('(e) => e.open'))
                                    controls = dialog.locator('button, input, a[href]')
                                    self.assertTrue(controls.first.evaluate('(e) => e === document.activeElement'))
                                    page.keyboard.press('Shift+Tab')
                                    self.assertTrue(controls.last.evaluate('(e) => e === document.activeElement'))
                                    page.keyboard.press('Tab')
                                    self.assertTrue(controls.first.evaluate('(e) => e === document.activeElement'))
                                    page.keyboard.press('Escape')
                                    self.assertTrue(reopen.evaluate('(e) => e === document.activeElement'))
                                    page.keyboard.press('Tab')
                                    page.keyboard.press('Enter')
                                    self.assertEqual(page.evaluate('window.choices.at(-1).action'), 'withdraw')
                                    self.assertFalse(any(page.evaluate('window.choices.at(-1).purposes').values()))
                                finally:
                                    page.close()
            finally:
                browser.close()

    def test_runtime_failure_stays_visible_after_scrolling(self):
        with sync_playwright() as playwright:
            browser = playwright.chromium.launch(
                executable_path="/usr/bin/chromium-browser", headless=True
            )
            try:
                for surface in ("frontend", "web"):
                    assets = ROOT / "app" / surface / "public" / "consent"
                    core = (assets / "core.mjs").read_text().replace("export ", "")
                    ui = (assets / "ui.mjs").read_text().replace(
                        "import { ALL, NONE, PURPOSES } from './core.mjs'", ""
                    ).replace("export function mountConsent", "function mountConsent")
                    for lang in ("es", "en"):
                        for width in (320, 390):
                            with self.subTest(surface=surface, lang=lang, width=width):
                                page = browser.new_page(viewport={"width": width, "height": 640})
                                page.route("**/*", lambda route: route.abort())
                                try:
                                    page.set_content(f'<html lang="{lang}"><style>'
                                        + (assets / "consent.css").read_text()
                                        + '</style><body><div id="mount"></div></body></html>')
                                    # Real engine/UI; only storage and time scheduling are controlled.
                                    page.add_script_tag(content=core + ui + """
                                      const data = new Map(); window.fail = '';
                                      const storage = {
                                        get length() { return data.size }, key: i => [...data.keys()][i],
                                        getItem: k => {
                                          if (window.fail === 'read' && k === 'p9_drafts_1') throw Error('synthetic');
                                          return data.get(k) ?? null;
                                        },
                                        setItem: (k,v) => {
                                          if (window.fail === 'write') throw Error('synthetic');
                                          data.set(k,v);
                                        }, removeItem: k => data.delete(k),
                                      };
                                      window.api = createConsent({ now: () => Date.now(), storage: () => storage,
                                        setTimeout: () => 0, clearTimeout() {}, listen: () => () => {}, clearSidebar() {} });
                                      mountConsent(document.querySelector('#mount'), window.api);
                                    """)
                                    page.locator('.airis-consent-notice .airis-consent-content').evaluate('(e) => e.scrollTop = e.scrollHeight')
                                    page.evaluate("api.setChoice(ALL, 'accept'); window.fail = 'write'; api.write('recovery', 'p9_drafts_1', 'synthetic')")
                                    self.assert_alert_visible(page, '.airis-consent-notice')
                                    page.evaluate("window.fail = ''; api.setChoice(ALL, 'accept'); api.reopen()")
                                    page.locator('dialog .airis-consent-content').evaluate('(e) => e.scrollTop = e.scrollHeight')
                                    page.evaluate("window.fail = 'read'; api.read('recovery', 'p9_drafts_1')")
                                    self.assert_alert_visible(page, 'dialog')
                                    self.assertTrue(page.locator('dialog').evaluate('(e) => e.open'))
                                    self.assertIsNone(page.evaluate('api.getState()'))
                                finally:
                                    page.close()
            finally:
                browser.close()

    def assert_alert_visible(self, page, container):
        geometry = page.locator(container + ' [role=alert]').evaluate("""e => {
          const r = e.getBoundingClientRect(), p = e.parentElement.getBoundingClientRect();
          return {visible: !e.hidden && r.top >= Math.max(0,p.top) && r.bottom <= Math.min(innerHeight,p.bottom),
            hit: document.elementFromPoint(r.x+r.width/2,r.y+r.height/2) === e};
        }""")
        self.assertTrue(geometry['visible'] and geometry['hit'], 'R2: failure alert clipped/occluded after scrolling')


if __name__ == '__main__':
    unittest.main()
