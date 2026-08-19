<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('knowledge_brands', function (Blueprint $table): void {
            $table->id();
            $table->string('key', 100)->unique();
            $table->string('name', 200);
            $table->timestamps();
        });

        Schema::create('knowledge_sites', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('brand_id');
            $table->string('key', 100);
            $table->string('canonical_host', 255);
            $table->string('role', 100);
            $table->timestamps();

            $table->foreign('brand_id', 'knowledge_sites_brand_id_foreign')
                ->references('id')
                ->on('knowledge_brands')
                ->restrictOnDelete();
            $table->unique(['brand_id', 'key'], 'knowledge_sites_brand_key_unique');
            $table->unique(['brand_id', 'id'], 'knowledge_sites_brand_id_id_unique');
        });

        Schema::create('knowledge_directories', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('brand_id');
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->string('key', 100);
            $table->string('path', 500);
            $table->string('name', 200);
            $table->timestamps();

            $table->foreign('brand_id', 'knowledge_directories_brand_id_foreign')
                ->references('id')
                ->on('knowledge_brands')
                ->restrictOnDelete();
            $table->unique(['brand_id', 'path'], 'knowledge_directories_brand_path_unique');
            $table->unique(['brand_id', 'id'], 'knowledge_directories_brand_id_id_unique');
            $table->foreign(['brand_id', 'parent_id'], 'knowledge_directories_brand_parent_foreign')
                ->references(['brand_id', 'id'])
                ->on('knowledge_directories')
                ->restrictOnDelete();
        });

        Schema::table('knowledge_bases', function (Blueprint $table): void {
            $table->unsignedBigInteger('brand_id')->nullable();
            $table->unsignedBigInteger('site_id')->nullable();
            $table->unsignedBigInteger('primary_directory_id')->nullable();

            $table->foreign('brand_id', 'knowledge_bases_brand_id_foreign')
                ->references('id')
                ->on('knowledge_brands')
                ->restrictOnDelete();
            $table->foreign(['brand_id', 'site_id'], 'knowledge_bases_brand_site_ownership_foreign')
                ->references(['brand_id', 'id'])
                ->on('knowledge_sites')
                ->restrictOnDelete();
            $table->foreign(['brand_id', 'primary_directory_id'], 'knowledge_bases_brand_directory_ownership_foreign')
                ->references(['brand_id', 'id'])
                ->on('knowledge_directories')
                ->restrictOnDelete();
            $table->index(['brand_id', 'site_id'], 'knowledge_bases_brand_site_ownership_index');
            $table->index(['brand_id', 'primary_directory_id'], 'knowledge_bases_brand_directory_ownership_index');
        });
    }

    public function down(): void
    {
        Schema::table('knowledge_bases', function (Blueprint $table): void {
            $table->dropForeign('knowledge_bases_brand_directory_ownership_foreign');
            $table->dropForeign('knowledge_bases_brand_site_ownership_foreign');
            $table->dropForeign('knowledge_bases_brand_id_foreign');
            $table->dropIndex('knowledge_bases_brand_directory_ownership_index');
            $table->dropIndex('knowledge_bases_brand_site_ownership_index');
            $table->dropColumn(['primary_directory_id', 'site_id', 'brand_id']);
        });

        Schema::dropIfExists('knowledge_directories');
        Schema::dropIfExists('knowledge_sites');
        Schema::dropIfExists('knowledge_brands');
    }
};
