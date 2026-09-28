<?php
namespace App\Console\Commands;

use App\Jobs\PublishThreadsPost;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class DispatchDueThreadsPosts extends Command {
    protected $signature='threads:dispatch-due';
    protected $description='Dispatch approved queued posts whose scheduled time has arrived';
    public function handle(): int {
        DB::table('campaign_posts')->where('status','QUEUED')->where(fn($q)=>$q->whereNull('scheduled_at')->orWhere('scheduled_at','<=',now()))->pluck('id')->each(fn($id)=>PublishThreadsPost::dispatch($id));
        return self::SUCCESS;
    }
}
