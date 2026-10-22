<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sources', function (Blueprint $table): void {
            $table->string('ingestion_type', 32)->default('html')->after('type')->index();
            $table->string('georef_mode', 32)->default('extract_and_geocode')->after('ingestion_type')->index();
            $table->string('trust_level', 24)->default('medium')->after('polling_minutes')->index();
            $table->boolean('requires_ai_decision')->default(false)->after('trust_level');
            $table->boolean('canonical_url_required')->default(true)->after('requires_ai_decision');
            $table->boolean('supports_etag')->default(false)->after('canonical_url_required');
            $table->boolean('supports_last_modified')->default(false)->after('supports_etag');
            $table->timestamp('last_change_at')->nullable()->after('last_success_at')->index();
            $table->unsignedSmallInteger('last_http_status')->nullable()->after('last_change_at');
            $table->unsignedSmallInteger('consecutive_errors')->default(0)->after('last_error');
            $table->json('configuration_json')->nullable()->after('content_hash');
        });
    }

    public function down(): void
    {
        Schema::table('sources', function (Blueprint $table): void {
            $table->dropIndex(['ingestion_type']);
            $table->dropIndex(['georef_mode']);
            $table->dropIndex(['trust_level']);
            $table->dropIndex(['last_change_at']);
            $table->dropColumn([
                'ingestion_type',
                'georef_mode',
                'trust_level',
                'requires_ai_decision',
                'canonical_url_required',
                'supports_etag',
                'supports_last_modified',
                'last_change_at',
                'last_http_status',
                'consecutive_errors',
                'configuration_json',
            ]);
        });
    }
};
