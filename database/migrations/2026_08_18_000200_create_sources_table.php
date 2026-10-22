<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sources', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 140);
            $table->string('slug', 100)->unique();
            $table->string('type', 20)->index();
            $table->string('base_url', 2048)->nullable();
            $table->string('domain', 255)->nullable();
            $table->boolean('enabled')->default(true)->index();
            $table->unsignedSmallInteger('polling_minutes')->default(10);
            $table->timestamp('last_run_at')->nullable()->index();
            $table->timestamp('last_success_at')->nullable();
            $table->text('last_error')->nullable();
            $table->string('etag', 255)->nullable();
            $table->string('last_modified', 255)->nullable();
            $table->char('content_hash', 64)->nullable()->index();
            $table->timestamps();

            $table->index(['enabled', 'last_run_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sources');
    }
};
