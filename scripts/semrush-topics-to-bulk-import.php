<?php

/**
 * Semrush 主题差距（Topic Gap）导出 → 批量导入文章任务模板（.xlsx）。
 *
 * 用法：
 *   php scripts/semrush-topics-to-bulk-import.php \
 *       --in=~/Downloads/gap_topics_lightinthebox_com_us.csv \
 *       --out=~/Downloads/bulk-content-import.xlsx \
 *       --site="LITB WordPress Test Site (47.103.117.122)" \
 *       --brand="LightInTheBox (lightinthebox)" \
 *       --preset=litb.geo_category_authority.en \
 *       --limit=50
 *
 * 输出严格遵循 BulkContentImportParser 的约定：
 *   第 1 行表头、第 2 行说明（解析时被跳过）、第 3 行起才是数据。
 *
 * site 必须写成 `任意名称 (渠道domain)`：resolveByParenthetical() 只读结尾括号，
 * 裸域名解析不出渠道。preset 用规范 key。category 精确匹配分类名。
 *
 * 注意：Semrush 的差距报告继承的是竞品的整个足迹，含大量不可用主题
 * （竞品品牌导航词、成人内容、政治财经新闻、以及 shein/sheen/Schein 同音噪声）。
 * 本脚本按黑名单剔除后再按机会分排序，剔除结果会打印摘要供人工复核。
 */

$root = dirname(__DIR__);
require $root.'/vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

const HEADERS = ['site', 'preset', 'category', 'topic', 'brand', 'directory', 'knowledge_site', 'core_question', 'primary_keyword', 'reference_urls'];

const INSTRUCTIONS = [
    'site' => '【必填】发布渠道，必须是 名称 (domain) 形式',
    'preset' => '【必填】内容模板 preset_key',
    'category' => '【必填】文章分类，精确匹配分类名',
    'topic' => '【必填】选题方向，最终标题由 AI 生成',
    'brand' => '【必填】知识来源品牌',
    'directory' => '【选填】知识目录',
    'knowledge_site' => '【选填】知识来源站点，品牌只有一个来源站点时留空',
    'core_question' => '【选填】需重点回答的问题',
    'primary_keyword' => '【选填】核心关键词',
    'reference_urls' => '【选填】参考链接，每行一个',
];

/** 明显不能作为自有品牌内容发布的主题。 */
const REJECT_ADULT = '/(\bnude\b|\bporn|erotic|adult content|\bsexy\b|\banal toys\b|upskirt|wet t-shirt|\bleaks\b|crossdress|\bsissy\b|uncensored|bikini girls|nipple|mail order brides)/i';

/** 与电商选品无关：财经、政治、医疗、名人、影视、游戏、餐饮、旅行、通用软硬件。 */
const REJECT_OFFTOPIC = '/(stock|\bipo\b|invest|dow jones|market updates|financial news|tariff|trade polic|trade relation|de minimis|harmonized tariff|customs|insurance|pregnan|weight loss|supplement|vitamin|sciatic|psoriasis|dandruff|semaglutide|insulin|injur|\bpain\b|lawyer|legal|law firm|jury duty|\bcnn\b|forbes|\bnews\b|celebrit|sheen|schein|\bshen\b|\bsein\b|movie|film|godzilla|roblox|gaming|league of legends|ramen|noodle|recipe|cuisine|dim sum|pineapple cake|melon|coffee|airport|airline|hotel|ghana|hong kong|east asia|europe and eu|\bmeme\b|tattoo|domain name|web develop|machine learning|app develop|smartphone|tablet|chromebook|\bssd\b|headphone|earbud|imei|scam|phishing|boycott|palestine|israel|\bfbi\b|trump|labubu|blox fruits|sweatshop|greenwashing|fast fashion and environmental)/i';

