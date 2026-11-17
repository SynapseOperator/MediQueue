# MediQueue

![CI](https://github.com/SynapseOperator/MediQueue/actions/workflows/ci.yml/badge.svg?branch=main)

单医院、多科室的医院预约挂号课程项目。采用 PHP 8.4 / Laravel 13、MySQL 8.4、Blade / Tailwind CSS / Vite。

当前为 **P1 数据库模型阶段**：P0 已合并；十张核心业务表、数据库约束、虚构基础数据和真实 MySQL 测试已加入工程。认证、预约、支付、退款及统计业务流程尚未实现，不能作为完整医院系统使用。[P1 验证记录](docs/verification/P1.md) · [ER 图与表结构说明](docs/architecture/p1-database-schema.md) · [P0 历史记录](docs/verification/P0.md)。

本期支付路线仅为 **模拟微信支付／无实际扣款**，不接入真实商户，不用于真实临床服务。所有演示资料均须为合成数据。当前未提供付款入口，模拟开关默认关闭；之后也只允许显式 local/demo/testing 环境启用。

## 快速启动

需要 PHP 8.4（含 PDO MySQL、mbstring、OpenSSL、DOM/XML、curl、fileinfo）、Composer 2、Node.js 24 / npm、Docker Compose。也可使用已有 MySQL 8.4，按 `docker/mysql/init.sh` 的权限边界自行建库。仅需一个 PHP 应用和一个 MySQL，无需 Redis 或后台队列。

```sh
git clone https://github.com/SynapseOperator/MediQueue.git
cd MediQueue
# 若 P1 尚未合并，先 git switch feat/p1-core-schema
composer install
php scripts/setup-local.php
docker compose --env-file .env.database up -d --wait
php artisan migrate --env=migration --force
# 可选：仅在十张业务表为空的本地/演示库初始化虚构基础数据
php artisan db:seed
npm ci
npm run build
php artisan serve --host=127.0.0.1 --port=8000
```

访问 <http://127.0.0.1:8000>。`GET /up` 仅检查应用存活；`GET /health` 用当前运行账号读取迁移后的 sessions 表，正常返回 `{"status":"ok","database":"ok"}`，数据库不可用时返回不含连接详情的 503。

`setup-local.php` 在首次运行时生成随机密钥和数据库密码，不打印秘密、不覆盖已有文件。`.env.example` 与 `.env.testing.example` 是可公开模板；以下文件都被 Git 忽略：

| 文件 / 账号 | 用途与权限 |
|---|---|
| `.env` / mediqueue_app | HTTP 运行，仅 mediqueue 库 SELECT、INSERT、UPDATE、DELETE |
| `.env.migration` / mediqueue_migrator | 显式迁移命令，具有 mediqueue 库结构管理权限 |
| `.env.testing` / mediqueue_test | 专用 mediqueue_test 库，可创建/删除测试结构；另含运行账号用于权限检查 |
| `.env.database` / root | 仅本机 Docker 初始化和账号授权，不供 HTTP 应用加载 |

MySQL 仅绑定 `127.0.0.1:3307`。数据持久化到 Docker 卷；`docker compose --env-file .env.database stop` 停服务并保留数据。初始化脚本只在空数据目录运行，修改环境文件不会自动更改已有数据库密码。不要删除持久卷来处理普通连接问题。

本机已配置的 Windows 环境：项目在 `D:\Bread_coding\MediQueue`，PHP 在 `D:\Bread_coding\.tools\mediqueue\php\php.exe`，Composer 在 `D:\Bread_coding\.tools\mediqueue\composer.phar`。当前终端可使用：

```powershell
$env:Path = 'D:\Bread_coding\.tools\mediqueue\php;' + $env:Path
php D:\Bread_coding\.tools\mediqueue\composer.phar install
php artisan serve --host=127.0.0.1 --port=8000
```

本机环境文件已经生成，无需重复初始化。以上机器路径仅为此次交付位置，项目本身不依赖固定绝对路径。

## 测试与 CI

先启动 MySQL 并完成应用库迁移，然后运行：

```sh
composer validate --strict
php scripts/check-php-syntax.php
php vendor/bin/pint --test
composer audit
npm ci
npm audit --audit-level=low
npm run build
php artisan migrate --env=testing --force
php artisan test --log-junit test-results/junit.xml
```

数据库测试明确要求 MySQL 8.x 和专用 `mediqueue_test`，在测试 DDL 前核验实际数据库，不能指向业务库或用 SQLite 代替。保留 P0 基础检查，并在 P1 真实核心表上验证全部外键、唯一键、大小写敏感请求键、CHECK、容量、精确金额/毫秒时间、合法状态、迁移回滚重建及 Seeder 原子性。这不表示 P2 权限、P3 并发或 P4 生命周期验收已通过。

[GitHub Actions](https://github.com/SynapseOperator/MediQueue/actions/workflows/ci.yml) 在 PR、main push、手动触发时执行四项必需检查：`quality`、`mysql`、`frontend`、`secrets`。使用锁定 SHA 的 actions；MySQL 服务隔离、凭据临时生成；Gitleaks 扫描全部 Git 历史并隐藏匹配值。JUnit 结果作为 CI artifact 保存，不上传环境文件或应用日志。

## 工程与演示

- `app/Http/Controllers/HealthController.php`：只读数据库就绪检查。
- `config/mediqueue.php`：医院展示时区与模拟模式边界；存储和比较使用 UTC。
- `database/migrations/`：users、patients、departments、doctors、schedules、slots、appointments、payment_orders、refunds、audit_logs 共十张核心表；sessions 与 migrations 是额外的框架技术表。
- `resources/views/`：Blade 首页，使用系统字体，无外部图片或字体素材。
- `tests/`：配置边界及真实 MySQL 基础测试；`docker/`、`scripts/` 提供环境复现。
- `docs/spec/`：冻结规范；`docs/architecture/`：工程决定；`docs/verification/`：实际验证证据。

P1 Seeder 生成 2 科室、2 医生、3 患者、1 管理员（共 6 个账户）、2 排班、4 时段；姓名和联系方式明显标注演示，独立随机密码只保存哈希，不提供公开的可登录密码。不会创建预约、订单、退款或审计。时间按上海次日 09:00–10:00 生成并转 UTC 存储。

Seeder 只允许 local/testing/demo，生产环境即使 `--force` 也拒绝。十张业务表任一非空即拒绝重跑，防止覆盖历史或重置占用；失败整体回滚。不要用清空已有业务数据的方式重复播种。认证页面和演示登录方式留在 P2；业务流程截图将在对应阶段实现后补充。

`main` 已要求 PR、四项 CI 通过与讨论解决，禁止强推和删除，管理员也受规则约束。个人项目不设置无法自行满足的他人 approval 数量；负责人已授权在本地检查、独立复核和最新 CI 均通过且无遗留问题时自行合并。仓库不启用无人核验的自动合并，不绕过保护。

## 正式依据

- [开发与阶段验收指令](CODEX_START_PROMPT.md)
- [PRD V1.1](docs/spec/MediQueue_PRD_V1.1.md)
- [数据库设计 V1.1](docs/spec/MediQueue_Database_Design_V1.1.md)
- [模拟微信支付设计 V1.1](docs/spec/MediQueue_Mock_WeChat_Payment_Design_V1.1.md)

遵循 [贡献指南](CONTRIBUTING.md) 与 [安全报告流程](SECURITY.md)，采用 [MIT License](LICENSE)。下一阶段为 P2 账户与医院资源；本轮不提前实现。老师是否接受模拟支付作为课程“在线挂号支付”仍属外部验收事项。
