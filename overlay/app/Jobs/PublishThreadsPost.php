<?php
namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

class PublishThreadsPost implements ShouldQueue {
    use Queueable;
    public int $tries=2; public int $timeout=150;
    public function __construct(public int $postId) {}
    public function handle(): void {
        $p=DB::table('campaign_posts')->find($this->postId);
        if (!$p || $p->status!=='QUEUED' || ($p->scheduled_at && $p->scheduled_at>now())) return;
        $account=DB::table('threads_accounts')->find($p->threads_account_id);
        if (!$account || $account->status!=='READY') { $this->failPost('Account login required'); return; }
        $lock=Cache::lock('threads-account-'.$account->id,180);
        if (!$lock->get()) { $this->release(20); return; }
        try {
            if (!DB::table('campaign_posts')->where('id',$p->id)->where('status','QUEUED')->update(['status'=>'PUBLISHING','updated_at'=>now()])) return;
            DB::table('threads_accounts')->where('id',$account->id)->update(['status'=>'BUSY']);
            $media=DB::table('post_media')->join('media_library','media_library.id','=','post_media.media_library_id')->where('campaign_post_id',$p->id)->orderBy('position')->pluck('media_library.path')
                ->map(fn($path)=>Storage::disk('local')->path($path))->all();
            $payload=json_encode(['uuid'=>$account->uuid,'caption'=>$p->caption,'media'=>$media,'post_id'=>$p->id],JSON_THROW_ON_ERROR);
            $response=Http::timeout(125)->withHeaders(['X-Worker-Signature'=>hash_hmac('sha256',$payload,config('threads.worker_secret'))])->withBody($payload,'application/json')
                ->post(rtrim(config('threads.worker_url'),'/').'/threads/publish');
            $data=$response->json()??[];
            if (!$response->successful() || !($data['ok']??false)) throw new \RuntimeException($data['error']??'Worker HTTP '.$response->status());
            DB::table('campaign_posts')->where('id',$p->id)->update(['status'=>'PUBLISHED','published_url'=>$data['url']??null,'locked_at'=>null,'updated_at'=>now()]);
            $this->log($account->id,'complete','PUBLISHED',$data['url']??null);
        } catch (\Throwable $e) {
            $this->failPost($e->getMessage()); $this->log($account->id,'publish','FAILED',$e->getMessage());
            if (str_contains(strtolower($e->getMessage()),'verification')) DB::table('threads_accounts')->where('id',$account->id)->update(['status'=>'VERIFICATION_REQUIRED']);
            elseif (str_contains(strtolower($e->getMessage()),'login')) DB::table('threads_accounts')->where('id',$account->id)->update(['status'=>'LOGIN_REQUIRED']);
        } finally {
            DB::table('threads_accounts')->where('id',$account->id)->where('status','BUSY')->update(['status'=>'READY']);
            $lock->release();
        }
    }
    private function failPost(string $reason): void { DB::table('campaign_posts')->where('id',$this->postId)->update(['status'=>'FAILED','last_error'=>mb_substr($reason,0,3000),'updated_at'=>now()]); }
    private function log(int $accountId,string $step,string $status,?string $detail): void { DB::table('automation_logs')->insert(['campaign_post_id'=>$this->postId,'threads_account_id'=>$accountId,'step'=>$step,'status'=>$status,'detail'=>$detail,'created_at'=>now(),'updated_at'=>now()]); }
}
