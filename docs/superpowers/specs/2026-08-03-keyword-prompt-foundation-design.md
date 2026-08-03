# GEOFlow 多级关键词与模块化 Prompt 基础设施设计

日期：2026-08-03  
状态：待用户书面审阅  
范围：第一阶段，不包含 LITB 内容业务模板和人工社区分发

## 1. 目标

在不破坏 GEOFlow 现有关键词库、标题库、Prompt、任务、Worker 和文章发布链路的前提下，建设以下基础能力：

1. 以业务品类树为主轴，以品牌、国家、语言、搜索意图和数据来源为维度的多级关键词体系。
2. 支持导入 LITB 品类词与品牌词 Excel，并保留批次、来源和历史指标。
3. 将关键词组织为可用于选题和内容生产的主题集群，并提供可解释的机会评分。
4. 将单体 Prompt 升级为可组合、可版本化、可验证、可追溯的 Prompt 配方。
5. 修正标题与关键词随机关联、自动关键词未生效、SEO 描述仅截断正文等问题。
6. 为后续 LITB 知识库、多语言内容、周/月上新以及人工社区分发提供稳定接口。

## 2. 非目标

第一阶段不实现：

- Reddit、Quora、Medium 的帖子发现、评论生成、账号人设和人工发布任务。
- LightInTheBox 具体文章模板、商品同步、订单或评价证据接入。
- 官网主题、导航、公开站点页面和 WordPress 发布改造。
- 自动修改或正式导入当前运行数据库。
- 用向量或模型黑盒分数完全替代可解释的关键词规则。

## 3. 现状与问题

当前 GEOFlow 的 `keyword_libraries` 与 `keywords` 只能表达“关键词属于一个库”。关键词缺少层级、市场、语言、意图、品牌关系、时间序列指标和内容覆盖状态。

标题生成从关键词库随机抽样，生成后又随机选择一个关键词保存到标题，无法保证标题与关键词的真实关联。Worker 只读取标题上的单个关键词；`auto_keywords` 没有生成关键词集合，`auto_description` 没有独立生成 SEO 描述。

任务只能选择一个 `prompt_id`。`Prompt.variables` 未形成变量契约，Worker 只向 Prompt 注入 `title`、`keyword` 和 `knowledge`，也没有保存模块版本、完整配方或生成清单。

## 4. 总体架构

数据流如下：

```text
Excel / 手工输入 / 后续数据源
  → 导入批次与原始行
  → 关键词标准化与去重
  → 品类树和多维标签
  → 历史搜索指标
  → 主题集群与机会评分
  → 标题规划
  → Prompt 配方解析和渲染
  → 正文与 SEO 元数据生成
  → 生成清单、验证结果和文章草稿
```

采用渐进式升级：现有表和字段继续可用，新能力由新增表和可空外键承载。旧任务仍走旧 `prompt_id` 路径，新任务可以选择关键词集群和 Prompt 配方。

## 5. 关键词领域模型

### 5.1 分类体系

`keyword_taxonomies` 表示一套分类体系，例如 `LITB Product Taxonomy`。`keyword_nodes` 使用 `parent_id` 形成邻接表树，并保存稳定路径和层级深度。

示例：

```text
Fashion
└── Women
    └── Dresses
        └── Occasion Dresses
            ├── Mother of the Bride Dresses
            └── Wedding Guest Dresses
```

关键词与节点是多对多关系，由 `keyword_node_keyword` 连接。同一个词可以属于多个业务场景，但必须标记一个主要节点，供导航、选题和默认聚合使用。

### 5.2 关键词扩展字段

保留 `keywords.library_id` 兼容原关键词库，并新增或通过关联表表达：

- `normalized_keyword`：用于去重的标准形式。
- `language_code`：BCP 47 或可映射的 ISO 语言代码。
- `market_code`：ISO 3166-1 alpha-2 国家/市场代码，可为空表示全球。
- `keyword_type`：brand、category、product、question、comparison、trust、navigation 等。
- `search_intent`：informational、commercial、transactional、navigational、support。
- `brand_relation`：branded、brand_category、non_brand、competitor_comparison。
- `status`：active、ignored、review_required、archived。
- `canonical_keyword_id`：拼写变体或同义词指向的标准词。
- `latest_opportunity_score`：用于列表排序的缓存值，权威明细仍在评分记录中。

唯一性以标准词、语言和市场为核心，不把大小写或多余空白视为不同关键词。是否合并单复数和近义词由显式关系决定，不在导入阶段激进合并。

### 5.3 历史指标

`keyword_metrics` 以关键词、导入批次、统计周期、市场、语言、设备和数据源为维度，保存来源能够提供的指标，例如：

- clicks
- impressions
- ctr
- average_position
- cost
- conversions
- source_payload

指标采用追加模式，不覆盖历史记录。字段缺失时保存为空，不用零伪装未知值。

### 5.4 关键词关系

`keyword_relations` 保存有方向的关系：

- synonym
- spelling_variant
- broader
- narrower
- related
- question_for
- brand_modifier_of
- market_variant_of

