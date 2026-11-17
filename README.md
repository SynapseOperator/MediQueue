# MediQueue

单医院、多科室的医院预约挂号课程项目，采用 PHP / Laravel 与 MySQL。

当前状态：V1.1 需求与设计基线已归档；P0 基础工程开发中，尚未完成验收。

本期仅实现明确标注的 **模拟微信支付／无实际扣款**，不接入真实商户，不用于真实临床服务。所有演示资料均须为合成数据。

## 正式依据

- [开发与阶段验收指令](CODEX_START_PROMPT.md)
- [PRD V1.1](docs/spec/MediQueue_PRD_V1.1.md)
- [数据库设计 V1.1](docs/spec/MediQueue_Database_Design_V1.1.md)
- [模拟微信支付设计 V1.1](docs/spec/MediQueue_Mock_WeChat_Payment_Design_V1.1.md)

`main` 保存经审核的基线；每阶段使用功能分支和 Pull Request，CI 通过后交项目负责人验收，不自动合并。

采用 [MIT License](LICENSE)。
