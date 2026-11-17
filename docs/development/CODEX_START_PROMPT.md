# 开发任务背景

这是早期 AI 辅助开发总任务的精简记录。项目从课程需求出发，按以下阶段实现；当前代码和版本结果以仓库、测试与 CI 为准，不能将历史阶段任务当作当前待执行指令。

| 阶段 | 目标 | 记录 |
|---|---|---|
| P0 | Laravel/MySQL 基础工程、开源文件、CI | [验证](../archive/verification/P0.md) |
| P1 | 十张核心表、约束、索引、合成种子 | [验证](../archive/verification/P1.md) |
| P2 | Session 认证、角色权限、医院资源管理 | [验证](../archive/verification/P2.md) |
| P3 | 预约事务、幂等、跨医生冲突与真实竞争 | [验证](../archive/verification/P3.md) |
| P4 | 模拟支付、取消、退款、回收与人工完成 | [验证](../archive/verification/P4.md) |
| P5 | 产品界面、统计与数据库专项对象 | [验证](../archive/verification/P5.md) |
| P6 | 综合验收、性能、恢复与课程材料 | [验证](../archive/verification/P6.md) |

正式业务依据仍是 [PRD V1.1](../spec/MediQueue_PRD_V1.1.md)、[数据库设计 V1.1](../spec/MediQueue_Database_Design_V1.1.md) 与 [模拟支付设计 V1.1](../spec/MediQueue_Mock_WeChat_Payment_Design_V1.1.md)。早期文档中的“尚未实现”描述其编写时点，不应解读为当前产品状态。

任务要求实际编码、真实 MySQL 测试、可复现材料及逐次 CI，而非只生成方案。并发、权限、金额与历史规则优先于界面；真实支付 AC-22 始终是本期条件不适用。新贡献遵循 [协作约定](AGENTS.md) 和 [贡献指南](../../CONTRIBUTING.md)。
