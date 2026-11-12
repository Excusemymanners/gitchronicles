"""walk.py [base_url] [--quick] — walks every page in en/ro x dark/light x 1440/390 and prints facts per page.
Run: /home/pilatluca/.local/share/gitchronicle-dev/venv/bin/python -I walk.py http://127.0.0.1:8063
Repository ids (largest, rich, small) can be set with env QA_REPOS="3,1,2". --quick = only en/dark/1440 + ro/light/390."""
import json, os, re, sys
from playwright.sync_api import sync_playwright

base = next((a for a in sys.argv[1:] if a.startswith('http')), 'http://127.0.0.1:8063')
quick = '--quick' in sys.argv
repos = os.environ.get('QA_REPOS', '3,1,2').split(',')
rich = os.environ.get('QA_RICH', '1')
PAGES = ['/', '/discover', '/login', '/register', '/github.com/octocat/hello-world', '/no-such-page-xyz']
for r in repos:
    b = f'/repositories/{r}'
    PAGES += [b, f'{b}/docs', f'{b}/diagrams', f'{b}/assistant', f'{b}/timeline', f'{b}/media', f'{b}/consulting']
PAGES += [f'/repositories/{rich}/docs/overview', f'/repositories/{rich}/docs/app-http', f'/repositories/{rich}/docs/readme']
PAGES = list(dict.fromkeys(PAGES))

PROBE = r"""
() => {
  const out = {overflow: null, badImg: [], noName: [], clipped: [], overlap: [], contrast: [], text: []};
  const de = document.documentElement;
  if (de.scrollWidth > de.clientWidth + 1) out.overflow = de.scrollWidth + '>' + de.clientWidth;
  const vis = el => { const r = el.getBoundingClientRect(); const s = getComputedStyle(el);
    const cd = el.closest('details:not([open])'); if (cd && !el.closest('summary')) return false;
    return r.width > 0 && r.height > 0 && s.visibility !== 'hidden' && s.display !== 'none' && s.opacity !== '0'; };
  const desc = el => el.tagName.toLowerCase() + (el.id ? '#' + el.id : '') + (typeof el.className === 'string' && el.className ? '.' + el.className.split(/\s+/).slice(0, 2).join('.') : '') + ' "' + (el.textContent || '').trim().replace(/\s+/g, ' ').slice(0, 30) + '"';
  document.querySelectorAll('img').forEach(i => { if (vis(i) && (!i.complete || i.naturalWidth === 0)) out.badImg.push(i.src.slice(-60)); });
  const name = el => (el.getAttribute('aria-label') || el.getAttribute('aria-labelledby') || el.getAttribute('title') || (el.innerText || '').trim() || (el.labels && el.labels.length) || el.querySelector('img[alt]:not([alt=""])') || '');
  document.querySelectorAll('a[href],button,input:not([type=hidden]),select,textarea,[role=button],[role=tab],[tabindex="0"]').forEach(el => {
    if (!vis(el)) return; if (!name(el)) out.noName.push(desc(el)); });
  document.querySelectorAll('h1,h2,h3,button,a,[role=tab],label').forEach(el => {
    if (!vis(el)) return; const s = getComputedStyle(el);
    if (!el.closest('.sr-only') && !el.classList.contains('sr-only') && el.scrollWidth > el.clientWidth + 2 && s.display !== 'inline' && s.textOverflow !== 'ellipsis' && s.overflowX !== 'auto' && s.overflowX !== 'scroll') out.clipped.push(desc(el) + ' ' + el.scrollWidth + '>' + el.clientWidth);
  });
  const boxes = [...document.querySelectorAll('h1,h2,h3,button,a[href],[role=tab],input')].filter(vis);
  for (let i = 0; i < boxes.length; i++) for (let j = i + 1; j < boxes.length; j++) {
    const a = boxes[i], b = boxes[j]; if (a.contains(b) || b.contains(a)) continue;
    const ra = a.getBoundingClientRect(), rb = b.getBoundingClientRect();
    const w = Math.min(ra.right, rb.right) - Math.max(ra.left, rb.left), h = Math.min(ra.bottom, rb.bottom) - Math.max(ra.top, rb.top);
    if (w > 4 && h > 4) { const pa = getComputedStyle(a).position, pb = getComputedStyle(b).position;
      out.overlap.push(desc(a) + ' x ' + desc(b) + (pa === 'fixed' || pb === 'fixed' || pa==='sticky'||pb==='sticky' ? ' [fixed/sticky]' : '')); }
  }
  const cv = document.createElement('canvas').getContext('2d', {willReadFrequently: true});
  const rgba = c => { cv.clearRect(0, 0, 1, 1); cv.fillStyle = '#000'; cv.fillStyle = c; cv.fillRect(0, 0, 1, 1); const d = cv.getImageData(0, 0, 1, 1).data; return [d[0], d[1], d[2], d[3] / 255]; };
  const lum = ([r, g, b]) => { const f = v => { v /= 255; return v <= .03928 ? v / 12.92 : Math.pow((v + .055) / 1.055, 2.4); }; return .2126 * f(r) + .7152 * f(g) + .0722 * f(b); };
  const bgOf = el => { let layers = []; for (let e = el; e; e = e.parentElement) { const s = getComputedStyle(e); if (s.backgroundImage !== 'none') return null; const c = rgba(s.backgroundColor); if (c[3] > 0) { layers.push(c); if (c[3] >= 1) break; } }
    let base = rgba(getComputedStyle(document.body).backgroundColor); if (base[3] < 1) base = [255, 255, 255, 1];
    let cur = layers.length && layers[layers.length-1][3] >= 1 ? layers.pop() : base;
    while (layers.length) { const l = layers.pop(); cur = [0,1,2].map(k => l[k]*l[3] + cur[k]*(1-l[3])).concat([1]); } return cur; };
  const seen = new Set(); const walker = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT);
  let n; let count = 0;
  while ((n = walker.nextNode())) {
    const t = n.textContent.trim(); if (!t) continue; const el = n.parentElement;
    if (!el || ['SCRIPT', 'STYLE', 'NOSCRIPT'].includes(el.tagName) || !vis(el)) continue;
    const gen = !!el.closest('article, .prose, [data-generated], pre, code, svg');
    out.text.push([t.slice(0, 80), gen ? 1 : 0, el.tagName.toLowerCase() + '.' + (typeof el.className === 'string' ? el.className.split(/\s+/)[0] : '')]);
    if (count > 400) continue; count++;
    const s = getComputedStyle(el); const key = s.color + '|' + el.className; if (seen.has(key)) continue; seen.add(key);
    const fg = rgba(s.color), bg = bgOf(el); if (!bg || el.closest('svg, foreignObject')) continue;
    const fgb = [0,1,2].map(k => fg[k]*fg[3] + bg[k]*(1-fg[3]));
    const L1 = lum(fgb), L2 = lum(bg); const ratio = (Math.max(L1, L2) + .05) / (Math.min(L1, L2) + .05);
    const big = parseFloat(s.fontSize) >= 24 || (parseFloat(s.fontSize) >= 18.66 && parseInt(s.fontWeight) >= 700);
    if (ratio < (big ? 3 : 4.5) && !el.closest('[disabled],[aria-disabled=true]')) out.contrast.push(ratio.toFixed(2) + ' ' + desc(el));
  }
  return out;
}
"""
RO_WORDS = re.compile(r"[ăâîșțşţĂÂÎȘȚ]|\b(și|este|sunt|pentru|din|cu|nu|un|despre|mai|care|sau|fișier|depozit|înapoi|căutare)\b", re.I)
EN_WORDS = re.compile(r"\b(the|and|of|to|is|are|for|with|your|this|that|you|from|not|an)\b", re.I)

