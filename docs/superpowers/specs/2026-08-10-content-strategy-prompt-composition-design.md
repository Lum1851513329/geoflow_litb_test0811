# GEOFlow 内容策略与 Prompt 编排第一阶段设计

日期：2026-08-10  
状态：待用户书面审核  
范围：内容简报、内容策略、内容蓝图、Prompt 模块版本、可追踪生成运行，以及与现有文章生产链路的兼容接入

## 1. 背景与目标

GEOFlow 已具备关键词库、关键词聚类、知识库切片与检索、AI 模型调用、文章生成、审核、风险扫描、发布分发、人工发布任务和 AI 可见性分析等能力。现有正文生成主要围绕单个 Prompt、标题、关键词和知识库上下文展开，尚不能稳定表达搜索意图、目标读者、内容深度、模块版本、生成血缘和后续效果回测所需的信息。

第一阶段不重做现有生成和发布系统，而是在其上游增加可解耦的内容策略与 Prompt 编排层，并在生成时保存不可变运行快照。

本阶段目标：

1. 运营人员通过简明表单描述内容需求，而不是直接编写完整 Prompt。
2. 系统将简报转换为可审核的内容策略卡和内容蓝图。
3. Prompt 由多个有版本的模块和动态上下文组装，且能够检测变量缺失和规则冲突。
4. 核心长文使用“蓝图—全文生成—质量检查—定向修订”流程，减少内容简短、空泛和上下文不连贯。
5. 每次生成都能追溯内容策略、模块版本、知识证据、模型、最终 Prompt、原始输出、修订过程和最终采用结果。
6. 旧任务与旧 Prompt 继续运行，新功能通过并行链路和功能开关逐步启用。

## 2. 非目标

第一阶段明确不实现：

- 完整的 GSC 同步和 SEO 效果回测；
- 多 AI 平台定时提问和品牌引用监测；
- 完整搜索意图机会发现与自动选题；
- Reddit、Quora、Medium 账号成熟度评分；
- 每周账号养成计划；
- Playwright 自动发布；
- Prompt 版本的统计归因和自动优化；
- 复杂多变量 A/B 测试平台。

本阶段仅为上述能力预留稳定关联接口，不提前实现。

## 3. 设计原则

### 3.1 模块是指令，不是正文片段

Prompt 模块共同编译为一份完整指令，模型默认一次生成完整正文。系统不让多个模块分别生成章节后直接拼接，从源头减少语气、事实和逻辑不一致。

### 3.2 规则决定边界，模型负责表达

变量校验、冲突优先级、知识有效性、最低结构、字数、必答问题和状态迁移由确定性规则完成。模型可以生成策略建议、蓝图、正文和修订内容，但不能绕过硬性约束。

### 3.3 现有能力优先复用

复用现有：

- Keyword、KeywordCluster 与关键词指标；
- KnowledgeBase、KnowledgeChunkSyncService 与 KnowledgeRetrievalService；
- AiModel 与 ArticleContentGenerationService；
- Article、ArticleReview、ArticleRiskScanner 与审核状态机；
- Distribution 与 ManualPublication；
- 管理员、权限、活动日志与队列。

### 3.4 历史可复现

已使用的模块版本、预设版本和生成运行不可物理删除或覆盖。修改模块必须产生新版本。生成运行保存必要快照，避免知识、人设或模块更新后历史不可解释。

### 3.5 模块化单体

第一阶段继续使用 Laravel 单体、数据库和队列，不引入微服务或消息中间件。新增领域通过接口和内部事件连接现有服务，避免控制器或单个服务持续膨胀。

## 4. 业务流程

### 4.1 标准流程

```text
创建内容简报
  → 系统建议内容策略
  → 运营确认/修改策略
  → 系统生成内容蓝图
  → 运营确认/修改蓝图
  → 系统解析场景预设和模块版本
  → 校验变量、知识证据和模块冲突
  → 编译最终写作契约和 Prompt
  → 调用现有模型生成完整正文
  → 确定性质量门禁
  → AI 语义质量检查
  → 必要时最多一轮定向修订
  → 创建或更新现有 Article 草稿
  → 进入现有审核和发布流程
```

### 4.2 快速流程

低风险短内容允许跳过人工蓝图确认，但仍生成并保存策略、蓝图和运行快照。核心官网文章默认要求策略和蓝图确认后生成。

### 4.3 旧链路

```text
原 Task
  → 原 Prompt
  → ArticleContentPromptRenderer
  → ArticleContentGenerationService
  → Article
```

旧链路保持可用，不强制迁移历史任务。

