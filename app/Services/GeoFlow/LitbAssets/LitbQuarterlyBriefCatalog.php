<?php

namespace App\Services\GeoFlow\LitbAssets;

use Illuminate\Support\Str;
use Illuminate\Support\Carbon;

final class LitbQuarterlyBriefCatalog
{
    /** @var array<string, list<array{0:string,1:string,2:string}>> */
    private const TOPICS = [
        'category_authority' => [
            ['wedding dresses', 'Wedding Dresses: A Complete Style, Fit, and Planning Guide', 'How do you choose a wedding dress that fits the event, body, budget, and timeline?'],
            ['bridesmaid dresses', 'Bridesmaid Dresses: How to Coordinate Style, Colour, and Fit', 'How can a bridal party coordinate bridesmaid dresses without sacrificing individual fit?'],
            ['mother of the bride dresses', 'Mother of the Bride Dresses: Etiquette, Comfort, and Style', 'What should you consider when choosing a mother of the bride dress?'],
            ['wedding guest dresses', 'Wedding Guest Dresses: A Dress-Code Guide for Every Celebration', 'How do you choose a wedding guest dress for the venue, season, and dress code?'],
            ['cocktail dresses', 'Cocktail Dresses: Length, Fit, Fabric, and Occasion Guide', 'What makes a dress appropriate for a cocktail event?'],
            ['special occasion dresses', 'Special Occasion Dresses: A Practical Buying Guide', 'How can you choose a special occasion dress you will feel comfortable wearing?'],
            ['women clothing', "Women's Clothing: Building a Versatile Wardrobe by Occasion", 'Which clothing categories create a practical and versatile wardrobe?'],
            ['men clothing', "Men's Clothing: A Guide to Fit, Function, and Versatility", 'How do you select menswear that works across daily and formal settings?'],
            ['custom suits', 'Custom Suits: Measurements, Style Choices, and Ordering Guide', 'What should shoppers understand before ordering a custom suit?'],
            ['shoes and bags', 'Shoes and Bags: How to Choose Useful, Coordinated Accessories', 'How should shoes and bags balance comfort, function, and style?'],
            ['home and garden', 'Home and Garden Shopping: A Room-by-Room Planning Guide', 'How can shoppers plan home and garden purchases by space and purpose?'],
            ['home textiles', 'Home Textiles: Materials, Measurements, and Care Guide', 'What should you check before buying home textiles online?'],
            ['lights and lighting', 'Lights and Lighting: Choosing Fixtures by Room and Purpose', 'How do you choose lighting by room, task, size, and installation needs?'],
            ['phones and accessories', 'Phone Accessories: Compatibility, Protection, and Everyday Use', 'How can you verify that a phone accessory is compatible and useful?'],
            ['beauty and hair', 'Beauty and Hair Products: A Needs-First Selection Guide', 'How should shoppers evaluate beauty and hair products without relying on hype?'],
            ['baby and kids', 'Baby and Kids Products: Age, Size, Material, and Safety Questions', 'Which questions matter most when selecting baby and kids products online?'],
            ['sports and outdoors', 'Sports and Outdoor Products: Choosing for Activity and Conditions', 'How do you match sports and outdoor products to activity, climate, and ability?'],
            ['automotive accessories', 'Automotive Accessories: Compatibility and Use-Case Guide', 'How can shoppers check whether an automotive accessory fits their vehicle and use case?'],
        ],
        'product_comparison' => [
            ['wedding dresses', 'A-Line vs Ball Gown Wedding Dresses: Which Shape Fits Your Plans?', 'What are the practical differences between A-line and ball gown wedding dresses?'],
            ['bridesmaid dresses', 'Matching vs Mix-and-Match Bridesmaid Dresses', 'When should a bridal party use matching or mix-and-match bridesmaid dresses?'],
            ['formal dresses', 'Cocktail vs Evening Dresses: Understanding the Difference', 'How do cocktail and evening dresses differ in formality, length, and styling?'],
            ['custom suits', 'Custom vs Standard-Size Suits: Trade-Offs to Consider', 'What are the trade-offs between custom and standard-size suits?'],
            ['women shoes', 'Heels vs Flats for Events: Comfort and Dress-Code Trade-Offs', 'Should you choose heels or flats for a long formal event?'],
            ['bedding', 'Duvet Covers vs Comforters: Which Bedding Layer Do You Need?', 'What is the difference between a duvet cover and a comforter?'],
            ['home lighting', 'Pendant, Ceiling, and Wall Lights: Where Each Works Best', 'How do pendant, ceiling, and wall lights serve different room needs?'],
            ['mobile accessories', 'Phone Cases vs Screen Protectors: What Each Protects', 'Do you need a phone case, a screen protector, or both?'],
            ['hair products', 'Synthetic vs Human-Hair Wigs: A Practical Comparison', 'How do synthetic and human-hair wigs differ in care, look, and use?'],
            ['sports products', 'Indoor vs Outdoor Training Gear: What Changes?', 'Which product features change between indoor and outdoor training?'],
            ['toys and hobbies', 'Creative Kits vs Open-Ended Hobby Supplies', 'How do guided kits and open-ended hobby supplies support different users?'],
            ['custom print products', 'Personalised Gifts vs Ready-Made Gifts', 'When does personalisation add meaningful value to a gift?'],
        ],
        'style_occasion' => [
            ['wedding guest dresses', 'What to Wear to a Garden Wedding', 'How can a guest dress comfortably and appropriately for a garden wedding?'],
            ['beach wedding dresses', 'Beach Wedding Dress Ideas for Movement, Heat, and Wind', 'Which dress features work well for a beach ceremony?'],
            ['bridesmaid dresses', 'Bridesmaid Colour Palettes That Work Across Seasons', 'How can bridesmaid colours be adapted to different seasons?'],
            ['mother of the bride dresses', 'Mother of the Bride Outfit Ideas for Day-to-Evening Events', 'How can a mother of the bride outfit work across a long wedding day?'],
            ['cocktail dresses', 'Cocktail Outfit Ideas for Work Events and Celebrations', 'How can cocktail attire be adapted to different social settings?'],
            ['women outfits', 'Travel-Friendly Outfit Ideas Built Around Reusable Layers', 'How can you plan travel outfits with fewer, more reusable pieces?'],
            ['men fashion', "Men's Smart-Casual Outfit Formula for Different Occasions", 'What pieces create a reliable smart-casual outfit?'],
            ['fashion accessories', 'How to Use Accessories Without Overcomplicating an Outfit', 'How can accessories add interest while keeping an outfit balanced?'],
            ['home lighting', 'Layered Lighting Ideas for Living Rooms', 'How can ambient, task, and accent lighting work together?'],
            ['home textiles', 'Seasonal Bedroom Refresh Ideas Using Textiles', 'How can textiles refresh a bedroom without changing major furniture?'],
            ['personalized print products', 'Personalised Gift Ideas by Recipient and Occasion', 'How can you choose a personalised gift that feels relevant?'],
            ['holiday products', 'A Reusable Holiday Decorating Plan for Small Spaces', 'How can small spaces be decorated for holidays with reusable items?'],
        ],
        'new_arrivals' => [
            ['LightInTheBox new arrivals', 'This Week at LightInTheBox: New Arrivals to Review', 'Which newly listed products are worth a closer look this week?'],
            ['new dresses', 'New Dress Arrivals This Week: Shapes, Colours, and Occasions', 'What themes appear in this week’s new dress listings?'],
            ['new home products', 'New Home and Lighting Arrivals This Week', 'Which new home and lighting products address practical room needs?'],
            ['new accessories', 'New Shoes, Bags, and Accessories This Week', 'Which newly listed accessories combine function and style?'],
            ['new menswear', 'New Menswear Arrivals This Month', 'What new menswear options were listed this month?'],
            ['new wedding dresses', 'New Wedding and Event Styles This Month', 'Which new wedding and event styles were listed this month?'],
            ['new electronics accessories', 'New Phone and Electronics Accessories This Month', 'Which new accessories have clearly documented compatibility and uses?'],
            ['new seasonal products', 'New Seasonal Products to Plan for This Month', 'Which current seasonal additions have verified product evidence?'],
            ['LightInTheBox monthly new arrivals', 'LightInTheBox Monthly New-Arrivals Review', 'What can shoppers learn from this month’s verified new listings?'],
        ],
        'product_education' => [
            ['dress size guide', 'How to Measure for a Dress Ordered Online', 'Which body measurements should you take before choosing a dress size?'],
            ['wedding dress fabric', 'Wedding Dress Fabrics Explained', 'How do common wedding dress fabrics affect structure, movement, and care?'],
            ['dress silhouette guide', 'Dress Silhouettes Explained: A-Line, Sheath, Mermaid, and More', 'How do common dress silhouettes differ?'],
            ['custom suit measurements', 'How to Take Measurements for a Custom Suit', 'How should suit measurements be taken and checked?'],
            ['shoe size guide', 'How to Measure Your Feet for Shoes Bought Online', 'How can you measure foot length and width accurately?'],
            ['bedding size guide', 'How to Check Bedding Sizes Before Ordering', 'Which mattress, duvet, and pillow measurements should you compare?'],
            ['lighting size guide', 'How to Size a Light Fixture for a Room', 'How can room and furniture dimensions guide fixture size?'],
            ['phone accessory compatibility', 'How to Check Phone Accessory Compatibility', 'Which model numbers, ports, dimensions, and standards should you verify?'],
            ['wig care guide', 'Wig Care Basics: Storage, Washing, and Everyday Handling', 'How should different wigs be handled and maintained?'],
        ],
        'customer_education' => [
            ['LightInTheBox shipping', 'How LightInTheBox Shipping Information Works', 'Where should shoppers check available methods and current delivery estimates?'],
            ['estimated delivery time', 'Processing Time vs Shipping Time: Understanding Delivery Estimates', 'What is the difference between processing and shipping time?'],
            ['LightInTheBox returns', 'How to Review LightInTheBox Return Information Before Ordering', 'What should shoppers verify about return eligibility and process?'],
            ['LightInTheBox payment methods', 'LightInTheBox Payment Methods: What to Check at Checkout', 'How can shoppers confirm which payment methods are available?'],
            ['secure payment', 'Online Payment Safety: Practical Checks Before Checkout', 'Which checks help shoppers use online payment methods safely?'],
            ['LightInTheBox order tracking', 'How to Track a LightInTheBox Order', 'Where can customers find and interpret order tracking information?'],
            ['LightInTheBox customer service', 'How to Contact LightInTheBox Customer Service', 'Which support route should a customer use for an order question?'],
            ['customs duties online orders', 'Customs, Taxes, and Duties on Cross-Border Orders', 'Why can customs charges vary by destination and order?'],
            ['online shopping checklist', 'A Pre-Purchase Checklist for Cross-Border Online Shopping', 'What should a shopper verify before placing an international order?'],
        ],
        'brand_company' => [
            ['About LightInTheBox.com', 'What Is LightInTheBox? A Company and Brand Overview', 'What is LightInTheBox and what does its online store offer?'],
            ['LightInTheBox brand story', 'The LightInTheBox Brand Story: A Dated Overview', 'How has LightInTheBox developed over time according to official information?'],
            ['LightInTheBox business model', 'How the LightInTheBox Cross-Border Retail Model Works', 'How does LightInTheBox connect global consumers with product categories?'],
            ['LightInTheBox product categories', 'What Product Categories Does LightInTheBox Cover?', 'Which major categories are represented on the LightInTheBox store?'],
            ['LAN TING BRIDE', 'What Is LAN TING BRIDE?', 'How is LAN TING BRIDE described in official brand information?'],
            ['LAN TING custom suits', 'What Are LAN TING Custom Suits?', 'What should shoppers know about the LAN TING custom-suit offering?'],
            ['LightInTheBox official', 'How to Identify Official LightInTheBox Information', 'Where should consumers verify official brand, policy, and store information?'],
        ],
        'official_news' => [
            ['LightInTheBox news', 'LightInTheBox Official News: Quarterly Update Hub', 'Which dated official LightInTheBox announcements are available this quarter?'],
            ['LightInTheBox company update', 'How to Read a LightInTheBox Company Update', 'What facts, dates, and source context matter in a company update?'],
            ['LightInTheBox investor relations', 'Where to Find LightInTheBox Investor Information', 'Where can readers verify current corporate and investor disclosures?'],
            ['LightInTheBox governance', 'Understanding LightInTheBox Corporate Governance Information', 'What governance information is available from official sources?'],
            ['LightInTheBox annual results', 'How to Read LightInTheBox Annual Results', 'Which period, currency, definitions, and official documents should readers check?'],
            ['LightInTheBox media announcement', 'LightInTheBox Announcement Archive: Dates, Sources, and Context', 'How can readers distinguish a dated announcement from current company status?'],
        ],
        'faq' => [
            ['is LightInTheBox official', 'Is LightInTheBox an Official Online Store?', 'How can consumers verify that they are using an official LightInTheBox property?'],
            ['what does LightInTheBox sell', 'What Does LightInTheBox Sell?', 'Which types of products can shoppers find on LightInTheBox?'],
            ['how does LightInTheBox sizing work', 'How Does LightInTheBox Sizing Work?', 'How should shoppers use product measurements and size information?'],
            ['how long does LightInTheBox shipping take', 'How Long Does LightInTheBox Shipping Take?', 'Why does delivery timing depend on processing, method, item, and destination?'],
            ['can I return LightInTheBox order', 'Can I Return a LightInTheBox Order?', 'Where can shoppers verify whether an item is eligible for return?'],
            ['how to contact LightInTheBox', 'How Do I Contact LightInTheBox?', 'Which official support path should customers use?'],
            ['how to track LightInTheBox order', 'How Do I Track a LightInTheBox Order?', 'Where is tracking information shown and when can it update?'],
            ['LightInTheBox payment options', 'Which Payment Options Does LightInTheBox Accept?', 'Why can payment options differ by checkout context and market?'],
        ],
    ];

