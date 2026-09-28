<?php
namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use App\Models\User;

class MakeThreadsAdmin extends Command {
    protected $signature='threads:make-admin';
    protected $description='Create the first dashboard administrator interactively';
    public function handle(): int {
        $email=$this->ask('Admin email'); $password=$this->secret('Password (at least 12 characters)');
        if (!filter_var($email,FILTER_VALIDATE_EMAIL) || strlen($password??'')<12) { $this->error('Invalid email or weak password'); return self::FAILURE; }
        User::firstOrCreate(['email'=>$email],['name'=>'Threads Admin','password'=>Hash::make($password),'email_verified_at'=>now()]);
        $this->info('Admin account ready'); return self::SUCCESS;
    }
}