## 5. 领域边界

建议新增以下目录或同等职责边界：

```text
app/Domain/ContentStrategy
app/Domain/PromptComposition
app/Domain/ContentGeneration
```

### 5.1 ContentStrategy

负责内容简报、策略卡、蓝图及其状态，不负责模型提供商或文章发布。

### 5.2 PromptComposition

负责模块、版本、预设、变量契约、模块解析、冲突处理和最终 Prompt 编译，不直接写入文章。

### 5.3 ContentGeneration

负责生成运行、模型调用协调、质量报告、定向修订和最终结果采用。实际模型调用复用 `ArticleContentGenerationService`。

## 6. 内容简报

### 6.1 必填字段

- 主题；
- 目标市场；
- 目标语言；
- 目标读者；
- 用户需要解决的核心问题；
- 业务目标；
- 内容类型；
- 计划发布日期。

### 6.2 推荐字段

- KeywordCluster 或核心关键词；
- 重点品类、商品或商城落地页；
- 必须提及的信息；
- 禁止出现的信息；
- 活动或时效范围；
- 参考 URL；
- 期望 CTA 强度。

### 6.3 状态

```text
draft → ready → strategy_ready → archived
```

已产生正式策略的简报仍可修改，但修改后必须创建新的策略版本，不能静默改变已执行生成的上下文。

## 7. 内容策略卡

内容策略卡至少包含：

- 目标读者和用户问题；
- 主搜索意图；
- 次搜索意图；
- 用户决策阶段；
- 业务主题和品类；
- 目标关键词簇；
- 推荐内容类型；
- 内容目标和成功标准；
- CTA 强度；
- 建议商城落地页；
- 必答问题；
- 内容风险和证据缺口；
- 未来监测问题的初始集合。

第一阶段允许系统根据规则和模型建议搜索意图，但不建设完整意图机会发现系统。运营必须可以修改建议结果，并记录原建议、最终值、修改人和理由。

## 8. 内容蓝图

蓝图采用结构化 JSON，并提供可视化编辑表单。至少包含：

```json
{
  "working_title": "string",
  "thesis": "string",
  "audience": "string",
  "primary_question": "string",
  "target_length": 1800,
  "sections": [
    {
      "key": "stable-section-key",
      "heading": "string",
      "purpose": "string",
      "questions_to_answer": ["string"],
      "required_fact_ids": ["knowledge-chunk-reference"],
      "minimum_words": 180,
      "cta_allowed": false
    }
  ],
  "required_entities": ["LightInTheBox"],
  "faq_questions": ["string"]
}
```

蓝图版本不可覆盖。生成运行引用明确蓝图版本。

## 9. Prompt 模块与版本

### 9.1 模块身份

每个模块具有：

- 不可随意修改的 `module_key`；
- 用户可修改的 `display_name`；
- 模块类型；
- 系统模块或用户模块；
- 适用内容类型、平台、语言和市场；
- 是否强制；
- 默认优先级；
- 所有者及启用状态。

### 9.2 版本生命周期

```text
draft → test → canary → production → deprecated → archived
```

第一阶段实现 draft、production、deprecated、archived 的完整行为；test 和 canary 保留状态及手工选择能力，不实现自动流量分配。

已被生成运行引用的版本不能删除。所谓回滚是重新将历史版本设为生产选择，不修改已有运行记录。

### 9.3 模块分类

- foundation；
- geo；
- seo；
- content_type；
- platform；
- persona；
- maturity_stage；
- language_market；
- knowledge_policy；
- output_format；
- quality_check。

第一阶段优先实现官网文章需要的分类；社区相关分类可保存但不提供完整成熟度联动。

### 9.4 变量契约

模块版本声明：

- 必填变量；
- 可选变量；
- 数据类型；
- 默认值；
- 最大长度；
- 缺失行为；
- 是否敏感；
- 示例值。

未知变量或缺失必填变量必须在调用模型前失败。敏感变量的运行日志只保存脱敏值或摘要。

## 10. 场景预设

场景预设是一组模块选择规则，不是完整 Prompt 文本。

预设支持：

- 用户名称和稳定 key；
- 内容类型、语言、市场等匹配条件；
- 必选模块；
- 可选模块；
- 模块顺序；
- 默认生成策略；
- 预设版本。

系统提供自动、推荐和专家三种使用方式：

- 自动：系统选择生产版本；
- 推荐：系统预选，运营可调整非强制模块；
- 专家：有权限的管理员指定明确模块版本。

