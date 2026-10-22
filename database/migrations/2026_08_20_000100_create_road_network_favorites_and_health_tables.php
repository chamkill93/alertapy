<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('road_datasets', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('source_name')->nullable();
            $table->text('source_url')->nullable();
            $table->date('source_date')->nullable();
            $table->string('file_name');
            $table->char('file_hash', 64)->unique();
            $table->string('format', 12);
            $table->timestamp('imported_at')->nullable();
            $table->unsignedInteger('feature_count')->default(0);
            $table->string('status', 32)->default('validating')->index();
            $table->json('metadata_json')->nullable();
            $table->timestamps();
        });

        Schema::create('roads', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('source_dataset_id')->constrained('road_datasets')->restrictOnDelete();
            $table->string('code', 32);
            $table->string('normalized_code', 32)->index();
            $table->string('name')->nullable();
            $table->string('official_name')->nullable();
            $table->string('classification', 32)->nullable()->index();
            $table->string('department')->nullable()->index();
            $table->string('district')->nullable()->index();
            $table->string('geometry_type', 24)->default('LineString');
            $table->json('geometry_json');
            $table->decimal('bbox_north', 10, 7);
            $table->decimal('bbox_south', 10, 7);
            $table->decimal('bbox_east', 10, 7);
            $table->decimal('bbox_west', 10, 7);
            $table->decimal('length_meters', 14, 2)->default(0);
            $table->string('direction_start_label')->nullable();
            $table->string('direction_end_label')->nullable();
            $table->boolean('active')->default(true)->index();
            $table->timestamps();
            $table->index(['normalized_code', 'active']);
        });

        Schema::create('road_aliases', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('road_id')->constrained()->cascadeOnDelete();
            $table->string('alias');
            $table->string('normalized_alias')->index();
            $table->string('source')->nullable();
            $table->timestamps();
            $table->unique(['road_id', 'normalized_alias']);
        });

        Schema::create('road_segments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('road_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('sequence');
            $table->decimal('start_lat', 10, 7);
            $table->decimal('start_lng', 10, 7);
            $table->decimal('end_lat', 10, 7);
            $table->decimal('end_lng', 10, 7);
            $table->decimal('segment_length_meters', 14, 2);
            $table->decimal('cumulative_start_meters', 14, 2);
            $table->decimal('cumulative_end_meters', 14, 2);
            $table->decimal('bbox_north', 10, 7);
            $table->decimal('bbox_south', 10, 7);
            $table->decimal('bbox_east', 10, 7);
            $table->decimal('bbox_west', 10, 7);
            $table->string('department')->nullable()->index();
            $table->string('district')->nullable()->index();
            $table->string('city')->nullable()->index();
            $table->timestamps();
            $table->unique(['road_id', 'sequence']);
            $table->index(['road_id', 'bbox_south', 'bbox_north']);
        });

        Schema::create('road_reference_points', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('road_id')->constrained()->cascadeOnDelete();
            $table->foreignId('source_dataset_id')->nullable()->constrained('road_datasets')->nullOnDelete();
            $table->string('reference_type', 32)->default('KM_MARKER')->index();
            $table->decimal('kilometer', 10, 3)->nullable();
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            $table->string('name')->nullable();
            $table->text('source_url')->nullable();
            $table->string('accuracy', 32)->nullable();
            $table->timestamps();
            $table->index(['road_id', 'kilometer']);
        });

        Schema::create('territorial_geometries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('location_id')->constrained()->cascadeOnDelete();
            $table->string('geometry_type', 24);
            $table->json('geometry_json');
            $table->decimal('bbox_north', 10, 7);
            $table->decimal('bbox_south', 10, 7);
            $table->decimal('bbox_east', 10, 7);
            $table->decimal('bbox_west', 10, 7);
            $table->string('source');
            $table->date('source_date')->nullable();
            $table->timestamps();
            $table->unique('location_id');
        });

        Schema::create('favorite_zones', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->char('anonymous_token', 64)->nullable()->index();
            $table->string('name', 80);
            $table->string('icon', 32)->default('favorite');
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            $table->decimal('radius_km', 6, 2)->default(3);
            foreach (['electricity', 'water', 'security', 'traffic', 'fire', 'weather', 'flood', 'health'] as $category) {
                $table->boolean("{$category}_enabled")->default(true);
            }
            $table->boolean('enabled')->default(true)->index();
            $table->timestamps();
            $table->index(['user_id', 'enabled']);
            $table->index(['anonymous_token', 'enabled']);
        });

        Schema::create('source_parser_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('source_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('element_count')->default(0);
            $table->unsignedInteger('content_length')->default(0);
            $table->char('structure_signature', 64);
            $table->char('page_hash', 64);
            $table->string('parser_version', 64);
            $table->boolean('structure_changed')->default(false)->index();
            $table->timestamp('observed_at')->index();
            $table->timestamps();
        });

        Schema::table('incidents', function (Blueprint $table): void {
            $table->foreignId('road_id')->nullable()->after('geometry_geojson')->constrained()->nullOnDelete();
            $table->string('road_code', 32)->nullable()->after('road_id')->index();
            $table->decimal('road_kilometer', 10, 3)->nullable()->after('road_code')->index();
            $table->string('road_location_method', 40)->nullable()->after('road_kilometer')->index();
            $table->unsignedInteger('location_error_meters')->nullable()->after('road_location_method');
            $table->json('location_evidence_json')->nullable()->after('location_error_meters');
            $table->string('impact_method', 32)->default('UNKNOWN')->after('location_evidence_json')->index();
            $table->json('impact_evidence_json')->nullable()->after('impact_method');
        });

        Schema::table('content_candidates', function (Blueprint $table): void {
            $table->string('road_code', 32)->nullable()->after('detected_location')->index();
            $table->decimal('road_kilometer', 10, 3)->nullable()->after('road_code');
            $table->json('location_evidence_json')->nullable()->after('road_kilometer');
            $table->decimal('evidence_score', 5, 4)->nullable()->after('location_evidence_json');
            $table->json('evidence_components_json')->nullable()->after('evidence_score');
        });
    }

    public function down(): void
    {
        Schema::table('content_candidates', function (Blueprint $table): void {
            $table->dropColumn(['road_code', 'road_kilometer', 'location_evidence_json', 'evidence_score', 'evidence_components_json']);
        });
        Schema::table('incidents', function (Blueprint $table): void {
            $table->dropForeign(['road_id']);
            $table->dropColumn(['road_id', 'road_code', 'road_kilometer', 'road_location_method', 'location_error_meters', 'location_evidence_json', 'impact_method', 'impact_evidence_json']);
        });
        Schema::dropIfExists('source_parser_snapshots');
        Schema::dropIfExists('favorite_zones');
        Schema::dropIfExists('territorial_geometries');
        Schema::dropIfExists('road_reference_points');
        Schema::dropIfExists('road_segments');
        Schema::dropIfExists('road_aliases');
        Schema::dropIfExists('roads');
        Schema::dropIfExists('road_datasets');
    }
};
