<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('community_incident_votes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('incident_id')->constrained()->cascadeOnDelete();
            $table->char('voter_hash', 64);
            $table->string('vote', 12);
            $table->timestamps();

            $table->unique(['incident_id', 'voter_hash'], 'community_incident_voter_unique');
            $table->index(['incident_id', 'vote']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('community_incident_votes');
    }
};
