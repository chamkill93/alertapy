<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('incidents', function (Blueprint $table): void {
            $table->string('subcategory', 64)->nullable()->index();
            $table->timestamp('occurred_at')->nullable()->index();
            $table->string('occurred_at_accuracy', 24)->default('unknown');
            $table->json('temporal_evidence_json')->nullable();
            $table->timestamp('detected_at')->nullable()->index();
            $table->timestamp('first_seen_at')->nullable()->index();
            $table->timestamp('last_seen_at')->nullable()->index();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('expired_at')->nullable();
            $table->string('lifecycle_status', 24)->default('detected')->index();
            $table->string('incident_scope', 32)->default('unknown')->index();
            $table->string('geometry_type', 24)->nullable()->index();
            $table->string('location_method', 32)->default('unknown')->index();
            $table->string('location_quality', 20)->default('unknown')->index();
            $table->json('location_quality_flags')->nullable();
            $table->json('publication_explanation_json')->nullable();
            $table->json('data_quality_json')->nullable();
        });

        Schema::table('incident_sources', function (Blueprint $table): void {
            $table->string('source_role', 24)->default('primary')->index();
        });

        Schema::table('incident_updates', function (Blueprint $table): void {
            $table->string('event_type', 32)->default('updated')->index();
            $table->json('metadata_json')->nullable();
        });

        Schema::table('sources', function (Blueprint $table): void {
            $table->string('last_error_type', 64)->nullable()->index();
            $table->unsignedInteger('last_duration_ms')->nullable();
            $table->unsignedInteger('items_seen')->default(0);
            $table->unsignedInteger('items_new')->default(0);
            $table->timestamp('last_new_item_at')->nullable()->index();
        });

        Schema::create('scheduler_cycles', function (Blueprint $table): void {
            $table->id();
            $table->timestamp('started_at')->index();
            $table->timestamp('finished_at')->nullable();
            $table->string('status', 24)->index();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->unsignedSmallInteger('sources_processed')->default(0);
            $table->unsignedInteger('candidates_created')->default(0);
            $table->unsignedInteger('incidents_created')->default(0);
            $table->unsignedInteger('incidents_updated')->default(0);
            $table->unsignedInteger('duplicates_merged')->default(0);
            $table->unsignedInteger('incidents_closed')->default(0);
            $table->unsignedInteger('geography_errors')->default(0);
            $table->text('error_summary')->nullable();
            $table->timestamps();
        });

        Schema::create('location_audits', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('incident_id')->constrained()->cascadeOnDelete();
            $table->decimal('old_latitude', 10, 7)->nullable();
            $table->decimal('old_longitude', 10, 7)->nullable();
            $table->decimal('new_latitude', 10, 7)->nullable();
            $table->decimal('new_longitude', 10, 7)->nullable();
            $table->string('old_method', 32)->nullable();
            $table->string('new_method', 32)->nullable();
            $table->string('reason', 255);
            $table->string('source', 120);
            $table->json('metadata_json')->nullable();
            $table->timestamp('audited_at')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('location_audits');
        Schema::dropIfExists('scheduler_cycles');

        Schema::table('sources', function (Blueprint $table): void {
            $table->dropColumn(['last_error_type', 'last_duration_ms', 'items_seen', 'items_new', 'last_new_item_at']);
        });
        Schema::table('incident_updates', function (Blueprint $table): void {
            $table->dropColumn(['event_type', 'metadata_json']);
        });
        Schema::table('incident_sources', function (Blueprint $table): void {
            $table->dropColumn('source_role');
        });
        Schema::table('incidents', function (Blueprint $table): void {
            $table->dropColumn([
                'subcategory', 'occurred_at', 'occurred_at_accuracy', 'temporal_evidence_json',
                'detected_at', 'first_seen_at', 'last_seen_at', 'resolved_at', 'expired_at',
                'lifecycle_status', 'incident_scope', 'geometry_type', 'location_method',
                'location_quality', 'location_quality_flags', 'publication_explanation_json', 'data_quality_json',
            ]);
        });
    }
};
