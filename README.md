# MediQueue

![CI](https://github.com/SynapseOperator/MediQueue/actions/workflows/ci.yml/badge.svg?branch=feat%2Fp0-foundation)

单医院、多科室的医院预约挂号课程项目。采用 PHP 8.4 / Laravel 13、MySQL 8.4、Blade / Tailwind CSS / Vite。

当前为 **P0 基础工程，待负责人验收**：首页、数据库 session、存活/就绪检查、真实 MySQL 基础测试与 GitHub Actions。账户、十张业务表、预约、支付、退款及统计尚未实现，不能作为完整医院系统使用。[实际验证记录](docs/verification/P0.md)。

本期支付路线仅为 **模拟微信支付／无实际扣款**，不接入真实商户，不用于真实临床服务。所有演示资料均须为合成数据。P0 未提供付款入口，模拟开关默认关闭；之后也只允许显式 local/demo/testing 环境启用。

## 快速启动

需要 PHP 8.4（含 PDO MySQL、mbstring、OpenSSL、DOM/XML、curl、fileinfo）、Composer 2、Node.js 24 / npm、Docker Compose。也可使用已有 MySQL 8.4，按 `docker/mysql/init.sh` 的权限边界自行建库。仅需一个 PHP 应用和一个 MySQL，无需 Redis 或后台队列。

```sh
git clone https://github.com/SynapseOperator/MediQueue.git
cd MediQueue
# P0 尚未合并时，先切换阶段分支
git switch feat/p0-foundation
composer install
php scripts/setup-local.php
docker compose --env-file .env.database up -d --wait
php artisan migrate --env=migration --force
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

数据库测试明确要求 MySQL 8.x 和专用 `mediqueue_test`，在测试 DDL 前核验实际数据库，不能指向业务库或用 SQLite 代替。测试创建并清理 `p0_probe_*` fixture，验证外键、CHECK、RESTRICT、精确金额/Unicode、事务回滚与运行账号无 DDL 权限；这些仅是基础能力验证，不表示 P1 业务约束或 P3 并发验收通过。

[GitHub Actions](https://github.com/SynapseOperator/MediQueue/actions/workflows/ci.yml) 在 PR、main push、手动触发时执行四项必需检查：`quality`、`mysql`、`frontend`、`secrets`。使用锁定 SHA 的 actions；MySQL 服务隔离、凭据临时生成；Gitleaks 扫描全部 Git 历史并隐藏匹配值。JUnit 结果作为 CI artifact 保存，不上传环境文件或应用日志。

## 工程与演示

- `app/Http/Controllers/HealthController.php`：只读数据库就绪检查。
- `config/mediqueue.php`：医院展示时区与模拟模式边界；存储和比较使用 UTC。
- `database/migrations/`：P0 仅 sessions；migrations 为框架技术表，两者不计十张核心业务表。
- `resources/views/`：Blade 首页，使用系统字体，无外部图片或字体素材。
- `tests/`：配置边界及真实 MySQL 基础测试；`docker/`、`scripts/` 提供环境复现。
- `docs/spec/`：冻结规范；`docs/architecture/`：工程决定；`docs/verification/`：实际验证证据。

P0 演示仅展示首页与健康检查，没有登录账号或预约数据。业务流程截图在对应阶段实现后补充；不使用效果图冒充已实现业务。

`main` 已要求 PR、四项 CI 通过与讨论解决，禁止强推和删除，管理员也受规则约束。个人项目不设置无法自行满足的他人 approval 数量；阶段审核和最终合并仍由项目负责人手动决定，自动合并关闭。

## 正式依据

- [开发与阶段验收指令](CODEX_START_PROMPT.md)
- [PRD V1.1](docs/spec/MediQueue_PRD_V1.1.md)
- [数据库设计 V1.1](docs/spec/MediQueue_Database_Design_V1.1.md)
- [模拟微信支付设计 V1.1](docs/spec/MediQueue_Mock_WeChat_Payment_Design_V1.1.md)

遵循 [贡献指南](CONTRIBUTING.md) 与 [安全报告流程](SECURITY.md)，采用 [MIT License](LICENSE)。下一步 P1 将按冻结设计创建十张业务表及约束，须在 P0 验收后开始。老师是否接受模拟支付作为课程“在线挂号支付”仍属外部验收事项。
