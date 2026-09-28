<?php

namespace App\Http\Controllers;

use App\Jobs\PublishThreadsPost;
use App\Jobs\SendTelegramFeed;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ThreadsToolsController extends Controller
{
    public function loginForm() { return view('threads-login'); }
    public function login(Request $r) {
        $v = $r->validate(['email'=>'required|email','password'=>'required|string']);
        if (! Auth::attempt($v)) throw ValidationException::withMessages(['email'=>'Kredensial salah.']);
        $r->session()->regenerate(); return redirect('/');
    }
    public function logout(Request $r) { Auth::logout(); $r->session()->invalidate(); $r->session()->regenerateToken(); return redirect('/login'); }
    public function index() {
        return view('threads-dashboard',[
            'accounts'=>DB::table('threads_accounts')->latest()->get(),
            'campaigns'=>DB::table('campaigns')->latest()->get(),
            'posts'=>DB::table('campaign_posts')->join('threads_accounts','threads_accounts.id','=','campaign_posts.threads_account_id')->select('campaign_posts.*','threads_accounts.username','threads_accounts.status as account_status')->latest('campaign_posts.id')->get(),
            'media'=>DB::table('media_library')->latest()->get(),
            'groups'=>DB::table('telegram_groups')->latest()->get(),
            'templates'=>DB::table('telegram_templates')->latest()->get(),
            'feed'=>DB::table('telegram_transaction_feed')->latest()->get(),
            'logs'=>DB::table('automation_logs')->latest()->limit(30)->get(),
        ]);
    }
    private function worker(string $endpoint,array $body): array {
        $payload=json_encode($body,JSON_THROW_ON_ERROR);
        $response=Http::timeout(35)->withHeaders(['X-Worker-Signature'=>hash_hmac('sha256',$payload,config('threads.worker_secret'))])
            ->withBody($payload,'application/json')->post(rtrim(config('threads.worker_url'),'/').$endpoint);
        if (!$response->successful()) throw ValidationException::withMessages(['worker'=>$response->json('error','Worker unavailable')]);
        return $response->json();
    }
    public function account() {
        $uuid=(string)Str::uuid();
        $id=DB::table('threads_accounts')->insertGetId(['uuid'=>$uuid,'status'=>'LOGIN_REQUIRED','created_at'=>now(),'updated_at'=>now()]);
        try { $this->worker('/threads/open-login',['uuid'=>$uuid]); }
        catch (\Throwable $e) { DB::table('threads_accounts')->where('id',$id)->update(['status'=>'ERROR','last_error'=>$e->getMessage()]); return back()->withErrors(['worker'=>$e->getMessage()]); }
        return back()->with('notice','Browser login dibuka di tampilan server. Login manual, lalu klik Check Session.');
    }
    public function retryAccount(int $id) {
        $a=DB::table('threads_accounts')->find($id); abort_unless($a,404);
        try { $this->worker('/threads/open-login',['uuid'=>$a->uuid]); }
        catch (\Throwable $e) { DB::table('threads_accounts')->where('id',$id)->update(['status'=>'ERROR','last_error'=>$e->getMessage(),'updated_at'=>now()]); return back()->withErrors(['worker'=>$e->getMessage()]); }
        DB::table('threads_accounts')->where('id',$id)->update(['status'=>'LOGIN_REQUIRED','last_error'=>null,'updated_at'=>now()]);
        return back()->with('notice','Browser login dibuka di tampilan server. Login manual, lalu klik Check Session.');
    }
    public function checkAccount(int $id) {
        $a=DB::table('threads_accounts')->find($id); abort_unless($a,404);
        $result=$this->worker('/threads/check-session',['uuid'=>$a->uuid]);
        DB::table('threads_accounts')->where('id',$id)->update(['status'=>$result['status'],'username'=>$result['username']??$a->username,'updated_at'=>now()]);
        return back()->with('notice','Status: '.$result['status']);
    }
    public function campaign(Request $r) {
        $v=$r->validate(['name'=>'required|string|max:180','product_name'=>'nullable|string|max:180','notes'=>'nullable|string|max:4000']);
        DB::table('campaigns')->insert($v+['created_at'=>now(),'updated_at'=>now()]); return back();
    }
    public function duplicate(Request $r,int $id) {
        abort_unless(DB::table('campaigns')->where('id',$id)->exists(),404);
        $v=$r->validate(['account_ids'=>'required|array|min:1','account_ids.*'=>'integer|exists:threads_accounts,id']);
        foreach (array_unique($v['account_ids']) as $a) DB::table('campaign_posts')->insertOrIgnore(['campaign_id'=>$id,'threads_account_id'=>$a,'status'=>'DRAFT','caption'=>'','created_at'=>now(),'updated_at'=>now()]);
        return back();
    }
    public function updatePost(Request $r,int $id) {
        $p=DB::table('campaign_posts')->find($id); abort_unless($p,404); abort_unless(in_array($p->status,['DRAFT','READY','APPROVED','FAILED']),409);
        $v=$r->validate(['caption'=>'required|string|max:5000','scheduled_at'=>'nullable|date','media_ids'=>'nullable|array|max:10','media_ids.*'=>'integer|exists:media_library,id']);
        DB::transaction(function () use ($v,$id) {
            DB::table('campaign_posts')->where('id',$id)->update(['caption'=>$v['caption'],'scheduled_at'=>$v['scheduled_at']??null,'status'=>'READY','updated_at'=>now()]);
            DB::table('post_media')->where('campaign_post_id',$id)->delete();
            foreach (array_unique($v['media_ids']??[]) as $pos=>$mid) DB::table('post_media')->insert(['campaign_post_id'=>$id,'media_library_id'=>$mid,'position'=>$pos]);
        }); return back();
    }
    public function approvePost(int $id) {
        $p=DB::table('campaign_posts')->find($id); abort_unless($p,404); abort_unless(in_array($p->status,['READY','APPROVED']),409);
        DB::table('campaign_posts')->where('id',$id)->update(['status'=>$p->status==='READY'?'APPROVED':'READY','updated_at'=>now()]); return back();
    }
    public function queuePost(int $id) {
        $updated=DB::table('campaign_posts')->where('id',$id)->where('status','APPROVED')->update(['status'=>'QUEUED','updated_at'=>now()]); abort_unless($updated,409);
        if (DB::table('campaign_posts')->where('id',$id)->where(fn($q)=>$q->whereNull('scheduled_at')->orWhere('scheduled_at','<=',now()))->exists()) PublishThreadsPost::dispatch($id);
        return back();
    }
    public function media(Request $r) {
        $v=$r->validate(['file'=>'required|file|mimes:jpg,jpeg,png,webp|max:10240','campaign_id'=>'nullable|integer|exists:campaigns,id']);
        $f=$v['file']; $path=$f->store('threads-media','local');
        DB::table('media_library')->insert(['campaign_id'=>$v['campaign_id']??null,'path'=>$path,'original_name'=>basename($f->getClientOriginalName()),'mime'=>$f->getMimeType(),'size'=>$f->getSize(),'created_at'=>now(),'updated_at'=>now()]);
        return back();
    }
    public function mediaPreview(int $id) {
        $m=DB::table('media_library')->find($id); abort_unless($m,404);
        return Storage::disk('local')->response($m->path,null,['Content-Type'=>$m->mime,'X-Content-Type-Options'=>'nosniff']);
    }
    public function group(Request $r) {
        $v=$r->validate(['name'=>'required|string|max:180','chat_id'=>'required|regex:/^-?[0-9]{1,20}$/|unique:telegram_groups,chat_id']);
        DB::table('telegram_groups')->insert($v+['active'=>true,'created_at'=>now(),'updated_at'=>now()]); return back();
    }
    public function template(Request $r) {
        $v=$r->validate(['name'=>'required|string|max:180','kind'=>'required|in:FAQ,ANNOUNCEMENT,SIMULATION','body'=>'required|string|max:3000']);
        if ($v['kind']==='SIMULATION') $v['body']='[SIMULASI] '.preg_replace('/^\[SIMULASI\]\s*/u','',$v['body']);
        DB::table('telegram_templates')->insert($v+['created_at'=>now(),'updated_at'=>now()]); return back();
    }
    public function paidWebhook(Request $r) {
        $raw=$r->getContent(); $key=config('threads.orders_webhook_secret');
        abort_unless($key && hash_equals(hash_hmac('sha256',$raw,$key),(string)$r->header('X-Order-Signature')),401);
        $v=$r->validate(['event_id'=>'required|string|max:100','order_id'=>'required|string|max:100','status'=>'required|in:PAID','product'=>'required|string|max:180','amount_idr'=>'required|integer|min:1','group_id'=>'required|integer|exists:telegram_groups,id']);
        abort_unless(DB::table('telegram_groups')->where('id',$v['group_id'])->where('active',true)->exists(),422);
        // The website signs an event only after its own server verifies payment. Event ID and order ID are idempotent.
        DB::table('telegram_transaction_feed')->insertOrIgnore(['telegram_group_id'=>$v['group_id'],'external_order_id'=>$v['order_id'],'product'=>$v['product'],'amount_idr'=>$v['amount_idr'],'status'=>'PENDING_APPROVAL','created_at'=>now(),'updated_at'=>now()]);
        return response()->json(['received'=>true]);
    }
    public function approveFeed(int $id) {
        $n=DB::table('telegram_transaction_feed')->where('id',$id)->where('status','PENDING_APPROVAL')->update(['status'=>'APPROVED','updated_at'=>now()]); abort_unless($n,409); return back();
    }
    public function sendFeed(int $id) {
        $n=DB::table('telegram_transaction_feed')->where('id',$id)->where('status','APPROVED')->update(['status'=>'QUEUED','updated_at'=>now()]); abort_unless($n,409);
        SendTelegramFeed::dispatch($id); return back();
    }
    public function sendTemplate(Request $r,int $id) {
        $v=$r->validate(['group_id'=>'required|integer|exists:telegram_groups,id']);
        $tpl=DB::table('telegram_templates')->find($id); abort_unless($tpl,404);
        $g=DB::table('telegram_groups')->where('id',$v['group_id'])->where('active',true)->first(); abort_unless($g,422);
        $token=config('threads.telegram_token'); abort_unless($token,503);
        $response=Http::timeout(15)->post('https://api.telegram.org/bot'.$token.'/sendMessage',['chat_id'=>$g->chat_id,'text'=>$tpl->body]);
        if (!$response->successful() || !$response->json('ok')) return back()->withErrors(['telegram'=>'Pengiriman Telegram gagal.']);
        return back()->with('notice','Template terkirim.');
    }
}
