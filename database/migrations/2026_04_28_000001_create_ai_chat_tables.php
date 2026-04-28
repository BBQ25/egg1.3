<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('ai_chat_sessions')) {
            Schema::create('ai_chat_sessions', function (Blueprint $table) {
                $table->id();
                $table->unsignedInteger('user_id');
                $table->string('title', 160)->default('New chat');
                $table->string('role_key', 30);
                $table->string('last_intent', 80)->nullable();
                $table->timestamp('last_message_at')->nullable();
                $table->timestamps();

                $table->index(['user_id', 'last_message_at'], 'idx_ai_chat_sessions_user_last');
                $table->foreign('user_id', 'fk_ai_chat_sessions_user')
                    ->references('id')
                    ->on('users')
                    ->cascadeOnUpdate()
                    ->cascadeOnDelete();
            });
        }

        if (!Schema::hasTable('ai_chat_messages')) {
            Schema::create('ai_chat_messages', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('session_id');
                $table->unsignedInteger('user_id');
                $table->string('sender', 20);
                $table->string('role_key', 30);
                $table->string('intent', 80)->nullable();
                $table->string('status', 40)->default('ok');
                $table->longText('content');
                $table->longText('scoped_data_json')->nullable();
                $table->longText('sources_json')->nullable();
                $table->timestamps();

                $table->index(['session_id', 'created_at'], 'idx_ai_chat_messages_session_created');
                $table->index(['user_id', 'created_at'], 'idx_ai_chat_messages_user_created');
                $table->foreign('session_id', 'fk_ai_chat_messages_session')
                    ->references('id')
                    ->on('ai_chat_sessions')
                    ->cascadeOnUpdate()
                    ->cascadeOnDelete();
                $table->foreign('user_id', 'fk_ai_chat_messages_user')
                    ->references('id')
                    ->on('users')
                    ->cascadeOnUpdate()
                    ->cascadeOnDelete();
            });
        }

        if (!Schema::hasTable('ai_chat_action_drafts')) {
            Schema::create('ai_chat_action_drafts', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('session_id');
                $table->unsignedBigInteger('message_id')->nullable();
                $table->unsignedInteger('user_id');
                $table->string('action_type', 80);
                $table->text('summary');
                $table->string('target_route', 120)->nullable();
                $table->longText('payload_json')->nullable();
                $table->string('status', 30)->default('draft');
                $table->timestamps();

                $table->index(['user_id', 'created_at'], 'idx_ai_action_drafts_user_created');
                $table->foreign('session_id', 'fk_ai_action_drafts_session')
                    ->references('id')
                    ->on('ai_chat_sessions')
                    ->cascadeOnUpdate()
                    ->cascadeOnDelete();
                $table->foreign('message_id', 'fk_ai_action_drafts_message')
                    ->references('id')
                    ->on('ai_chat_messages')
                    ->cascadeOnUpdate()
                    ->nullOnDelete();
                $table->foreign('user_id', 'fk_ai_action_drafts_user')
                    ->references('id')
                    ->on('users')
                    ->cascadeOnUpdate()
                    ->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_chat_action_drafts');
        Schema::dropIfExists('ai_chat_messages');
        Schema::dropIfExists('ai_chat_sessions');
    }
};
