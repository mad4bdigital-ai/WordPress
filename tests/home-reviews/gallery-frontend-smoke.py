"""Optional browser smoke for Wayfatours gallery. No screenshot/browser packages installed by this test.

Usage: python3 tests/home-reviews/gallery-frontend-smoke.py
Prerequisite: existing Playwright + Chromium on the authorized test host.
"""
import asyncio
import json
from playwright.async_api import async_playwright

CHECKS=[
 ('tour_desktop','https://wayfatours.com/tours-and-activities/trips-to-giza-pyramids/',1440,900,1,3),
 ('home_tablet','https://wayfatours.com/',900,950,1,2),
 ('tour_mobile_retina','https://wayfatours.com/tours-and-activities/trips-to-giza-pyramids/',390,844,2,1),
 ('home_es','https://wayfatours.com/es/',1440,900,1,3),
]

async def verify(browser,name,url,width,height,dpr,count):
    page=await browser.new_page(viewport={'width':width,'height':height},
                                device_scale_factor=dpr,ignore_https_errors=True,
                                is_mobile=width<600,has_touch=width<600)
    try:
        response=await page.goto(url,wait_until='load',timeout=60000)
        before=await page.evaluate('''() => {
            let w=document.querySelector('.elementor-element-d3b8065');
            if(!w)return {present:false,requested:0};
            let names=[...w.querySelectorAll('img[data-src]')].map(x=>x.dataset.src);
            let matched=performance.getEntriesByType('resource').filter(r=>names.includes(r.name));
            return {present:true,requested:matched.length};
        }''')
        assert response.status==200 and before['present'] and before['requested']==0,(name,before)
        gallery=page.locator('.elementor-element-d3b8065').first
        await gallery.scroll_into_view_if_needed(timeout=15000)
        await page.wait_for_timeout(1000)
        result=await page.evaluate('''() => {
            let g=document.querySelector('.elementor-element-d3b8065');
            let s=g.querySelector('.uael-img-carousel-wrap');
            let pics=[...g.querySelectorAll('.slick-slide.slick-active img')];
            return {css:!!document.getElementById('mad4b-reviews-gallery-layout-v1'),
                autoplay:JSON.parse(s.getAttribute('data-image_carousel')).autoplay,
                images:pics.map(i=>({url:i.currentSrc,fit:getComputedStyle(i).objectFit,
                    height:Math.round(i.getBoundingClientRect().height)})),
                slidesHeight:Math.round(g.querySelector('.slick-list').getBoundingClientRect().height),
                galleryWidth:Math.round(g.getBoundingClientRect().width)
            };
        }''')
        assert result['css'] and not result['autoplay'] and len(result['images'])==count,(name,result)
        assert result['galleryWidth']>=min(width-130,670),(name,result)
        assert 320<=result['slidesHeight']<=365,(name,result)
        # Browser may density-correct naturalWidth under srcset on DPR=2.
        # Verify actual chosen URL is not a 150/225/300px medium thumbnail.
        for image in result['images']:
            assert image['fit']=='contain',image
            assert not any(x in image['url'] for x in ['-150x','-225x','-300x']),image
        print(json.dumps({'case':name,'status':'PASS','slides':count,'galleryWidth':result['galleryWidth'],
            'initialGalleryRequests':before['requested'],'selectedFiles':[i['url'].rsplit('/',1)[-1] for i in result['images']]},
            ensure_ascii=False))
    finally:await page.close()

async def main():
    async with async_playwright() as p:
        browser=await p.chromium.launch(headless=True,args=['--no-sandbox','--disable-dev-shm-usage','--disable-gpu'])
        try:
            for check in CHECKS:await verify(browser,*check)
        finally:await browser.close()

if __name__=='__main__':asyncio.run(main())