/** 竞品/第三方品牌导航词：搜索者要找的是别人的站点。 */
const REJECT_COMPETITOR = '/^(shein|temu|shopee|romwe|sheglam|sheinus|manfinity|boohoo|asos|zara|h&m|forever 21|jcpenney|j\.c\. penney|kohl|macy|uniqlo|fashion nova|halara|cider|edikted|princess polly|hello molly|meshki|showpo|nasty gal|missguided|prettylittlething|lulus|cupshe|peppermayo|petal and pup|selfie leslie|beginning boutique|12th tribe|lime lush|loragal|uoozee|xpluswear|curvedream|modlily|dresslily|patpat|bloomchic|shecurve|rihoas|sopula|fehaute|lacemade|babyboo|naked wardrobe|pink lily|garage|bershka|stussy|superdry|hollister|brandy melville|urban outfitters|anthropologie|everlane|american giant|brooks brothers|next retail|zalando|thredup|depop|plato|crossroads|goodwill|rue 21|charlotte russe|claire|miniso|maurices|stein mart|zulily|coofandy|valabasas|no boundaries|faded glory|south pole|izod|525|yas |gia |mable|cherie|q clothing|selected items|got it|jane app|blidz|lewkin|ogl move|atolea|evry|emi jay|foodology|prosa|carotone|zoyava|cata-kor|curvlife|bersache|lensmart|oritain|thg|musinsa|meesho|magazine luiza|iherb|dippin|hill house|selkie|skims|lululemon|nike|birkenstock|aldo|casio|jbl|redmi|hp deskjet|apple watch|iphone|ipad|samsung|walmart|amazon|target|costco|etsy|ebay|alibaba|aliexpress|wish|klarna|afterpay|affirm|sezzle|zip pay|rakuten|honey|usps|ups|fedex|dhl|ontrac|yunexpress|yanwen|17track|4px|speedx|uniuni|auctane|shipstation|pitney)/i';

/**
 * 分类映射：从最具体到最宽泛，命中即停。
 * 只使用目标库里真实存在的分类名。
 */
const CATEGORY_RULES = [
    ['/(\bvs\b|versus|alternative|similar to|comparison|compare|\bdupe|instead of|like shein)/i', 'Comparisons'],
    ['/(shipping|tracking|delivery|parcel|package|courier|logistic|freight|return polic|returns|refund|buy now pay later|\bbnpl\b|payment|installment|checkout|order status|fulfillment)/i', 'Shipping, Payment & Returns'],
    ['/(gift|christmas|halloween|birthday|wedding|anniversary|mother\'s day|father\'s day|valentine|graduation|baby shower|bridal|party|holiday|occasion|prom|stocking stuffer|celebration)/i', 'Inspiration & Occasions'],
    // 只匹配真正的答疑/教程意图。这里不能出现裸的 care / clean：
    // 它们会命中 "skincare"、"Steam Cleaners" 这类商品词，把选品主题误判成帮助中心。
    ['/(size chart|size guide|size conversion|\bconversion\b|how to|what is|\bmeaning\b|\bdefinition\b|\bfaq\b|troubleshoot|care guide|how to wash|shrinkage)/i', 'FAQ & Help Center'],
    ['/(trend|2024|2025|new arrival|latest)/i', 'New Arrivals'],
    ['/(dress|shirt|top|blouse|pant|trouser|jean|denim|skirt|jacket|coat|sweater|cardigan|hoodie|legging|swimwear|swimsuit|bikini|lingerie|underwear|bra|pajama|sleepwear|shoe|boot|sandal|slipper|heel|sneaker|wallet|bag|tote|jewelry|necklace|bracelet|earring|watch|hat|scarf|accessor|apparel|clothing|fashion|outfit|style|wear|suit|jumpsuit|romper|shapewear|sock)/i', 'Style & Buying Guides'],
];

const CATEGORY_FALLBACK = 'LightInTheBox Guides';

function arg(string $name, ?string $default = null): ?string
{
    foreach ($GLOBALS['argv'] as $a) {
        if (str_starts_with($a, "--{$name}=")) {
            $v = substr($a, strlen($name) + 3);

            return str_starts_with($v, '~') ? getenv('HOME').substr($v, 1) : $v;
        }
    }

    return $default;
}

function parseIntents(string $raw): array
{
    $out = [];
    foreach (explode(';', $raw) as $part) {
        if (! str_contains($part, ':')) {
            continue;
        }
        [$k, $v] = explode(':', $part, 2);
        $out[trim($k)] = (int) $v;
    }

    return $out;
}

/**
 * 机会分：成交量取对数压缩长尾，商业/交易意图加权，难度折价。
 * 三个因子都可解释，便于人工复核排序结果。
 */
function opportunityScore(int $volume, int $difficulty, array $intents): float
{
    $commercial = ($intents['commercial'] ?? 0) + ($intents['transactional'] ?? 0);

    return log10(max(10, $volume))
        * (0.5 + ($commercial / 100))
        * (1 - (min(100, max(0, $difficulty)) / 150));
}

function mapCategory(string $topic): string
{
    foreach (CATEGORY_RULES as [$pattern, $category]) {
        if (preg_match($pattern, $topic) === 1) {
            return $category;
        }
    }

    return CATEGORY_FALLBACK;
}

