import http from 'node:http';
import crypto from 'node:crypto';
import fs from 'node:fs/promises';
import path from 'node:path';

const root = path.resolve(process.env.THREADS_PROFILE_ROOT || './profiles');
const mediaRoot = path.resolve(process.env.THREADS_MEDIA_ROOT || '../storage/app/private/threads-media');
const secret = process.env.THREADS_WORKER_SECRET;
if (!secret || secret.length < 32) throw new Error('THREADS_WORKER_SECRET must contain 32+ characters');
const active = new Map();
const maxConcurrent = Math.max(1, Number(process.env.THREADS_CONCURRENCY || 2));
let running = 0;
export const validUuid = value => typeof value === 'string' && /^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i.test(value);
export const signatureValid = (body, signature, key) => {
  if (typeof signature !== 'string' || !/^[a-f0-9]{64}$/i.test(signature)) return false;
  return crypto.timingSafeEqual(Buffer.from(crypto.createHmac('sha256',key).update(body).digest('hex')),Buffer.from(signature.toLowerCase()));
};
function profile(uuid) { if (!validUuid(uuid)) throw new Error('Invalid UUID'); return path.join(root, uuid); }
function failIfChallenge(page) { return page.getByText(/verify (it|your)|verification required|suspicious activity|confirm your identity/i).first().isVisible({timeout:1000}); }
async function status(page) {
  if (await failIfChallenge(page).catch(()=>false)) return {status:'VERIFICATION_REQUIRED'};
  if (await page.getByRole('button',{name:/log in|sign in/i}).first().isVisible().catch(()=>false)) return {status:'LOGIN_REQUIRED'};
  const ownLink=page.getByRole('link',{name:/^profile$/i}).first();
  const href=await ownLink.getAttribute('href').catch(()=>null);
  if (href) return {status:'READY',username:href.match(/^\/@([^/]+)/)?.[1]||null};
  return {status:'LOGIN_REQUIRED'};
}
async function launch(uuid, headed=false) {
  await fs.mkdir(profile(uuid),{recursive:true,mode:0o700});
  const { chromium } = await import('playwright');
  const context=await chromium.launchPersistentContext(profile(uuid),{headless:!headed,viewport:{width:1280,height:850}});
  const page=context.pages()[0] || await context.newPage();
  await page.goto('https://www.threads.com/',{waitUntil:'domcontentloaded',timeout:30000});
  return {context,page};
}
async function action(url, data) {
  const uuid=data.uuid;
  if (!validUuid(uuid)) throw new Error('Invalid UUID');
  if (url==='/threads/open-login') {
    if (active.has(uuid)) return {ok:true,message:'Browser already open'};
    if (!process.env.DISPLAY) throw new Error('DISPLAY missing: start a secured desktop/VNC session on the VPS');
    const item=await launch(uuid,true); active.set(uuid,item);
    // Login and challenges stay manual. Auto-close after 15 minutes to release RAM.
    const timer=setTimeout(async()=>{ active.delete(uuid); await item.context.close().catch(()=>{}); },15*60*1000); timer.unref();
    return {ok:true,message:'Use the server desktop for manual login, then check session'};
  }
  if (url==='/threads/check-session') {
    const item=active.get(uuid);
    if (item) return await status(item.page);
    const {context,page}=await launch(uuid);
    try { return await status(page); } finally { await context.close(); }
  }
  if (url==='/threads/publish') {
    if (active.has(uuid)) throw new Error('Login window still open; close it before publishing');
    if (running>=maxConcurrent) throw new Error('Worker at concurrency limit');
    if (typeof data.caption!=='string' || !data.caption.trim() || data.caption.length>5000) throw new Error('Invalid caption');
    if (!Array.isArray(data.media) || data.media.length>10) throw new Error('Invalid media');
    for (const p of data.media) {
      const resolved=path.resolve(p);
      if (!resolved.startsWith(mediaRoot+path.sep)) throw new Error('Media path outside private upload root');
      const actual=await fs.realpath(resolved), rootActual=await fs.realpath(mediaRoot);
      if (!actual.startsWith(rootActual+path.sep)) throw new Error('Unsafe media path');
    }
    running++; let context, page;
    try {
      ({context,page}=await launch(uuid));
      const auth=await status(page);
      if (auth.status!=='READY') throw new Error(auth.status==='VERIFICATION_REQUIRED'?'Verification required':'Login required');
      // UI selectors are isolated here so Threads UI changes only touch this engine.
      const composer=page.getByRole('button',{name:/start a thread|what's new|post/i}).first();
      await composer.click({timeout:12000});
      const editor=page.getByRole('textbox').last();
      await editor.fill(data.caption,{timeout:12000});
      if (data.media.length) {
        const file=page.locator('input[type="file"]').first();
        await file.setInputFiles(data.media,{timeout:30000});
      }
      const submit=page.getByRole('button',{name:/^post$/i}).last();
      await submit.click({timeout:15000});
      await page.waitForTimeout(3000);
      if (await editor.isVisible().catch(()=>false)) throw new Error('Post composer still visible; verify manually before retry');
      const own=auth.username;
      const urlNow=page.url();
      const link=urlNow.includes('/post/') ? urlNow : await page.locator(`a[href^="/@${own}/post/"]`).first().getAttribute('href').catch(()=>null);
      if (!link) throw new Error('Publish confirmation unavailable; inspect Threads before retry');
      return {ok:true,url:new URL(link,'https://www.threads.com').href};
    } catch (error) {
      if (page) {
        const safe=String(data.post_id??'unknown').replace(/[^0-9]/g,'');
        const screenshot=path.join(root,'error-'+safe+'-'+Date.now()+'.png');
        await page.screenshot({path:screenshot}).catch(()=>{});
        error.message += ` (screenshot: ${screenshot})`;
      }
      throw error;
    } finally { if (context) await context.close().catch(()=>{}); running--; }
  }
  throw new Error('Unknown endpoint');
}
if (process.env.NODE_ENV !== 'test') {
  http.createServer(async(req,res)=>{
    const reply=(code,obj)=>{res.writeHead(code,{'Content-Type':'application/json','Cache-Control':'no-store'});res.end(JSON.stringify(obj));};
    if (req.url==='/health' && req.method==='GET') return reply(200,{ok:true,running});
    if (req.method!=='POST') return reply(404,{error:'Not found'});
    let chunks=[]; for await (const chunk of req) { chunks.push(chunk); if (Buffer.concat(chunks).length>50000) return reply(413,{error:'Request too large'}); }
    const raw=Buffer.concat(chunks);
    if (!signatureValid(raw,req.headers['x-worker-signature'],secret)) return reply(401,{error:'Unauthorized'});
    try { return reply(200,await action(req.url,JSON.parse(raw))); }
    catch (err) { return reply(422,{ok:false,error:err.message}); }
  }).listen(Number(process.env.THREADS_WORKER_PORT||3487),'127.0.0.1');
}
