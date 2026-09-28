<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('threads_accounts', function (Blueprint $t) {
            $t->id(); $t->uuid('uuid')->unique(); $t->string('username')->nullable();
            $t->string('status')->default('LOGIN_REQUIRED')->index(); $t->text('last_error')->nullable(); $t->timestamps();
        });
        Schema::create('campaigns', function (Blueprint $t) {
            $t->id(); $t->string('name'); $t->string('product_name')->nullable(); $t->text('notes')->nullable(); $t->timestamps();
        });
        Schema::create('media_library', function (Blueprint $t) {
            $t->id(); $t->foreignId('campaign_id')->nullable()->constrained()->nullOnDelete();
            $t->string('path'); $t->string('original_name'); $t->string('mime'); $t->unsignedBigInteger('size'); $t->timestamps();
        });
        Schema::create('campaign_posts', function (Blueprint $t) {
            $t->id(); $t->foreignId('campaign_id')->constrained()->cascadeOnDelete();
            $t->foreignId('threads_account_id')->constrained()->cascadeOnDelete();
            $t->text('caption')->default(''); $t->string('status')->default('DRAFT')->index();
            $t->timestamp('scheduled_at')->nullable()->index(); $t->string('published_url')->nullable();
            $t->text('last_error')->nullable(); $t->timestamp('locked_at')->nullable(); $t->timestamps();
            $t->unique(['campaign_id','threads_account_id']);
        });
        Schema::create('post_media', function (Blueprint $t) {
            $t->id(); $t->foreignId('campaign_post_id')->constrained()->cascadeOnDelete();
            $t->foreignId('media_library_id')->constrained('media_library')->cascadeOnDelete();
            $t->unsignedInteger('position'); $t->unique(['campaign_post_id','position']);
        });
        Schema::create('automation_logs', function (Blueprint $t) {
            $t->id(); $t->foreignId('campaign_post_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('threads_account_id')->nullable()->constrained()->nullOnDelete();
            $t->string('step'); $t->string('status'); $t->text('detail')->nullable();
            $t->string('screenshot')->nullable(); $t->timestamps();
        });
        Schema::create('telegram_groups', function (Blueprint $t) {
            $t->id(); $t->string('name'); $t->string('chat_id')->unique(); $t->boolean('active')->default(true); $t->timestamps();
        });
        Schema::create('telegram_templates', function (Blueprint $t) {
            $t->id(); $t->string('name'); $t->string('kind'); $t->text('body'); $t->timestamps();
        });
        Schema::create('telegram_transaction_feed', function (Blueprint $t) {
            $t->id(); $t->foreignId('telegram_group_id')->constrained()->cascadeOnDelete();
            $t->string('external_order_id'); $t->string('product'); $t->unsignedBigInteger('amount_idr');
            $t->string('status')->default('PENDING_APPROVAL')->index();
            $t->string('telegram_message_id')->nullable(); $t->text('last_error')->nullable();
            $t->timestamps(); $t->unique(['telegram_group_id','external_order_id']);
        });
    }
    public function down(): void {
        foreach (['telegram_transaction_feed','telegram_templates','telegram_groups','automation_logs','post_media','campaign_posts','media_library','campaigns','threads_accounts'] as $table) Schema::dropIfExists($table);
    }
};