关系包含来源、置信度和人工确认状态。首期规则产生的关系必须可由管理员修改。

## 6. 导入模型与两份 LITB 数据

### 6.1 导入批次

`keyword_import_batches` 保存文件名、文件哈希、数据源、指标周期、映射配置、状态、统计结果、操作者和时间。相同文件哈希和相同导入配置默认视为重复导入，除非用户明确创建新批次。

`keyword_import_rows` 保存经过敏感字段过滤后的原始行、行号、标准化结果、处理状态和错误信息，用于审计和重跑。

批次状态：

```text
uploaded → mapped → validating → importing → completed
                                  ↘ failed
completed → reverting → reverted
```

撤销仅删除该批次创建的指标、关系和未被其他批次或业务对象引用的关键词；共享关键词本身不得误删。

### 6.2 字段映射

导入界面先解析工作表和表头，再让用户确认字段映射。系统为已知报表提供预设，但不依赖固定列序。

两份初始数据的用途：

- 品类词表用于建立非品牌需求、语言、市场、品类节点和自然搜索表现。
- 品牌词表用于建立品牌导航、品牌+品类、优惠、信任评价、账号支持和国家修饰等意图簇。

导入分为预览与确认两个操作。预览不写业务表；确认导入在后台任务中分块执行，并保证幂等。

## 7. 主题集群

新增 `keyword_clusters`、`keyword_cluster_keywords`。每个集群包含：

- 一个主关键词。
- 多个次级关键词、问题词、品牌组合词和市场变体。
- 主要品类节点。
- 目标语言和市场。
- 搜索意图。
- 推荐内容类型。
- 可选的知识库、分类页和商城落地页引用。
- 内容覆盖状态。
- 机会评分及解释。

集群成员角色包括 `primary`、`secondary`、`question`、`variant`、`brand_supporting`。同一个关键词可进入多个集群，但同一市场和语言下只能在一个活跃集群中担任主关键词。

### 7.1 首期聚类方式

首期采用规则优先的半自动聚类：标准化文本、品类节点、品牌关系、意图、语言和市场生成候选集群，管理员确认后生效。模型可给出建议，但不得直接覆盖人工确认结果。

### 7.2 机会评分

评分由独立 `KeywordOpportunityScoringService` 计算并保存分项解释：

```text
需求分 + 点击表现分 + 排名机会分 + 商业相关性分
+ 内容缺口分 + 新鲜度分 - 数据不足惩罚
```

各项归一化到稳定范围，权重由配置管理。品牌词和非品牌词使用不同权重模板，避免品牌导航词凭点击量垄断选题。每次重算保存评分版本和输入时间范围。

## 8. Prompt 模块与配方

### 8.1 数据模型

- `prompt_modules`：模块身份、分组、适用范围和当前版本。
- `prompt_module_versions`：不可变版本内容、变量契约、输出契约和状态。
- `prompt_recipes`：配方名称、用途、语言、内容类型和状态。
- `prompt_recipe_modules`：模块版本、排序、是否必选、条件和参数覆盖。
- `generation_manifests`：一次生成实际使用的配方、模块版本、上下文、模型、哈希、验证结果和目标内容。

Prompt 模块分组：

- mandatory：事实边界、GEO 可引用性、品牌规范。
- content_type：新闻、购买指南、榜单、产品说明等。
- category：服装、婚礼服饰、泳装等专业规则。
- locale：语言、国家、单位、季节和文化表达。
- seo：标题、关键词、description、内链和结构化输出。
- structure：段落结构、FAQ、表格、摘要等。
- output：Markdown 或结构化 JSON 契约。
- future_channel、future_persona：为第三阶段预留，不在首期实现业务界面。

### 8.2 变量契约

每个模块声明所需和可选变量、类型及默认值。首期标准变量包括：

```text
title
primary_keyword
secondary_keywords
questions
search_intent
content_type
language
market
category_path
knowledge
source_evidence
brand_context
target_url
```

保存配方前执行变量冲突、缺失、未知变量和输出契约校验。运行时缺少必选变量则生成失败并写入 TaskRun，不静默拼接空值。

### 8.3 编排顺序

`PromptRecipeCompiler` 负责读取配方、解析条件、合并参数并输出稳定顺序的渲染计划；`PromptRecipeRenderer` 只负责安全渲染；`PromptRecipeValidator` 负责静态和运行时校验。三者不承担模型调用。

配方中 mandatory 模块不可被普通任务关闭。模块版本发布后不可原地修改；编辑产生新版本，确保历史文章可复现。

### 8.4 兼容策略

任务新增可空 `prompt_recipe_id`。执行优先级：

1. 有 `prompt_recipe_id` 时走新编排器。
2. 否则有 `prompt_id` 时走现有 `ArticleContentPromptRenderer`。
3. 两者都没有时保持当前兜底行为，但记录兼容路径警告。

现有 Prompt 管理页和旧任务不删除、不自动转换。

## 9. 标题与内容生成

### 9.1 标题规划

