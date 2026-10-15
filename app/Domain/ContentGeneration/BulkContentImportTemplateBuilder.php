<?php

namespace App\Domain\ContentGeneration;

use App\Models\Category;
use App\Models\DistributionChannel;
use App\Models\KnowledgeBrand;
use App\Models\KnowledgeDirectory;
use App\Models\KnowledgeSite;
use App\Models\PromptPreset;
use App\Models\PromptPresetVersion;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

final class BulkContentImportTemplateBuilder
{
    private const HEADERS = ['site', 'preset', 'category', 'topic', 'brand', 'directory', 'knowledge_site', 'core_question', 'primary_keyword', 'reference_urls', 'knowledge_mode', 'content_type', 'external_evidence_policy', 'external_general_domains', 'brand_profile_snapshot_hash'];

    private const INSTRUCTIONS = [
        'site' => '【必填】发布到哪个站点，从"可选值参考"表中选，不要手打。',
        'preset' => '【必填】使用哪个内容模板，从"可选值参考"表中选。同一分类下可能有多个模板，请核对模板名称。',
        'category' => '【必填】文章分类，从"可选值参考"表中选。',
        'topic' => '【必填】选题方向，写清楚主题即可，不用是完整标题（最终标题由AI生成）。',
        'brand' => '【必填】知识来源品牌，从"可选值参考"表中选，也可填写品牌 key 或唯一名称。系统自动检索品牌资料，不导入商品图片/视频。',
        'directory' => '【选填】限定知识目录及子目录。选择对应品牌的完整路径；重名目录必须用完整路径。',
        'knowledge_site' => '【选填】知识来源站点，与 site 发布渠道不同。品牌只有一个来源站点时可留空，多站点时必须指定。',
        'core_question' => '【选填】文章需要重点回答的具体问题，不填则由选题方向自动推导。',
        'primary_keyword' => '【选填】核心关键词。',
        'reference_urls' => '【选填】参考链接，每行一个；品牌自动检索模式仅保存链接，本期不抓取外部链接。',
        'knowledge_mode' => '【选填】knowledge_mode：brand_auto（品牌资料）、general（仅外部/通用证据）、mixed（品牌与外部证据）；留空时按 brand 自动判断，旧版行保持 legacy_manual。',
        'content_type' => '【选填】内容类型，例如 educational、shopping_advice、comparison、industry、brand_policy；用于路由 Prompt 和证据策略。',
        'external_evidence_policy' => '【选填】off 或 on_insufficient。泛科普、购物建议、行业话题建议使用 on_insufficient。',
        'external_general_domains' => '【选填】允许的通用来源域名，逗号或空格分隔，例如 wikipedia.org, who.int。',
        'brand_profile_snapshot_hash' => '【选填】Semrush 预览生成的品牌配置快照，用于确认时防止品牌配置已变更；手工导入可留空。',
    ];

    /**
     * 示例行的 `site` 单元格**不能**带任何可解析的尾部括号。
     * `BulkContentImportParser::resolveByParenthetical()` 只读结尾的 `(...)`，完全忽略
     * 前缀里的"示例——请删除"提示；此前示例里放的是一个真实存在的渠道域名
     * `(blog.lightinthebox.com)`，于是在示例中的分类/模板/知识库名称恰好也存在的环境上，
     * 两行示例会被当成真实数据导入。这里改成一个永远不可能匹配任何渠道 domain 的
     * 假括号内容，保证 `resolveByParenthetical()` 在任何环境下都返回 null、该行必定
     * 校验失败。对应回归测试：`BulkContentImportTemplateBuilderTest`
     * 的模板往返用例（build() → parse() 必须 0 行 + 至少 1 条错误）。
     */
    private const EXAMPLE_ROWS = [
        ['示例——请删除后再填真实数据 (示例-请勿使用)', 'Category Authority Guide (litb.geo_category_authority.en)', 'Style & Buying Guides', 'How to Choose Running Shoes', 'LITB (litb)', '', '', 'What should a first-time buyer check?', 'running shoes', '', 'brand_auto', 'shopping_advice', 'on_insufficient', 'wikipedia.org', ''],
        ['示例——请删除后再填真实数据 (示例-请勿使用)', 'Category Authority Guide (litb.geo_category_authority.en)', 'Style & Buying Guides', 'Choosing the Right Duvet Cover', 'LITB (litb)', '', '', '', 'duvet cover', 'https://example.com/care-guide', 'brand_auto', 'educational', 'on_insufficient', '', ''],
    ];

    public function build(): Spreadsheet
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('导入数据');

