# LITB 90 天 GEO/SEO 内容资产与初始化设计

日期：2026-08-11  
状态：设计已由用户确认（方案 B：资产治理后生产）  
依赖：`2026-08-10-content-strategy-prompt-composition-design.md`  
范围：后台导航、知识资产治理、多级关键词导入、模块化 Prompt 扩充、90 天英文内容计划、文章生成与本地归档

## 1. 目标

将用户提供的 LightInTheBox 知识、关键词和搜索表现数据整理为可追踪、可重复导入的内容资产，并在 GEOFlow 内建立一套可直接运营的 90 天英文主站内容体系。系统应在首次初始化后具备：

1. 清晰可达的 Prompt、内容策略、关键词和知识库入口；
2. 分来源、语言、主题和可信等级管理的知识库；
3. 品牌词、品类词、问题词和搜索表现数据组成的多级关键词体系；
4. 强制通用 GEO/SEO 模块与按场景装配的写作模块；
5. 约 90 条已填充内容策略信息的季度简报；
6. 可生成、质检、采用并归档的正式英文文章；
7. 可重复执行、不会覆盖运营修改、保留审计记录的初始化机制；
8. 可迁移到 WordPress 的 Markdown 和结构化元数据。

## 2. 输入资产与边界

主要输入目录为：

- `/Users/litb/Documents/litb_geo/litb_zhishiku`；
- `/Users/litb/Documents/litb_geo/litb关键词`；
- 两份 Google Web Search Excel 品类词和品牌词数据；
- GEOFlow 当前数据库中的 Keyword、KeywordCluster、KnowledgeBase、Prompt 和内容策略记录。

输入资料包含官方知识、公司研究报告、客户服务与政策资料、英语/葡语/中文关键词、产品品类词及搜索表现数据。不同来源不得被视为相同权威等级。库存、价格、物流时效、退换政策、财务和新闻属于时间敏感信息，生成时必须携带资料日期并经过适用性检查。

本阶段以英文全球消费者为主要受众，保留葡语、西语、法语、德语、意大利语及中文的本地化关联，但不把英文文章逐句机器翻译后直接发布。

## 3. 后台信息架构

顶部主导航保留有限入口，新增或调整为：

- 内容中心；
- 关键词智能；
- 素材与知识；
- AI 配置；
- 站点设置。

内容中心内部提供二级入口：

- 内容简报；
- 90 天内容日历；
- 文章生成记录；
- Prompt 模块；
- Prompt 预设。

Prompt 模块和预设路由应出现在活动导航映射中。功能开关关闭时入口隐藏；功能开启且管理员已登录时，用户不需要手工输入 URL。

## 4. 知识资产治理

### 4.1 逻辑知识库

首批知识按用途拆分为：

1. `LITB Official Company & Brand`；
2. `LITB Product & Category`；
3. `LITB Customer Service & Policies`；
4. `LITB Shipping, Payment & Returns`；
5. `LITB News & Investor Information`；
6. `LITB Consumer Insights`；
7. `LITB Marketing Research — Reference Only`。

研究材料不得自动升级为官方陈述。消费者评价类材料仅作为主题发现和问题洞察；若缺少可核验的授权记录或具体后台事实，不生成第一人称购买经历。

### 4.2 标准化清单

每个源文件生成清单记录：

- 稳定 source key；
- 原始路径和 SHA-256；
- 标题、语言、市场和主题；
- 来源类型与可信等级；
- 官方、研究、消费者或内部数据标记；
- 发布、生效、抓取或整理日期；
- 时间敏感级别和建议复核日期；
- 允许使用的内容类型；
- 风险和审核状态；
- 对应 GEOFlow KnowledgeBase ID；
- 导入、跳过、更新或冲突结果。

标准化版本存放在项目内容资产目录，但原文件只读保留，不就地改写。

### 4.3 导入策略

导入服务按 source key 与内容哈希幂等运行。相同哈希跳过；新哈希创建修订或新版本；已由管理员修改的数据库记录不被初始化器静默覆盖。切片失败时保留知识库行和失败审计，可安全重试。

## 5. 多级关键词体系

### 5.1 层级

关键词按以下维度组合：

```text
品牌关系
└── 语言与市场
    └── 一级品类
        └── 二级品类
            └── 产品、属性或使用场景
                └── 搜索意图
                    └── primary / secondary / question
```

首批逻辑库：