标题库新增可空 `keyword_cluster_id`、`primary_keyword_id`、`language_code`、`market_code`、`search_intent` 和 `generation_manifest_id`。

标题生成必须以一个明确集群为单位。保存标题时使用生成请求中的主关键词，不再从样本关键词中随机选择。模型失败时保存失败状态和原因，不生成点击诱导式模板标题。

### 9.2 Worker 上下文

任务新增可空的关键词集群、语言和市场字段。Worker 构造 `ContentGenerationContext`，统一提供标题、主次关键词、问题、意图、品类路径、知识证据、语言、市场和目标链接。

WorkerExecutionService 继续负责任务生命周期和事务边界，但 Prompt 编译、关键词选择、SEO 元数据生成和验证由独立服务承担，避免继续膨胀。

### 9.3 SEO 元数据

当 `auto_keywords=1` 时，从集群和生成结果中构建去重的关键词集合，并保留主次关系；不再把单个原始关键词直接复制为全部关键词。

当 `auto_description=1` 时，通过结构化输出或独立 SEO 元数据调用生成 description，并验证长度、语言、主旨和重复度。失败时可回退为经过句子边界处理的摘要，并在生成清单中标记 fallback。

## 10. 管理后台

第一阶段提供：

1. 分类体系和品类树管理。
2. 关键词列表、多维筛选、批量归类和状态管理。
3. Excel 上传、映射预览、导入进度、错误下载与安全撤销。
4. 主题集群列表、成员编辑和机会评分解释。
5. Prompt 模块、不可变版本和发布状态管理。
6. Prompt 配方的模块排序、条件、参数覆盖、预览和校验。
7. 标题生成页面选择集群、语言、市场和意图。
8. 任务表单选择关键词集群和 Prompt 配方。

所有写操作沿用 GEOFlow 后台认证、CSRF、权限和活动日志约定。大文件导入和评分重算进入队列，页面展示进度和可恢复错误。

## 11. 错误处理与安全边界

- 文件类型、大小、工作表、公式和字段类型在导入前验证。
- 原始文件保存在受控路径，不允许以原始文件名决定存储路径。
- 导入、聚类、评分和配方发布均使用幂等键或数据库唯一约束。
- 队列失败记录可读错误，不在日志中输出密钥或不必要的原始数据。
- Prompt 渲染不执行代码；变量仅通过白名单解析。
- 配方发布、导入确认和批次撤销是独立动作，不因预览自动发生。
- 新表和新字段使用可回滚迁移，不修改生产数据作为迁移前提。

## 12. 测试与验收

### 12.1 数据层

- 分类树关系、循环阻止、删除保护。
- 关键词标准化、语言/市场唯一性和显式变体关系。
- 指标历史追加、不覆盖旧批次。
- 导入重复文件、重复行、部分失败、重试和撤销幂等性。

### 12.2 领域层

- 品牌词与非品牌词采用不同评分模板。
- 评分结果包含完整分项解释和版本。
- 聚类建议不会覆盖人工确认结果。
- Prompt 模块顺序、条件、参数覆盖和必选模块约束。
- 配方版本不可变，旧生成清单可复现。

### 12.3 Worker 与生成

- 新旧 Prompt 双轨执行。
- 标题与主关键词保持真实关联。
- `auto_keywords` 和 `auto_description` 分别生效。
- 缺失必选变量时任务明确失败。
- 生成清单记录配方、模块、关键词集群、模型和验证结果。
- 事务失败不会产生半成品关联。

### 12.4 管理后台

- 路由、权限、CSRF、验证、分页、筛选和国际化。
- 导入预览不写库，确认后才创建队列任务。
- 配方预览与实际 Worker 使用相同编译器。
- 错误和空状态可读。

### 12.5 验收标准

第一阶段完成时应满足：

1. 两份 LITB Excel 能通过同一导入框架完成预览、确认、去重、历史指标入库和批次追踪。
2. 管理员能建立 `Fashion → Women → Dresses` 等品类树并将关键词批量归类。
3. 系统能生成包含主次词、问题词、市场、语言和意图的主题集群及可解释评分。
4. 管理员能用多个版本化模块创建配方并预览最终 Prompt。
5. 新任务可使用集群和配方生成文章，旧任务继续正常运行。
6. 标题不再随机绑定关键词，SEO keywords 和 description 按开关真实生成。
7. 自动化测试覆盖兼容路径、导入幂等性、版本追溯和 Worker 主流程。

## 13. 实施分解建议

正式实施计划应按以下依赖顺序拆分：

1. 领域表、模型、索引和兼容字段。
2. Excel 导入批次、预览、映射和幂等服务。
3. 分类树、关键词关系、集群与评分服务。
4. Prompt 模块、版本、配方、编译器和渲染器。
5. 标题生成与 Worker 上下文改造。
6. SEO 元数据生成和生成清单。
7. 管理后台、队列状态和活动日志。
8. 两份 LITB 文件的只读预览验证；正式导入需单独确认。

每一步先运行聚焦测试，再运行 GEOFlow 全量测试。数据库正式导入、任务启动和内容发布均不属于代码实施的默认授权范围。