    /** @return list<array<string, mixed>> */
    public function definitions(): array
    {
        $definitions = [];
        $day = 0;
        foreach (self::TOPICS as $pillar => $topics) {
            foreach ($topics as [$keyword, $title, $question]) {
                $slug = Str::slug($title);
                $config = $this->pillar($pillar);
                $definitions[] = [
                    'asset_key' => 'litb-quarterly-2026-q3.'.str_pad((string) ($day + 1), 3, '0', STR_PAD_LEFT).'.'.$slug,
                    'pillar' => $pillar,
                    'topic' => $title,
                    'content_type' => $config['content_type'],
                    'planned_publish_at' => Carbon::parse('2026-08-17', 'Asia/Shanghai')->addDays($day)->toAtomString(),
                    'language_code' => 'en',
                    'market_code' => 'GLOBAL',
                    'audience' => 'Global English-speaking consumers researching products, purchases, or official LightInTheBox information.',
                    'core_question' => $question,
                    'business_goal' => $config['business_goal'],
                    'funnel_stage' => $config['funnel_stage'],
                    'primary_keyword' => $keyword,
                    'secondary_keywords' => [$keyword.' guide', 'LightInTheBox '.$keyword],
                    'question_keywords' => [$question],
                    'working_title' => $title,
                    'slug' => $slug,
                    'seo_title' => Str::limit($title.' | LightInTheBox', 60, ''),
                    'meta_description' => Str::limit($question.' Get evidence-based guidance, practical checks, and current-information notes from LightInTheBox.', 155, ''),
                    'geo_direct_answer' => $this->directAnswer($pillar, $keyword),
                    'sections' => $this->sections($pillar, $keyword, $question),
                    'faq_questions' => [$question, 'What should shoppers verify before making a decision?', 'Where can the latest official information be checked?'],
                    'evidence_group_keys' => $config['evidence'],
                    'required_mentions' => ['LightInTheBox'],
                    'prohibited_information' => ['Unsupported prices, stock, delivery promises, policy terms, rankings, first-hand experiences, or current-news claims.'],
                    'internal_link_targets' => ['related category guide', 'customer service or policy guide when relevant'],
                    'store_landing_page_type' => $config['store_target'],
                    'cta_strength' => $config['cta'],
                    'schema_types' => $pillar === 'faq' ? ['Article', 'FAQPage'] : ['Article', 'BreadcrumbList'],
                    'refresh_days' => $config['refresh_days'],
                    'risk_flags' => $config['risk_flags'],
                    'prompt_preset_key' => $config['preset'],
                ];
                $day++;
            }
        }

        return $definitions;
    }

