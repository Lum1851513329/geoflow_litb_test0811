# LightInTheBox 官方博客前端设计

日期：2026-08-12
状态：设计已由用户确认
范围：LightInTheBox 官方博客站点前端（首页、分类、文章详情页），不含后台管理界面、不含支付/交易功能

## 1. 背景与目标

当前站点使用的 `toutiao-news-20260426` 主题是新闻聚合站风格（密集标题流、滚动式 feed），与 LightInTheBox 作为时尚/家居/婚纱生活方式电商品牌的定位不符。同时，已生成并发布的 69 篇文章全部归入单一分类，首页缺少品牌介绍、促销引导和分类导航。

本阶段目标：

1. 将站点前端迁移到适合生活方式/电商品牌内容的杂志式布局，替换新闻聚合视觉风格。
2. 建立清晰的文章分类体系，将已发布文章按内容支柱重新归类。
3. 设计首页模块堆叠：文章推荐、分类导航、促销/活动引导、公司介绍、FAQ 入口。
4. 全面执行严格的 SEO 结构语法与 GEO（生成式引擎优化）写作规范，不使用过时语法。

本站点不涉及支付和交易；促销/活动内容以编辑型 CTA 卡片形式外链到官方购物站，不在本站内嵌任何购买流程。

## 2. 现状盘点

- 主题系统：`resources/views/theme/` 下有 21 个 `geoflow-template-*` 主题，其中 20 个（`01` 至 `20`，逐一核实 `home.blade.php` 行数与结构一致）共用同一套“Hero + 精选网格 + 侧栏”杂志式 Blade 结构，仅 `assets/theme.css` 与 `tokens.json` 的配色/字体不同；`21-enterprise-signature`（当前配置的默认主题）结构更复杂，不属于该共享族；另有 `toutiao-news-20260426`、`netease-news-20260507`、`apple_support_clone`、`apihot-recommend-20260623` 等定制克隆主题结构不同。
- 首页已有主题无关的模块系统 `resources/views/site/partials/homepage-modules.blade.php`，支持 `hero`、`rich_text`、`image_band`、`cta_band`、`metric_band`、`chart_band`、`feature_grid`、`article_collection`、`lead_form`、`custom_html` 等模块类型，通过 `App\Support\Site\HomepageModuleBuilder` 与站点设置驱动，任何主题均可复用。
- 现有 Schema.org 标记（如 `geoflow-template-15-reading-medium/article.blade.php`）使用 `NewsArticle`，缺少 `image`、`author.url`、`publisher.logo`；无 `BreadcrumbList`、`FAQPage`、`speakable`、`WebSite` 站内搜索 Schema。
- 现有分类：仅 `LightInTheBox Guides`（本次批量发布时创建）与历史知识库分类 `litb_企业知识`。
- 已发布内容：69 篇文章，来自 `content_briefs.asset_provenance.pillar` 字段可追溯所属内容支柱（category_authority、product_comparison、style_occasion、new_arrivals、product_education、customer_education、brand_company、official_news、faq）。

## 3. 主题基础

不复用现有 18 个共享结构主题的任一套配色，而是基于该共享 Blade 结构新建一套自有配色与字重方案：

- 背景：暖白/象牙色（而非纯白），传达生活方式质感而非科技/资讯感。
- 正文文字：炭灰色而非纯黑。
- 强调色：低饱和玫瑰色或哑光金，呼应婚纱/时尚旗舰品类，同时不过于鲜艳影响长文阅读。
- 标题字体：衬线字体（大标题/H1/H2），正文使用无衬线字体，保证长文可读性。
- 布局密度：沿用共享结构的“Hero + 精选网格 + 侧栏”，但首页顶部由 `homepage-modules` 模块堆叠接管，主题自身的 Hero/网格区块用于分类页与首页“最新文章”兜底展示。

新主题以独立目录 `resources/views/theme/litb-lifestyle-editorial/` 建立，复用共享结构的 Blade 逻辑（`home.blade.php`、`article.blade.php`、`category.blade.php`、`layout.blade.php`、`partials/article-card.blade.php`、`partials/sidebar.blade.php`），仅替换 `assets/theme.css`、`tokens.json`、`manifest.json`、`mapping.json` 中的视觉变量。

## 4. 分类体系

新增以下分类（`categories` 表），并将 69 篇已发布文章按 `content_briefs.asset_provenance.pillar` 重新归类：