def lang_leak(texts, lang):
    hits = []
    for t, gen, tag in texts:
        if gen: continue
        if lang == 'en' and RO_WORDS.search(t) and len(t) > 2: hits.append(t)
        if lang == 'ro' and len(EN_WORDS.findall(t)) >= 2: hits.append(t)
    return list(dict.fromkeys(hits))

def switch_lang(page, lang):
    page.goto(base + '/', wait_until='networkidle')
    cur = page.evaluate("document.documentElement.lang")
    if cur.startswith(lang): return True
    page.locator('header button[aria-controls]').first.click()
    page.get_by_role('button' if False else 'menuitem' if False else 'button', name='Română' if lang == 'ro' else 'English').first.click()
    page.wait_for_timeout(800)
    return page.evaluate("document.documentElement.lang").startswith(lang)

def main():
    combos = [(l, t, w) for l in ('en', 'ro') for t in ('dark', 'light') for w in (1440, 390)]
    if quick: combos = [('en', 'dark', 1440), ('ro', 'light', 390)]
    totals = {}
    with sync_playwright() as p:
        br = p.chromium.launch(channel='chrome', headless=True)
        for lang, theme, width in combos:
            ctx = br.new_context(viewport={'width': width, 'height': 900})
            ctx.add_init_script(f"try {{ localStorage.setItem('gitchronicle-theme', '{theme}') }} catch (e) {{}}")
            page = ctx.new_page(); errs = []; failed = []
            page.on('console', lambda m: errs.append(m.text[:120]) if m.type == 'error' else None)
            page.on('pageerror', lambda e: errs.append('PAGEERROR ' + str(e)[:120]))
            page.on('requestfailed', lambda r: failed.append(r.url[-60:]))
            page.on('response', lambda r: failed.append(f'{r.status} {r.url[-60:]}') if r.status >= 400 and 'no-such' not in r.url and '/media/' not in r.url else None)
            ok = switch_lang(page, lang)
            print(f'\n=== {lang} {theme} {width}  (lang switch ok={ok})')
            for path in PAGES:
                errs.clear(); failed.clear()
                try:
                    resp = page.goto(base + path, wait_until='networkidle'); status = resp.status if resp else '?'
                except Exception as e:
                    print(f'{path}: NAVIGATION ERROR {str(e)[:80]}'); continue
                page.wait_for_timeout(500)
                f = page.evaluate(PROBE)
                leak = lang_leak(f['text'], lang)
                line = {'status': status}
                for k, v in (('console', errs[:3]), ('failed', failed[:3]), ('overflow', f['overflow']), ('badImg', f['badImg'][:3]), ('wrongLang', leak[:5]),
                             ('noName', f['noName'][:4]), ('clipped', f['clipped'][:4]), ('overlap', f['overlap'][:4]), ('lowContrast', f['contrast'][:4])):
                    if v: line[k] = v
                    if v: totals[k] = totals.get(k, 0) + 1
                print(path, json.dumps(line, ensure_ascii=False))
            ctx.close()
        br.close()
    print('\nPAGE-VARIANTS WITH FINDINGS PER CATEGORY:', totals)
main()