现有 4 类 Prompt 不删除。迁移工具或种子数据将其映射为 Legacy 模块和兼容预设，旧任务继续引用原 `prompt_id`。

## 11. Prompt 编译和冲突处理

### 11.1 固定编译区段

```text
1. 系统角色与任务目标
2. 法律、平台和真实性硬约束
3. 品牌与知识证据规则
4. 内容策略和内容蓝图
5. 内容类型、SEO 与 GEO 要求
6. 语言、市场与写作风格
7. 关键词、产品和动态上下文
8. 输出格式
9. 质量检查清单
```

### 11.2 冲突优先级

```text
法律及平台硬约束
> 真实性和证据约束
> 账号阶段约束
> 品牌规范
> 内容策略
> 风格偏好
```

冲突解析器不依赖模型自由判断。可自动解决的冲突记录解决结果；无法安全解决的冲突阻止生成并返回可操作错误。

### 11.3 Token 预算

编译前估算上下文长度。超限时按以下顺序处理：

1. 去除重复的参考片段；
2. 缩减低相关知识；
3. 压缩外部参考内容；
4. 保留强制模块、策略和蓝图；
5. 仍超限则阻止生成并建议更换模型或缩小任务。

系统不得静默删除强制模块。

## 12. 生成运行与血缘

每次初稿生成、重试和定向修订都创建独立运行或运行步骤。运行至少保存：

- 简报、策略版本和蓝图版本；
- 预设版本；
- 实际模块版本及顺序；
- 变量安全快照；
- 知识证据引用及必要快照；
- 最终编译 Prompt；
- 模型、提供商和生成参数；
- 模型调用状态、Token 和成本；
- 原始输出；
- 质量报告；
- 修订指令和修订输出；
- 最终采用内容；
- 关联 Article；
- 创建人、确认人和时间。

同一策略允许多个运行。只有明确执行“采用”操作的运行成为文章当前来源，历史运行保持可查。

## 13. 生成质量门禁

### 13.1 确定性检查

- 总字数和章节最低字数；
- 蓝图章节是否完整；
- 必答问题是否出现回答；
- 必需实体和事实是否覆盖；
- 标题层级；
- 空章节和过短段落；
- 重复句和近似段落；
- 关键词堆砌；
- 禁止词和风险规则；
- 链接、CTA、语言和市场一致性；
- 未解析模板变量。

### 13.2 AI 语义检查

- 是否真正回答核心问题；
- 是否存在空泛、重复或无信息段落；
- 章节之间是否矛盾；
- 是否使用了证据不支持的事实；
- 是否符合目标读者和搜索意图；
- 是否存在明显的上下文跳跃。

语义检查输出结构化 JSON，不直接修改正文。

### 13.3 定向修订

默认最多自动修订一轮，只修改质量报告明确指出的章节或问题。其他合格内容保持不变。第二次仍不通过时进入人工审核，不无限调用模型。

可能状态：

- passed；
- needs_revision；
- needs_evidence；
- model_quality_failed；
- manual_review_required。

## 14. 与现有 Article 的集成

生成运行通过适配器调用现有 `ArticleContentGenerationService`。最终采用结果创建或更新 Article 草稿，并继续使用现有：

- review_status；
- ArticleRiskScanner；
- ArticleReview；
- ArticleWorkflowTransitionService；
- 发布和分发功能。

新表保存 `article_id` 关联，Article 表第一阶段仅增加必要的轻量来源关联或不增加字段，避免承载策略详情。

ManualPublication 作为后续人工分发执行层。本阶段仅允许已审核文章沿用现有方式创建人工发布任务，不在该模型内加入搜索意图或 Prompt 模块字段。

## 15. 建议数据表

### 15.1 内容策略

- `content_briefs`
- `content_strategy_versions`
- `content_blueprint_versions`
- `content_intent_targets`
- `content_evidence_bindings`

### 15.2 Prompt 编排

- `prompt_modules`
- `prompt_module_versions`
- `prompt_presets`
- `prompt_preset_versions`
- `prompt_preset_module_versions`

### 15.3 生成运行

- `content_generation_runs`
- `content_generation_run_modules`
- `content_generation_run_evidence`
- `content_generation_run_steps`
- `content_quality_reports`

最终迁移可在不损害审计性的前提下合并过细的表，但不能把版本内容覆盖式存放在主模块表中。

## 16. 权限

- 超级管理员：管理系统模块、强制规则、生产版本和回滚；
- Prompt 负责人：创建用户模块、模块版本和预设；
- 内容运营：创建简报、确认策略和蓝图、调整非强制模块；
- 审核人员：查看生成血缘和质量报告、审核文章；
- 发布人员：只处理现有人工发布任务；
- 分析人员：第一阶段只读运行和质量数据。