function rejectionReason(string $topic): ?string
{
    if (preg_match('//u', $topic) === false || preg_match('/[^\x00-\x7F]/', $topic) === 1) {
        return 'non_english';
    }
    if (preg_match(REJECT_ADULT, $topic) === 1) {
        return 'adult';
    }
    if (preg_match(REJECT_COMPETITOR, $topic) === 1) {
        return 'competitor_brand';
    }
    if (preg_match(REJECT_OFFTOPIC, $topic) === 1) {
        return 'off_topic';
    }

    return null;
}

// ---------------------------------------------------------------- run

$in = arg('in') ?? exit("--in=<semrush.csv> is required\n");
$out = arg('out') ?? getenv('HOME').'/Downloads/bulk-content-import.xlsx';
$site = arg('site') ?? exit("--site=\"Name (domain)\" is required\n");
$brand = arg('brand') ?? exit("--brand=\"Name (key)\" is required\n");
$preset = arg('preset') ?? exit("--preset=<preset_key> is required\n");
$limit = (int) (arg('limit') ?? '50');

if (! is_readable($in)) {
    exit("Cannot read {$in}\n");
}

$handle = fopen($in, 'r');
$header = fgetcsv($handle);
$index = array_flip(array_map('trim', $header));
foreach (['name', 'difficulty', 'volume', 'intents'] as $required) {
    if (! isset($index[$required])) {
        exit("Input CSV is missing the \"{$required}\" column — is this a Semrush topic gap export?\n");
    }
}

$kept = [];
$rejected = [];
while (($row = fgetcsv($handle)) !== false) {
    $topic = trim((string) ($row[$index['name']] ?? ''));
    if ($topic === '') {
        continue;
    }

    $reason = rejectionReason($topic);
    if ($reason !== null) {
        $rejected[$reason][] = $topic;

        continue;
    }

    $volume = (int) ($row[$index['volume']] ?? 0);
    $difficulty = (int) ($row[$index['difficulty']] ?? 0);
    $intents = parseIntents((string) ($row[$index['intents']] ?? ''));

    $kept[] = [
        'topic' => $topic,
        'volume' => $volume,
        'difficulty' => $difficulty,
        'category' => mapCategory($topic),
        'score' => opportunityScore($volume, $difficulty, $intents),
    ];
}
fclose($handle);

usort($kept, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);
$selected = array_slice($kept, 0, max(1, $limit));

$spreadsheet = new Spreadsheet;
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle('导入数据');

foreach (HEADERS as $i => $key) {
    $sheet->setCellValue([$i + 1, 1], $key);
    $sheet->setCellValue([$i + 1, 2], INSTRUCTIONS[$key]);
}

// 第 3 行起才是数据：解析器无条件跳过第 2 行说明行。
$rowNumber = 3;
foreach ($selected as $item) {
    $values = [
        $site,
        $preset,
        $item['category'],
        $item['topic'],
        $brand,
        '',
        '',
        '',
        mb_strtolower($item['topic']),
        '',
    ];
    foreach ($values as $i => $value) {
        $sheet->setCellValueExplicit([$i + 1, $rowNumber], $value, DataType::TYPE_STRING);
    }
    $rowNumber++;
}

foreach (range(1, count(HEADERS)) as $column) {
    $sheet->getColumnDimensionByColumn($column)->setAutoSize(true);
}

(new Xlsx($spreadsheet))->save($out);

// ---------------------------------------------------------------- report

$rejectedTotal = array_sum(array_map('count', $rejected));
printf("Read     : %s\n", $in);
printf("Rejected : %d\n", $rejectedTotal);
foreach ($rejected as $reason => $topics) {
    printf("   %-18s %4d   e.g. %s\n", $reason, count($topics), $topics[0]);
}
printf("Usable   : %d\n", count($kept));
printf("Written  : %d rows → %s\n\n", count($selected), $out);

$byCategory = [];
foreach ($selected as $item) {
    $byCategory[$item['category']] = ($byCategory[$item['category']] ?? 0) + 1;
}
arsort($byCategory);
echo "Category split:\n";
foreach ($byCategory as $category => $count) {
    printf("   %-30s %d\n", $category, $count);
}

echo "\nTop 15 selected:\n";
foreach (array_slice($selected, 0, 15) as $i => $item) {
    printf("  %2d. [%-28s] vol=%-9s kd=%-3d %s\n", $i + 1, $item['category'], number_format($item['volume']), $item['difficulty'], $item['topic']);
}
