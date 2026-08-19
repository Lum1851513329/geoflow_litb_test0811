<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
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

        $this->addOwnershipTupleConstraint();
    }

    public function down(): void
    {
        $this->dropOwnershipTupleConstraint();

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

    private function addOwnershipTupleConstraint(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            $this->createSqliteOwnershipTupleTrigger('insert', 'INSERT');
            $this->createSqliteOwnershipTupleTrigger('update', 'UPDATE OF brand_id, site_id, primary_directory_id');

            return;
        }

        DB::statement(<<<'SQL'
ALTER TABLE knowledge_bases
ADD CONSTRAINT knowledge_bases_ownership_tuple_check
CHECK (
    (brand_id IS NULL AND site_id IS NULL AND primary_directory_id IS NULL)
    OR
    (brand_id IS NOT NULL AND site_id IS NOT NULL AND primary_directory_id IS NOT NULL)
)
SQL);
    }

    private function dropOwnershipTupleConstraint(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            DB::statement('DROP TRIGGER IF EXISTS knowledge_bases_ownership_tuple_insert_check');
            DB::statement('DROP TRIGGER IF EXISTS knowledge_bases_ownership_tuple_update_check');

            return;
        }

        DB::statement('ALTER TABLE knowledge_bases DROP CONSTRAINT knowledge_bases_ownership_tuple_check');
    }

    private function createSqliteOwnershipTupleTrigger(string $suffix, string $event): void
    {
        DB::unprepared(<<<SQL
CREATE TRIGGER knowledge_bases_ownership_tuple_{$suffix}_check
BEFORE {$event} ON knowledge_bases
FOR EACH ROW
WHEN NOT (
    (NEW.brand_id IS NULL AND NEW.site_id IS NULL AND NEW.primary_directory_id IS NULL)
    OR
    (NEW.brand_id IS NOT NULL AND NEW.site_id IS NOT NULL AND NEW.primary_directory_id IS NOT NULL)
)
BEGIN
    SELECT RAISE(ABORT, 'knowledge_bases ownership must be all null or all non-null');
END
SQL);
    }
};
