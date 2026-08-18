<?php

namespace App\Domain\ContentGeneration;

use App\Models\Category;
use App\Models\DistributionChannel;
use App\Models\PromptPreset;
use App\Models\PromptPresetVersion;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

final class BulkContentImportTemplateBuilder
{
    private const HEADERS = ['site', 'preset', 'category', 'topic', 'knowledge_base', 'core_question', 'primary_keyword', 'reference_urls'];

    private const INSTRUCTIONS = [
        'site' => '【必填】发布到哪个站点，从"可选值参考"表中选，不要手打。',
        'preset' => '【必填】使用哪个内容模板，从"可选值参考"表中选。同一分类下可能有多个模板，请核对模板名称。',
        'category' => '【必填】文章分类，从"可选值参考"表中选。',
        'topic' => '【必填】选题方向，写清楚主题即可，不用是完整标题（最终标题由AI生成）。',
        'knowledge_base' => '【必填】写作时参考的知识库名称，从"可选值参考"表中选。同一站点/模板/分类分组内必须一致。',
        'core_question' => '【选填】文章需要重点回答的具体问题，不填则由选题方向自动推导。',
        'primary_keyword' => '【选填】核心关键词。',
        'reference_urls' => '【选填】参考资料链接，一个单元格内每行一个链接。',
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
        ['示例——请删除后再填真实数据 (示例-请勿使用)', 'Category Authority Guide (litb.geo_category_authority.en)', 'Style & Buying Guides', 'How to Choose Running Shoes', 'LITB Product KB', 'What should a first-time buyer check?', 'running shoes', ''],
        ['示例——请删除后再填真实数据 (示例-请勿使用)', 'Category Authority Guide (litb.geo_category_authority.en)', 'Style & Buying Guides', 'Choosing the Right Duvet Cover', 'LITB Product KB', '', 'duvet cover', 'https://example.com/care-guide'],
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

        $referenceSheet->setCellValue('A1', '站点 site');
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
