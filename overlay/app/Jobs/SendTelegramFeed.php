<?php
namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class SendTelegramFeed implements ShouldQueue {
    use Queueable;
    public int $tries=1;
    public function __construct(public int $feedId) {}
    public function handle(): void {
        $f=DB::table('telegram_transaction_feed')->find($this->feedId);
        if (!$f || $f->status!=='QUEUED') return;
        $g=DB::table('telegram_groups')->find($f->telegram_group_id);
        $token=config('threads.telegram_token');
        if (!$g || !$g->active || !$token) { $this->failFeed('Group inactive or Telegram token missing'); return; }
        $message="✅ Pembayaran terverifikasi\nOrder #{$f->external_order_id}\n{$f->product}\nRp".number_format($f->amount_idr,0,',','.')."\nTerima kasih, pesanan sedang diproses.";
        try {
            $r=Http::timeout(15)->post('https://api.telegram.org/bot'.$token.'/sendMessage',['chat_id'=>$g->chat_id,'text'=>$message]);
            if (!$r->successful() || !$r->json('ok')) throw new \RuntimeException('Telegram rejected message');
            DB::table('telegram_transaction_feed')->where('id',$f->id)->update(['status'=>'POSTED','telegram_message_id'=>(string)$r->json('result.message_id'),'updated_at'=>now()]);
        } catch (\Throwable $e) { $this->failFeed($e->getMessage()); }
    }
    private function failFeed(string $message): void { DB::table('telegram_transaction_feed')->where('id',$this->feedId)->update(['status'=>'FAILED','last_error'=>$message,'updated_at'=>now()]); }
}