        foreach (self::HEADERS as $index => $header) {
            $sheet->setCellValue([$index + 1, 1], $header);
            $sheet->setCellValue([$index + 1, 2], self::INSTRUCTIONS[$header]);
        }
        foreach (self::EXAMPLE_ROWS as $rowIndex => $row) {
            foreach ($row as $colIndex => $value) {
                $sheet->setCellValue([$colIndex + 1, 3 + $rowIndex], $value);
            }
        }

        $referenceSheet = $spreadsheet->createSheet();
        $referenceSheet->setTitle('可选值参考');
        $siteValues = $this->siteValues();
        $presetValues = $this->presetValues();
        $categoryValues = $this->categoryValues();

        $referenceSheet->setCellValue('A1', '发布渠道 site（非知识来源）');
        $referenceSheet->setCellValue('B1', '内容模板 preset');
        $referenceSheet->setCellValue('C1', '分类 category');
        foreach ($siteValues as $index => $value) {
            $referenceSheet->setCellValue('A'.($index + 2), $value);
        }
        foreach ($presetValues as $index => $value) {
            $referenceSheet->setCellValue('B'.($index + 2), $value);
        }
        foreach ($categoryValues as $index => $value) {
            $referenceSheet->setCellValue('C'.($index + 2), $value);
        }

        $this->addDropdownValidation($sheet, 'A', count($siteValues), $referenceSheet, 'A', count($siteValues));
        $this->addDropdownValidation($sheet, 'B', count($presetValues), $referenceSheet, 'B', count($presetValues));
        $this->addDropdownValidation($sheet, 'C', count($categoryValues), $referenceSheet, 'C', count($categoryValues));

        $scopeValues = [
            ['D', 'E', '知识品牌 brand', KnowledgeBrand::orderBy('name')->get()->map(fn ($brand) => "{$brand->name} ({$brand->key})")->all()],
            ['E', 'F', '知识目录 directory（品牌 / 路径）', KnowledgeDirectory::with('brand')->orderBy('brand_id')->orderBy('path')->get()->map(fn ($directory) => "{$directory->brand->key} / {$directory->name} ({$directory->path})")->all()],
            ['F', 'G', '知识来源 knowledge_site（非发布渠道）', KnowledgeSite::with('brand')->orderBy('brand_id')->orderBy('key')->get()->map(fn ($site) => "{$site->brand->key} / {$site->canonical_host} ({$site->key})")->all()],
        ];
        foreach ($scopeValues as [$referenceColumn, $mainColumn, $label, $values]) {
            $referenceSheet->setCellValue($referenceColumn.'1', $label);
            foreach ($values as $index => $value) {
                $referenceSheet->setCellValueExplicit($referenceColumn.($index + 2), $value, DataType::TYPE_STRING);
            }
            $this->addDropdownValidation($sheet, $mainColumn, count($values), $referenceSheet, $referenceColumn, count($values));
        }

        $spreadsheet->setActiveSheetIndex(0);

        return $spreadsheet;
    }

    /** @return list<string> */
    private function siteValues(): array
    {
        return DistributionChannel::query()
            ->where('status', DistributionChannel::STATUS_ACTIVE)
            ->orderBy('name')
            ->get(['name', 'domain'])
            ->map(fn (DistributionChannel $channel): string => "{$channel->name} ({$channel->domain})")
            ->all();
    }

    /** @return list<string> */
    private function presetValues(): array
    {
        return PromptPreset::query()
            ->whereHas('currentVersion', fn ($q) => $q->where('lifecycle_status', PromptPresetVersion::STATUS_PRODUCTION)->whereNotNull('blueprint_template'))
            ->orderBy('display_name')
            ->get(['display_name', 'preset_key'])
            ->map(fn (PromptPreset $preset): string => "{$preset->display_name} ({$preset->preset_key})")
            ->all();
    }

    /** @return list<string> */
    private function categoryValues(): array
    {
        return Category::query()->orderBy('name')->pluck('name')->all();
    }

    private function addDropdownValidation(
        Worksheet $sheet,
        string $column,
        int $optionCount,
        Worksheet $referenceSheet,
        string $referenceColumn,
        int $referenceCount,
    ): void {
        if ($optionCount === 0) {
            return;
        }

        $referenceRange = "'".$referenceSheet->getTitle()."'!\${$referenceColumn}\$2:\${$referenceColumn}\$".($referenceCount + 1);
        for ($row = 3; $row <= 200; $row++) {
            $validation = $sheet->getCell("{$column}{$row}")->getDataValidation();
            $validation->setType(DataValidation::TYPE_LIST);
            $validation->setErrorStyle(DataValidation::STYLE_STOP);
            $validation->setAllowBlank(true);
            $validation->setShowDropDown(true);
            $validation->setFormula1($referenceRange);
        }
    }
}