修改强制模块、发布生产版本和回滚必须记录操作理由。

## 17. 内部事件

第一阶段使用 Laravel 事件和队列：

- `ContentBriefSubmitted`
- `ContentStrategyConfirmed`
- `ContentBlueprintConfirmed`
- `ContentGenerationRequested`
- `ContentGenerationCompleted`
- `ContentGenerationFailed`
- `GenerationRunAdopted`

事件监听失败不应损坏主记录。异步任务必须幂等，并使用运行 ID 防止重复生成。

## 18. 功能开关与回退

- `content_strategy_enabled`
- `modular_prompts_enabled`
- `generation_quality_gate_enabled`

关闭开关时，原 Prompt 和原 Worker 链路继续工作。新运行失败时允许管理员更换模型后重试，或在有权限的情况下转回旧渲染器，并在运行记录中保留回退原因。

## 19. 错误处理

- 必填变量缺失：生成前阻止并定位字段；
- 模块冲突：显示模块、规则和解决建议；
- 知识不足：标记 needs_evidence，不允许模型编造；
- 知识过期：默认排除，管理员明确确认后才能使用；
- Prompt 超限：按预算策略压缩，仍超限则阻止；
- 模型失败：保留运行并允许幂等重试；
- 输出过短：生成质量报告并定向修订；
- 结构化检查响应无法解析：重试一次检查，仍失败则人工审核；
- 数据并发修改：策略、蓝图和模块版本采用版本号或乐观锁；
- 历史引用删除：禁止物理删除，改为归档。

## 20. 管理界面

### 20.1 内容创建向导

1. 填写简报；
2. 查看并确认策略卡；
3. 查看并确认蓝图；
4. 查看系统选中的预设和模块；
5. 生成并查看质量报告；
6. 采用结果并进入现有文章审核。

页面明确展示本次使用的模块名称和版本，不向普通运营暴露完整系统 Prompt，具有权限的用户可查看最终编译快照。

### 20.2 Prompt 管理

- 模块列表和过滤；
- 创建模块；
- 新建版本；
- 版本差异；
- 发布、停用和回滚；
- 查看使用该版本的生成运行和文章；
- 预设及模块顺序编辑；
- 变量契约编辑与校验。

## 21. 测试策略

### 21.1 单元测试

- 变量契约校验；
- 模块选择规则；
- 冲突优先级；
- Prompt 编译顺序；
- Token 预算裁剪；
- 蓝图校验；
- 确定性质量检查；
- 状态迁移；
- 历史版本不可变。

### 21.2 功能测试

- 完整向导；
- 权限隔离；
- 创建模块和新版本；
- 发布与回滚；
- 生成运行快照；
- 采用运行创建 Article；
- 旧 Prompt 和旧 Worker 回归；
- 模型失败与重试；
- 知识不足和 Prompt 冲突。

### 21.3 集成测试

- KnowledgeRetrievalService 证据注入；
- ArticleContentGenerationService 调用；
- ArticleRiskScanner 和审核状态；
- 队列幂等；
- PostgreSQL 迁移和约束。

## 22. 验收标准

第一阶段完成必须同时满足：

1. 运营可创建简报并确认策略和蓝图；
2. 管理员可创建、版本化、发布和回滚 Prompt 模块；
3. 系统能够根据预设组装明确模块版本；
4. 变量缺失和规则冲突在模型调用前被发现；
5. 核心文章能够完成蓝图、全文生成、质量检查和一次定向修订；
6. 每次生成可追溯模块、证据、模型、最终 Prompt 和输出；
7. 选定结果能够进入现有 Article 审核发布流程；
8. 原有 Prompt、Task、Worker 和 Article 流程的回归测试通过；
9. 新功能可通过功能开关关闭；
10. 已使用的版本和运行记录无法被无审计覆盖或删除。

## 23. 后续阶段接口

第一阶段为后续保留以下稳定标识：

- 内容策略版本 ID；
- 内容蓝图版本 ID；
- 目标搜索意图和关键词簇关联；
- 生成运行 ID；
- Prompt 模块版本 ID；
- Article ID；
- 未来监测问题集合。

第二阶段可在不改变生成核心的情况下增加搜索意图机会发现；第三阶段通过 ArticlePublished 事件接入 SEO/GEO 回测；第四阶段让账号周计划生成内容任务并复用 Prompt 编排和 ManualPublication。
