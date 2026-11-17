# MediQueue

![CI](https://github.com/SynapseOperator/MediQueue/actions/workflows/ci.yml/badge.svg?branch=main)

单医院、多科室的医院预约挂号课程项目。采用 PHP 8.4 / Laravel 13、MySQL 8.4、Blade / Tailwind CSS / Vite。

当前为 **P2 账户与医院资源阶段**：P1 已验收合并。已实现患者注册、登录退出、本人资料，三角色服务端权限，科室/医生公开目录，医生只读工作台，以及管理员的科室、医生、排班和号源管理。预约、抢号、模拟支付、退款及统计业务流程留在 P3—P5，当前不能作为完整医院系统使用。[P2 验证记录](docs/verification/P2.md) · [认证与资源设计](docs/architecture/adr-0003-p2-auth-resources.md) · [ER 图与表结构](docs/architecture/p1-database-schema.md) · [P1 历史记录](docs/verification/P1.md)。

本期支付路线仅为 **模拟微信支付／无实际扣款**，不接入真实商户，不用于真实临床服务。所有演示资料均须为合成数据。当前未提供付款入口，模拟开关默认关闭；之后也只允许显式 local/demo/testing 环境启用。

## 快速启动

需要 PHP 8.4（含 PDO MySQL、mbstring、OpenSSL、DOM/XML、curl、fileinfo）、Composer 2、Node.js 24 / npm、Docker Compose。也可使用已有 MySQL 8.4，按 `docker/mysql/init.sh` 的权限边界自行建库。仅需一个 PHP 应用和一个 MySQL，无需 Redis 或后台队列。

```sh
git clone https://github.com/SynapseOperator/MediQueue.git
cd MediQueue
# 若 P2 尚未合并，先 git switch feat/p2-auth-resources
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

P2 本机测试使用独立原生 **MySQL 8.4.13 / 127.0.0.1:3308**：现有 Docker Desktop 的机器级安装注册信息缺失，当前权限无法恢复；其数据盘保留。本地三个环境文件仅将端口改为 3308，原件备份在被忽略的 `.local/mysql-p2-env-backup/`。当前原生实例使用独立数据目录，已初始化合成演示资料，未迁移或覆盖 Docker 库的数据。CI 与上方通用 Compose 启动方式保持不变。

本机重启后可在 PowerShell 启动该独立实例（已有进程运行时不必重复启动）：

```powershell
Start-Process -FilePath 'D:\Bread_coding\.tools\mediqueue\mysql\bin\mysqld.exe' -WindowStyle Hidden -ArgumentList '--no-defaults --basedir=D:/Bread_coding/.tools/mediqueue/mysql --datadir=D:/Bread_coding/.tools/mediqueue/mysql-p2-data --port=3308 --bind-address=127.0.0.1 --mysqlx=0 --log-error=D:/Bread_coding/.tools/mediqueue/mysql-p2-error.log'
```

该实例不是 Windows 自动启动服务。恢复 Docker 环境后若要切回 3307，先明确选择对应数据源，再调整环境文件；不要把两个独立库误认为同一份业务数据。

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

数据库测试明确要求 MySQL 8.x 和专用 `mediqueue_test`，在测试 DDL 前核验实际数据库，不能指向业务库或用 SQLite 代替。保留 P0/P1 全部有效检查，P2 新增真实 Session/CSRF、角色和对象归属、资源历史及时间约束、管理事务回滚和双进程资源锁竞争测试。运行时应独占测试库，不同时启动两套 PHPUnit；本轮不以管理并发测试代替 P3 抢号或 P4 支付竞态验收。

[GitHub Actions](https://github.com/SynapseOperator/MediQueue/actions/workflows/ci.yml) 在 PR、main push、手动触发时执行四项必需检查：`quality`、`mysql`、`frontend`、`secrets`。使用锁定 SHA 的 actions；MySQL 服务隔离、凭据临时生成；Gitleaks 扫描全部 Git 历史并隐藏匹配值。JUnit 结果作为 CI artifact 保存，不上传环境文件或应用日志。

## 工程与演示

- `app/Http/Controllers/HealthController.php`：只读数据库就绪检查。
- `config/mediqueue.php`：医院展示时区与模拟模式边界；存储和比较使用 UTC。
- `database/migrations/`：users、patients、departments、doctors、schedules、slots、appointments、payment_orders、refunds、audit_logs 共十张核心表；sessions 与 migrations 是额外的框架技术表。
- `app/Models/User.php`：适配 `password_hash`；`routes/auth.php` 与角色中间件处理认证和权限。
- `app/Http/Controllers/AdminResourceController.php`：受锁保护的医院资源管理与同事务审计。
- `resources/views/`：统一布局的认证、患者、目录、医生工作台和管理页面，使用系统字体，无外部素材。
- `tests/`：配置边界及真实 MySQL 基础测试；`docker/`、`scripts/` 提供环境复现。
- `docs/spec/`：冻结规范；`docs/architecture/`：工程决定；`docs/verification/`：实际验证证据。

P1 Seeder 生成 2 科室、2 医生、3 患者、1 管理员（共 6 个账户）、2 排班、4 时段；姓名和联系方式明显标注演示，独立随机密码只保存哈希，不提供公开的可登录密码。不会创建预约、订单、退款或审计。时间按上海次日 09:00–10:00 生成并转 UTC 存储。

Seeder 只允许 local/testing/demo，生产环境即使 `--force` 也拒绝。十张业务表任一非空即拒绝重跑，防止覆盖历史或重置占用；失败整体回滚。不要用清空已有业务数据的方式重复播种。

## 演示登录与角色入口

初次使用可在交互终端为已有演示账号设置密码。命令仅允许 local/demo/testing，输入隐藏且需要确认，不接受密码参数、不打印密码，不清空业务数据；重置密码会使旧会话失效。

```sh
php artisan mediqueue:demo-password demo_admin
php artisan mediqueue:demo-password demo_doctor_1
php artisan mediqueue:demo-password demo_patient_1
```

账号范围为 `demo_admin`、`demo_doctor_1/2`、`demo_patient_1/2/3`。自行设置独立密码（至少 12 字符、最多 72 字节）；不要把密码写入仓库、脚本、截图或日志。仅支持用户名和密码 Session 登录，无“记住我”、短信或邮件找回流程。

如未运行 Seeder，可用 `php artisan mediqueue:create-admin <username>` 在 local/demo/testing 通过隐藏密码输入建立新管理员，不覆盖已有账号。医生在管理员资源页创建，账号和档案同事务提交；公开注册只能建立患者。用户名为 3–64 位 ASCII 字母、数字、下划线，统一转小写。

| 入口 | 可用身份与行为 |
|---|---|
| `/register`、`/login` | 未登录访客注册患者或登录现有账号 |
| `/patient/profile` | 患者仅维护本人姓名与联系方式 |
| `/departments`、`/doctors` | 公开科室、医生及未来 7 个自然日的开放时段；窗口可在 config/mediqueue.php 调整 |
| `/doctor/workbench` | 医生仅查看本人排班，不能编辑或进入他人排班详情 |
| `/admin/resources` | 管理员创建/编辑/启停科室和医生，配置草稿排班/号源后发布、停诊或关闭 |

排班与号源输入、显示均为北京时间，写入 UTC。相邻时段允许，重叠拒绝；已有预约的时间/关联冻结，时段开始后容量冻结，费用修改不更新已有预约金额。基础资源通过停用、关闭保留历史，无物理删除入口。医生资源的 `doctors.status` 和登录账号的 `users.status` 分别控制资源与账户；停用医生资源不会自动禁用其历史工作台，停用账号则拒绝新登录和旧会话。

`main` 已要求 PR、四项 CI 通过与讨论解决，禁止强推和删除，管理员也受规则约束。个人项目不设置无法自行满足的他人 approval 数量；负责人已授权在本地检查、独立复核和最新 CI 均通过且无遗留问题时自行合并。仓库不启用无人核验的自动合并，不绕过保护。

## 正式依据

- [开发与阶段验收指令](CODEX_START_PROMPT.md)
- [PRD V1.1](docs/spec/MediQueue_PRD_V1.1.md)
- [数据库设计 V1.1](docs/spec/MediQueue_Database_Design_V1.1.md)
- [模拟微信支付设计 V1.1](docs/spec/MediQueue_Mock_WeChat_Payment_Design_V1.1.md)

遵循 [贡献指南](CONTRIBUTING.md) 与 [安全报告流程](SECURITY.md)，采用 [MIT License](LICENSE)。下一阶段为 P3 预约事务、幂等和真实并发抢号验证；本轮不提前实现。老师是否接受模拟支付作为课程“在线挂号支付”仍属外部验收事项。
