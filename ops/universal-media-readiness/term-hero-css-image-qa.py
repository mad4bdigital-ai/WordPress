from playwright.sync_api import sync_playwright
import json
cases=[('wayfa','https://wayfatours.com/destination/africa-containent/egypt/beni-suef/'),('source','https://tours.dev.mad4b.com/destination/kontinent-afrika/agypten/beni-suef/')]
with sync_playwright() as pw:
 b=pw.chromium.launch(headless=True,executable_path='/root/.cache/ms-playwright/chromium-1194/chrome-linux/chrome',args=['--no-sandbox','--disable-dev-shm-usage'])
 out=[]
 for site,url in cases:
  p=b.new_page(viewport={'width':1440,'height':900});errors=[]
  p.on('pageerror',lambda e:errors.append(str(e)[:120]))
  r=p.goto(url+'?mad4b_surface_qa=20261110',wait_until='domcontentloaded',timeout=50000)
  p.wait_for_timeout(1100)
  d=p.evaluate('''async () => {
    let x=document.querySelector('.elementor-element-9716866');
    if(!x)return {present:false};
    let s=getComputedStyle(x),box=x.getBoundingClientRect(),bg=s.backgroundImage;
    let url=bg.match(/url\\(["']?(.*?)["']?\\)/)?.[1]||'';
    let im=await new Promise(resolve=>{let a=new Image();a.onload=()=>resolve({loaded:true,width:a.naturalWidth,height:a.naturalHeight});a.onerror=()=>resolve({loaded:false});a.src=url;});
    return {present:true,background: url,cssBackground: bg.slice(0,150),boundingBox:{width:Math.round(box.width),height:Math.round(box.height),top:Math.round(box.top)},display:s.display,visibility:s.visibility,opacity:s.opacity,image:im,creditVisible:document.body.innerText.includes('Shaimaa Ahmed Saleh')};
  }''')
  out.append({'site':site,'status':r.status if r else None,'result':d,'errors':errors})
  p.close()
 b.close()
print(json.dumps(out,ensure_ascii=False))