- `LITB Brand Keywords — EN`；
- `LITB Product & Category Keywords — EN`；
- `LITB Brand & Product Keywords — PT`；
- `LITB Brand Keywords — ZH`；
- `Google Search Performance — Category`；
- `Google Search Performance — Brand`。

### 5.2 去重和保真

去重键不能只依赖原始 keyword 字符串。系统同时考虑标准化词、语言、市场、品牌关系和来源。原始词、来源文件、Excel 行号和指标快照必须保留。相同库内的确切重复不得再次插入，跨库共享词允许存在并保留各自用途。

### 5.3 评分和聚类

候选主题综合：

- 过去 30 天点击、曝光、CTR 和平均排名；
- 品牌及商品相关度；
- 搜索意图和漏斗阶段；
- 商业价值；
- GEO 问答与引用潜力；
- 知识证据覆盖；
- 季节性和时效；
- 内容缺口；
- 关键词蚕食风险。

每个正式简报应尽量绑定一个已确认集群、明确 primary keyword、按顺序保存 secondary 和 question keywords。

## 6. Prompt 模块和预设

### 6.1 强制通用模块

所有正式官网文章必须装配：

- 真实性与证据约束；
- GEO 自包含可引用答案；
- 搜索意图满足；
- SEO 标题层级和主题覆盖；
- 品牌与实体一致性；
- E-E-A-T 和来源透明度；
- 关键词自然覆盖及防堆砌；
- 内链与商城链接规则；
- 语言和市场本地化；
- 时间敏感信息检查；
- 输出契约；
- 发布前质量检查。

强制模块不能被普通内容任务关闭，也不能通过后置模块改写其保护参数。

### 6.2 场景模块

场景模块覆盖：

- 每周或每月上新；
- 品类权威指南；
- 产品选择与购买指南；
- 尺码、材质、使用和维护；
- 穿搭、场景和灵感；
- 趋势分析；
- 比较、榜单和替代选择；
- 品牌故事和公司解释；
- 官方新闻；
- 配送、支付、退换与客服；
- FAQ 和知识中心；
- 季节及节日专题。

### 6.3 首批生产预设

建立至少以下预设：

1. `GEO Product Buying Guide — EN`；
2. `GEO Category Authority Guide — EN`；
3. `New Arrivals Weekly — EN`；
4. `New Arrivals Monthly — EN`；
5. `Product Comparison — EN`；
6. `Style & Occasion Inspiration — EN`；
7. `Trust, Shipping & Returns — EN`；
8. `Brand and Company Explainer — EN`；
9. `Official News Article — EN`；
10. `FAQ Knowledge Hub — EN`；
11. `Seasonal Commercial Guide — EN`。

种子只创建受保留 key 管理的系统模块和版本；重复执行不覆盖管理员创建的新版或禁用选择。

## 7. 90 天季度内容包

默认创建 90 条英文简报：

| 内容支柱 | 数量 |
|---|---:|
| 品类权威与购买指南 | 18 |
| 产品选择和比较 | 12 |
| 场景、穿搭和灵感 | 12 |
| 每周及月度上新 | 9 |
| 尺码、材质、使用和维护 | 9 |
| 配送、支付、退换与消费者教育 | 9 |
| 品牌、公司和业务解释 | 7 |
| 官方资讯与新闻背景 | 6 |
| FAQ 与高引用问题 | 8 |
| 合计 | 90 |

每条简报必须预填：

- 稳定 brief key；
- 主题、内容支柱和内容类型；
- 英文语言与目标市场；
- 受众、核心问题、业务目标和漏斗阶段；
- primary、secondary 和 question keywords；
- 关键词集群；
- 建议标题、slug、SEO title 和 meta description；
- 开头应直接回答的 GEO 核心答案；
- 结构化章节和 FAQ；
- 必须使用的证据类别；
- 必须提及和禁止表述；
- 内链、商城 CTA 和落地页类型；
- Schema 建议；
- 建议发布日期、季节标签和更新周期；
- Prompt 预设；
- 质量目标和风险提示。

实际选题由关键词、知识覆盖和搜索数据决定，不机械平均分配到无证据或已过时的品类。上新类内容在缺少实时商品数据时只建立 `needs_evidence` 简报，不虚构商品、价格或库存。

## 8. 写作、质检和采用

标准链路：

```text
简报 → 策略 → 蓝图 → 证据绑定 → Prompt 编译 → 初稿
     → 确定性 GEO/SEO 检查 → 语义检查 → 最多一次定向修订
     → 本地归档 → GEOFlow 生成运行 → Article 草稿 → 人工审核
```