| 分类 | 对应内容支柱 | 面向读者的定位 |
|---|---|---|
| Style & Buying Guides | category_authority, product_education | 品类购买指南、材质/尺码/保养 |
| Comparisons | product_comparison | X vs Y 决策型内容 |
| Inspiration & Occasions | style_occasion | 场景穿搭与灵感 |
| New Arrivals | new_arrivals | 新品（当前因证据不足全部处于 needs_evidence，暂无已发布文章，分类先建立占位） |
| Shipping, Payment & Returns | customer_education | 物流、支付、退换 |
| About LightInTheBox | brand_company, official_news | 品牌故事、公司介绍、官方资讯 |
| FAQ & Help Center | faq | 高频问题 |

原有单一分类 `LightInTheBox Guides` 保留但不再作为默认归类目标，避免破坏历史 URL；`litb_企业知识`（知识库分类）不受影响。

## 5. 首页模块堆叠

自上而下，全部通过既有 `homepage-modules` 系统配置，不新增模块类型：

1. **Hero 轮播**（`hero`，轮播 2–3 项）：旗舰编辑内容（如婚纱指南、品牌故事）。
2. **本季推荐**（`cta_band` 或 `image_band`）：编辑型促销卡片，外链官方购物站当前活动页；不在本地拼接价格/库存文案，卡片文案人工按活动周期维护。
3. **分类货架**（`article_collection` × 6，每个分类一组）：每个分类展示最新 4–6 篇文章，标题使用分类的读者定位命名（如“选购指南”“场景灵感”）。
4. **热门指南**（`feature_grid`）：跨分类精选长青购买指南与对比文章。
5. **关于 LightInTheBox**（`rich_text` + `image_band`）：品牌故事摘要，链接到 About 分类全文，强化 E-E-A-T 信号。
6. **常见问题**（`feature_grid`）：链接到 FAQ 分类高频问题。
7. **订阅入口**（`lead_form`，可选）：因站点不含支付，邮件订阅是唯一的留资/复访手段。

## 6. SEO 结构与 GEO 写作规范（前端强制要求）

### 6.1 页面结构

- 每页恰好一个 `<h1>`；标题层级严格递进，不跳级（与内容生成质量门禁的标题层级检查口径一致）。
- 使用语义化 HTML5 地标：`header`、`nav`、`main`、`article`、`aside`、`footer`；禁止用 `div` 堆叠模拟这些角色。
- 每篇指南文章开头保留一段可直接引用的“直接答案”，并用可被 `speakable` 选择器命中的容器包裹。

### 6.2 结构化数据（JSON-LD）

- 指南/对比类文章使用 `BlogPosting`；仅 `official_news` 分类使用 `NewsArticle`；FAQ 分类文章额外附加 `FAQPage`（真实 Question/Answer 对，取自文章正文而非编造）。
- 修复现有 Article Schema：补齐 `image`、`author.url`、`publisher.logo`（Google 富媒体结果的硬性要求）。
- 全站页面附加 `BreadcrumbList`；分类页与首页列表区使用 `CollectionPage`/`ItemList`；首页附加 `WebSite` + `SearchAction`（站内搜索已存在）。
- 站点级 `Organization` Schema 附带 `sameAs` 指向 LightInTheBox 官方真实资料页（不编造社交账号链接）。

### 6.3 禁止的过时写法

- 不使用表格布局、`<font>`、`<center>` 等废弃标签。
- 不使用仅 JS 渲染、爬虫不可见的导航；所有分类/文章链接必须是真实可抓取的 `<a href>`。
- 外部链接加 `rel="noopener"`；首屏之外图片使用 `loading="lazy"`。
- 使用 Flexbox/Grid 响应式布局，不使用浮动布局；不引入 AMP。
- 保留 `canonical`，多语言页面预留 `hreflang`（当前仅英文，先占位不强制填充）。

## 7. 非目标

- 不实现任何支付、购物车或结账相关功能。
- 不在本阶段自动化“促销卡片”内容的生成或与商城系统的实时同步；卡片文案由人工维护。
- 不在本阶段处理多语言站点（`litb_zhishiku` 中的葡语等其他语言内容留待后续阶段）。
- 不替换或删除现有 21 个主题；新主题作为新增选项，通过 `active_theme` 设置切换。

## 8. 验收标准

1. `active_theme` 切换为新主题后，首页展示 Hero、促销、分类货架、热门指南、品牌介绍、FAQ、订阅模块，样式为暖色调编辑风格，而非新闻聚合风格。
2. 69 篇已发布文章按分类正确归类，历史 URL（slug）不变。
3. 每篇文章页、分类页、首页均通过结构化数据校验（`BlogPosting`/`NewsArticle`/`FAQPage`/`BreadcrumbList`/`CollectionPage`/`WebSite` 按页面类型正确出现，且字段无编造）。
4. 全站页面标题层级、语义地标、图片懒加载、外链 `rel` 属性均符合第 6 节要求。
5. 站点不含支付相关代码或链接指向站内结账流程。
