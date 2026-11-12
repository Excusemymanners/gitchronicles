"""keys.py [base_url] — keyboard pass + Ask-button-vs-text check. Prints facts."""
import sys
from playwright.sync_api import sync_playwright
base = next((a for a in sys.argv[1:] if a.startswith('http')), 'http://127.0.0.1:8063')
R = 1
ACTIVE = "(()=>{const a=document.activeElement;return a?(a.tagName.toLowerCase()+(a.getAttribute('aria-label')?'['+a.getAttribute('aria-label')+']':'')+' \"'+(a.innerText||a.value||'').trim().slice(0,25)+'\"'):'none'})()"
OVER = """() => {
  const b = [...document.querySelectorAll('button')].find(x => (x.getAttribute('aria-keyshortcuts')||'').includes('Control+I'));
  if (!b) return 'no ask button';
  const r = b.getBoundingClientRect(); const hits = [];
  const w = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT); let n;
  while ((n = w.nextNode())) { if (!n.textContent.trim() || b.contains(n.parentElement)) continue;
    const rg = document.createRange(); rg.selectNodeContents(n);
    for (const q of rg.getClientRects()) { if (q.width < 2) continue;
      if (Math.min(q.right, r.right) - Math.max(q.left, r.left) > 2 && Math.min(q.bottom, r.bottom) - Math.max(q.top, r.top) > 2) {
        let clip = null; for (let e = n.parentElement; e; e = e.parentElement) { const o = getComputedStyle(e).overflowY; if (o === 'auto' || o === 'scroll' || o === 'hidden') { const c = e.getBoundingClientRect(); const ix = (Math.min(q.bottom, r.bottom) + Math.max(q.top, r.top)) / 2; if (ix > c.bottom || ix < c.top) { clip = true; break; } } }
        if (!clip) { hits.push(n.textContent.trim().slice(0, 25)); break; } } } }
  return hits.length;
}"""
with sync_playwright() as p:
    br = p.chromium.launch(channel='chrome', headless=True)
    pg = br.new_context(viewport={'width': 1440, 'height': 900}).new_page()
    pg.goto(f'{base}/repositories/{R}', wait_until='networkidle')
    seq = []
    for _ in range(14):
        pg.keyboard.press('Tab'); seq.append(pg.evaluate(ACTIVE))
    print('TAB overview:', ' > '.join(seq))
    pg.keyboard.press('Control+k'); pg.wait_for_timeout(300)
    print('palette open, focus:', pg.evaluate(ACTIVE), 'dialog count', pg.locator('[role=dialog]').count())
    pg.keyboard.type('over'); pg.wait_for_timeout(200)
    print('options:', pg.locator('[role=option]').count())
    pg.keyboard.press('ArrowDown'); pg.keyboard.press('Enter'); pg.wait_for_timeout(800)
    print('after Enter url:', pg.url, 'palette open:', pg.locator('[role=dialog]').count())
    pg.keyboard.press('Control+k'); pg.wait_for_timeout(200); pg.keyboard.press('Escape'); pg.wait_for_timeout(200)
    print('after Esc palette:', pg.locator('[role=dialog]').count(), 'focus', pg.evaluate(ACTIVE))
    pg.goto(f'{base}/repositories/{R}/docs/app-http', wait_until='networkidle')
    seq = []
    for _ in range(14):
        pg.keyboard.press('Tab'); seq.append(pg.evaluate(ACTIVE))
    print('TAB docs:', ' > '.join(seq))
    pg.goto(f'{base}/repositories/{R}/docs/app-http', wait_until='networkidle')
    pg.keyboard.press('Control+i'); pg.wait_for_timeout(500)
    print('ask panel open:', pg.locator('[role=dialog]:visible, aside:visible [role=dialog]').count(), 'focus', pg.evaluate(ACTIVE), 'inside:', pg.evaluate("!!document.activeElement.closest('[role=dialog],aside,[aria-labelledby]')"))
    pg.keyboard.press('Escape'); pg.wait_for_timeout(500)
    print('after Esc focus:', pg.evaluate(ACTIVE))
    pg.goto(f'{base}/repositories/{R}/diagrams', wait_until='networkidle')
    for key in ['Tab', 'Tab', 'Tab', 'Tab', 'ArrowRight', 'ArrowDown', '+', '-', '0', 'Escape', 'Enter']:
        pg.keyboard.press(key); pg.wait_for_timeout(120)
    print('diagrams after keys, focus:', pg.evaluate(ACTIVE), 'console-free')
    # The delete dialog only exists for a signed-in owner; as a guest it must not be offered at all.
    pg.goto(f'{base}/repositories/{R}', wait_until='networkidle')
    print('delete button visible to a guest:', pg.get_by_role('button', name=__import__('re').compile('delete|șterge', 2)).count())
    for w in (390, 768, 1024, 1279, 1440):
        c = br.new_context(viewport={'width': w, 'height': 800}); q = c.new_page()
        for path in (f'/repositories/{R}/docs/app-http', f'/repositories/{R}/docs/overview', f'/repositories/{R}/diagrams', f'/repositories/{R}/timeline'):
            q.goto(base + path, wait_until='networkidle'); total = q.evaluate("document.querySelector('main')?.scrollHeight||0"); worst = 0; at = None
            top = 0
            while top < total + 800:
                q.evaluate(f"(()=>{{const m=document.querySelector('main').parentElement; m.scrollTop={top}; window.scrollTo(0,{top});}})()"); q.wait_for_timeout(60)
                h = q.evaluate(OVER)
                if h == 'no ask button': worst = h; break
                if h > worst: worst, at = h, top
                top += 400
            print(f'ask-vs-text w={w} {path}: worst overlapping text nodes={worst} at scroll {at}')
        c.close()
    br.close()
