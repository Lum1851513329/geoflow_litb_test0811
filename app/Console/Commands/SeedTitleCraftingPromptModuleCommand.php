<?php

namespace App\Console\Commands;

use App\Domain\PromptComposition\PromptModuleService;
use App\Models\Admin;
use App\Models\PromptModule;
use Illuminate\Console\Command;
use RuntimeException;

final class SeedTitleCraftingPromptModuleCommand extends Command
{
    protected $signature = 'geoflow:content-strategy:seed-title-crafting-module';

    protected $description = 'Create the title_crafting mandatory prompt module and publish updated seo_structure guidance';

    private const TITLE_CRAFTING_CONTENT = <<<'PROMPT'
Write the article title as the very first line of your output, formatted as a single Markdown H1 ("# Title text"), before any other content. The brief's topic is a directional input describing what to write about — it is not the title; do not copy it verbatim.

Write a genuine headline using real headline-writing practice: lead with the concrete benefit, answer, or specific subject rather than a generic label ("A Guide to X" is not acceptable). Name the actual product, category, or comparison involved. Match the register the content calls for: a news headline states what happened; a buying-guide or how-to headline states the decision or outcome the reader gets; an FAQ headline poses or answers the actual question. Keep the title to roughly 50-60 characters where the content allows it, without sacrificing clarity for length. Do not use clickbait phrasing or unverifiable superlatives.
PROMPT;

    private const SEO_STRUCTURE_ADDITION = "\n\nEach blueprint section's \"heading\" field is a role label describing that section's purpose, not literal text to reproduce. Write a natural, specific H2 for every section, grounded in its stated purpose and the article's actual topic. Do not reuse a section's heading label verbatim, and do not reuse the same generic heading wording across different articles.";

    public function handle(PromptModuleService $modules): int
    {
        $actor = Admin::query()->where('username', 'geoflow-system')->first();
        if (! $actor) {
            $this->error('Run "geoflow:content-strategy:seed-system-admin" first (Task 10) — no geoflow-system admin found.');

            return self::FAILURE;
        }

        $this->seedTitleCraftingModule($modules, $actor);
        $this->publishSeoStructureAddition($modules, $actor);

        return self::SUCCESS;
    }

    private function seedTitleCraftingModule(PromptModuleService $modules, Admin $actor): void
    {
        $existing = PromptModule::query()->where('module_key', 'title_crafting')->first();
        if ($existing !== null) {
            $this->info('title_crafting module already exists, skipping creation.');

            return;
        }

        $module = $modules->createModule([
            'module_key' => 'title_crafting',
            'display_name' => 'Title crafting',
            'module_type' => PromptModule::TYPE_OUTPUT_FORMAT,
            'description' => 'Instructs the model to write a genuine, headline-quality article title instead of reusing the brief topic verbatim.',
            'is_system' => true,
            'applicability' => null,
            'is_mandatory' => true,
            'default_priority' => 150,
            'is_enabled' => true,
        ], [
            'content' => self::TITLE_CRAFTING_CONTENT,
            'variable_contract' => [],
        ], $actor);

        $version = $module->currentVersion()->firstOrFail();
        $modules->publish($version, $actor, 'Initial publish of the title_crafting mandatory module.');
        $this->info('Created and published title_crafting module version '.$version->id);
    }

    private function publishSeoStructureAddition(PromptModuleService $modules, Admin $actor): void
    {
        $module = PromptModule::query()->where('module_key', 'seo_structure')->first();
        if (! $module) {
            throw new RuntimeException('seo_structure module not found — is PromptCompositionSeeder seeded in this environment?');
        }

        $current = $module->currentVersion;
        if ($current !== null && str_contains((string) $current->content, "role label describing that section's purpose")) {
            $this->info('seo_structure already has heading-guidance addition, skipping.');

            return;
        }

        $newContent = trim((string) $current?->content).self::SEO_STRUCTURE_ADDITION;
        $version = $modules->createVersion($module, [
            'content' => $newContent,
            'variable_contract' => [],
        ], $actor);
        $modules->publish($version, $actor, 'Add AI-phrased heading guidance so headings are not copied verbatim from blueprint labels.');
        $this->info('Published updated seo_structure module version '.$version->id);
    }
}