质量门禁至少检查：

- 是否直接回答主要问题；
- 事实是否可回溯到证据；
- 时效限定是否完整；
- 标题层级、章节和 FAQ 是否完整；
- 关键词覆盖和堆砌；
- 实体、品牌名、语言和市场一致性；
- 重复、空泛、过短或相互矛盾段落；
- SEO title、description、slug、链接和 CTA；
- 建议 Schema 与正文是否一致；
- 禁止信息和风险表述。

未通过文章保持草稿或 `manual_review_required`，不得被初始化过程自动视为已批准发布。

## 9. 本地归档

归档根目录：

```text
content/litb-quarterly-2026-q3/
├── calendar/
├── briefs/
├── articles/
│   ├── en/
│   ├── pt/
│   └── localized/
├── evidence-manifests/
├── quality-reports/
└── import-manifests/
```

文章使用 Markdown 正文和 YAML front matter，字段覆盖 WordPress 迁移所需的 title、slug、excerpt、SEO metadata、canonical、language、market、category、tags、keywords、evidence、schema、store links、published/updated dates 和 GEOFlow IDs。

简报、文章和数据库通过稳定 key 关联。归档文件名不依赖会变化的数据库自增 ID。

## 10. 幂等初始化和审计

初始化器分为可独立执行的阶段：

1. 知识源扫描和标准化；
2. 知识库导入与切片；
3. 关键词清洗、映射和导入；
4. 分类树和关键词集群构建；
5. Prompt 模块和预设初始化；
6. 90 天日历与简报初始化；
7. 文章生成、质检和归档；
8. GEOFlow 草稿采用。

每阶段生成 manifest，包含运行 ID、输入哈希、代码/模式版本、创建、更新、跳过、冲突、失败统计和错误明细。初始化器不得覆盖没有 seed provenance 的记录。撤销只撤销本次独占创建的数据；共享数据保留并标记 `retained_shared`，审计行永久保留。

## 11. 安全与运行约束

- 不修改用户提供的原始知识库和关键词文件；
- 不记录 API 密钥或完整敏感上下文；
- 不使用研究材料伪装成官方来源；
- 不生成没有实时依据的价格、库存、配送承诺或新闻；
- 不自动发布到正式站点；
- 数据库批量写入采用事务、唯一键和幂等键；
- 长耗时生成使用队列，可断点续跑；
- 现有 `docker-compose.yml` 用户改动不纳入本任务提交。

## 12. 测试与验收

### 12.1 测试

- 导航入口和功能开关测试；
- 系统模块、版本和预设幂等测试；
- 知识扫描、哈希、分类、导入和重试测试；
- 多语言关键词解析、标准化、去重和 Excel 导入测试；
- 90 条简报数量、支柱配额、稳定 key 和必填信息测试；
- 关键词集群、证据和预设关联测试；
- Markdown/YAML 归档和 WordPress 字段测试；
- 生成质量门禁和失败状态测试；
- 重跑不覆盖运营修改测试；
- PostgreSQL、队列和现有内容生成回归测试。

### 12.2 验收标准

1. 管理员可从界面进入 Prompt 模块和预设；
2. 知识资料已按设计分类并可在 GEOFlow 知识库查询；
3. 英文、葡语、中文及 Search Performance 关键词形成独立可审计库；
4. 关键词不存在同库重复插入错误；
5. 通用模块强制参与，场景预设可预览其准确版本；
6. 系统存在 90 条预填完整的英文季度简报；
7. 可从简报生成并质检文章，失败不会伪装成功；
8. 每篇已生成文章具有本地 Markdown、证据清单和质量报告；
9. 数据库和本地资产通过稳定 key 对应；
10. 重复执行初始化不重复创建、不覆盖人工修改且保留审计；
11. 现有旧 Prompt、Task、Worker、Article 和发布流程测试通过；
12. 生成内容可以通过结构化字段迁移到 WordPress。

## 13. 实施顺序

实施按依赖顺序推进：导航与初始化框架 → 知识治理 → 关键词适配 → Prompt 扩充 → 季度日历与简报 → 写作与质量门禁 → 本地归档及数据库同步 → 全量验证。文章生成可在知识、关键词、Prompt 和简报数据通过验证后按批次并行运行，但不得绕过证据和质量门禁。