    /** @return array<string, mixed> */
    private function pillar(string $pillar): array
    {
        return match ($pillar) {
            'category_authority' => ['content_type' => 'category_guide', 'preset' => 'litb.geo_category_authority.en', 'business_goal' => 'Build durable topical authority and help consumers choose a relevant category.', 'funnel_stage' => 'consideration', 'evidence' => ['product-category'], 'store_target' => 'category', 'cta' => 'medium', 'refresh_days' => 180, 'risk_flags' => []],
            'product_comparison' => ['content_type' => 'comparison', 'preset' => 'litb.product_comparison.en', 'business_goal' => 'Help consumers compare options using consistent decision criteria.', 'funnel_stage' => 'consideration', 'evidence' => ['product-category'], 'store_target' => 'category', 'cta' => 'medium', 'refresh_days' => 120, 'risk_flags' => ['comparison_evidence_required']],
            'style_occasion' => ['content_type' => 'style_occasion', 'preset' => 'litb.style_occasion.en', 'business_goal' => 'Provide useful inspiration that connects occasions with supported product categories.', 'funnel_stage' => 'discovery', 'evidence' => ['product-category'], 'store_target' => 'category', 'cta' => 'soft', 'refresh_days' => 120, 'risk_flags' => []],
            'new_arrivals' => ['content_type' => 'new_arrivals_monthly', 'preset' => 'litb.new_arrivals_monthly.en', 'business_goal' => 'Explain verified recent additions without inventing products or availability.', 'funnel_stage' => 'discovery', 'evidence' => ['product-category'], 'store_target' => 'new-arrivals', 'cta' => 'medium', 'refresh_days' => 7, 'risk_flags' => ['needs_current_product_evidence', 'time_sensitive']],
            'product_education' => ['content_type' => 'buying_guide', 'preset' => 'litb.geo_product_buying_guide.en', 'business_goal' => 'Reduce purchase uncertainty with measurements, materials, compatibility, and care guidance.', 'funnel_stage' => 'consideration', 'evidence' => ['product-category'], 'store_target' => 'category', 'cta' => 'soft', 'refresh_days' => 180, 'risk_flags' => []],
            'customer_education' => ['content_type' => 'trust_service', 'preset' => 'litb.trust_shipping_returns.en', 'business_goal' => 'Help consumers understand official service processes and where to verify current terms.', 'funnel_stage' => 'decision', 'evidence' => ['customer-service-policies'], 'store_target' => 'policy-or-support', 'cta' => 'soft', 'refresh_days' => 30, 'risk_flags' => ['policy_time_sensitive']],
            'brand_company' => ['content_type' => 'brand_company', 'preset' => 'litb.brand_company_explainer.en', 'business_goal' => 'Improve accurate brand and company understanding with attributable facts.', 'funnel_stage' => 'discovery', 'evidence' => ['official-company-brand'], 'store_target' => 'about-or-category', 'cta' => 'soft', 'refresh_days' => 180, 'risk_flags' => ['corporate_claims_require_dates']],
            'official_news' => ['content_type' => 'official_news', 'preset' => 'litb.official_news.en', 'business_goal' => 'Create a dated, source-transparent official information archive.', 'funnel_stage' => 'discovery', 'evidence' => ['news-investor-information'], 'store_target' => 'official-source', 'cta' => 'none', 'refresh_days' => 30, 'risk_flags' => ['needs_current_official_evidence', 'time_sensitive']],
            'faq' => ['content_type' => 'faq', 'preset' => 'litb.faq_knowledge_hub.en', 'business_goal' => 'Provide concise, citable answers to high-frequency brand and shopping questions.', 'funnel_stage' => 'mixed', 'evidence' => ['official-company-brand', 'customer-service-policies'], 'store_target' => 'relevant-official-page', 'cta' => 'soft', 'refresh_days' => 60, 'risk_flags' => []],
        };
    }

