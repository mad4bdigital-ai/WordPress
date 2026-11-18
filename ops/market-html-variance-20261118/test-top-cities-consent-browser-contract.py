#!/usr/bin/env python3
"""Static QA regression: consent-aware genuine Swiper pointer testing."""
from pathlib import Path
p=Path(__file__).with_name('test-source-top-cities-canary-browser.js')
d=p.read_text()
def check(label,x):
 if not x:raise SystemExit('FAIL '+label)
 print('PASS',label)
check('browser QA uses exact original staging URL', "https://tours.dev.mad4b.com/" in d)
check('optional consent receipt intercepted in QA browser only',
      "await page.route(" in d and
      "'/mad4b-consent/v1/receipt'" in d and
      'route=>route.fulfill(' in d)
check('QA explicitly rejects all optional analytics/ads before using slider',
      'banner.locator(\'[data-mad4b-choice="reject"]\').click' in d)
check('QA checks consent is hidden before card inspection',
      "await banner.waitFor({state:'hidden'" in d and
      "if(!consentDismissed)throw Error('CONSENT_UI_DID_NOT_CLOSE')" in d)
check('Swiper initialization independently asserted',
      'slider.swiper?.initialized' in d)
check('pointer click is actual browser gesture with navigation interception',
      'await link.click({timeout:9000})' in d and
      'window.__mad4bTermCardClickCount++' in d)
check('keyboard link focus checked',
      'keyboardFocusable=await link.evaluate' in d)
check('CSS and JS regressions retained as browser gates',
      "canary.jsErrorCount>native.jsErrorCount" in d and
      'canary.volatileDcss!==0' in d)
check('tests keep exact native/cache Swiper parity',
      "variant==='bypass_native'" in d and
      "variant==='active_cached'" in d and
      'native.linkDigest!==canary.linkDigest' in d)
check('source site not mutated by test browser',
      'page.addStyleTag(' not in d and
      'page.evaluate(()=>{\n           window.__mad4bTermCardClickCount=0' in d)
print('SOURCE_CONSENT_AWARE_SWIPER_INTERACTION_QA_CONTRACT 10')