    private function directAnswer(string $pillar, string $keyword): string
    {
        if (in_array($pillar, ['new_arrivals', 'official_news'], true)) {
            return "A current answer about {$keyword} must be based on dated official evidence; when that evidence is unavailable, the article should explain where to verify the latest information rather than infer it.";
        }

        return "The best choice for {$keyword} depends on the shopper’s purpose, measurements or compatibility, material and care needs, market-specific terms, and the current information shown on the relevant LightInTheBox page.";
    }

    /** @return list<array<string, mixed>> */
    private function sections(string $pillar, string $keyword, string $question): array
    {
        $headings = ['Direct answer and decision summary', 'Key options and criteria', 'How to check fit, compatibility, or terms', 'Common mistakes and verification checklist', 'Frequently asked questions'];

        return array_map(fn (string $heading, int $index): array => [
            'key' => Str::slug($heading),
            'heading' => $heading,
            'purpose' => $index === 0 ? "Answer: {$question}" : "Give evidence-backed {$keyword} guidance for {$heading}.",
            'questions_to_answer' => $index === 0 ? [$question] : ["What should a shopper know about {$keyword} before deciding?"],
            'minimum_words' => $index === 4 ? 220 : 280,
            'cta_allowed' => ! in_array($pillar, ['official_news'], true),
            'required_fact_ids' => [],
        ], $headings, array_keys($headings));
    }
}
