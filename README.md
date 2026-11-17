# MediQueue

![CI](https://github.com/SynapseOperator/MediQueue/actions/workflows/ci.yml/badge.svg?branch=main)

单医院、多科室的医院预约挂号课程项目。采用 PHP 8.4 / Laravel 13、MySQL 8.4、Blade / Tailwind CSS / Vite。

当前为 **P5 页面、统计与课程 SQL 阶段**：完成患者预约确认、模拟收银台、医生只读名单、管理员看板和审计，以及基于真实 MySQL 的统计对象。P6 综合验收与课程报告尚待完成，不用于真实医院服务。[浏览器验收与截图](docs/verification/p5-browser.md) · [统计口径与设计](docs/architecture/p5-analytics.md) · [SQL 与权限说明](docs/database/p5-sql.md) · [P4 验证记录](docs/verification/P4.md)。

本地全量测试最近一次为 **229 项、2871 断言、0 失败**；远端提交、CI 及合并结果见阶段 PR 的交付记录和实际 GitHub Actions 检查。

本期支付路线仅为 **模拟微信支付／无实际扣款**，不接入真实商户。所有演示资料均须为合成数据。模拟开关默认关闭，仅允许显式 local/demo/testing 环境启用，production 即使开关打开也拒绝模拟付款及退款。

## 快速启动

需要 PHP 8.4（含 PDO MySQL、mbstring、OpenSSL、DOM/XML、curl、fileinfo）、Composer 2、Node.js 24 / npm、Docker Compose。也可使用已有 MySQL 8.4，按 `docker/mysql/init.sh` 的权限边界自行建库。仅需一个 PHP 应用和一个 MySQL，无需 Redis 或后台队列。

```sh
git clone https://github.com/SynapseOperator/MediQueue.git
cd MediQueue
composer install
php scripts/setup-local.php
docker compose --env-file .env.database up -d --wait
php scripts/run-schema.php migrate
php scripts/run-schema.php grant
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
| `.env` / mediqueue_app | HTTP 运行：mediqueue 库 SELECT、INSERT、UPDATE、DELETE，以及仅对 `sp_department_statistics` 和 `fn_intervals_overlap` 的 EXECUTE |
| `.env.migration` / mediqueue_migrator | 显式迁移使用 mediqueue 库结构管理权限；报告函数安装另由短期 CLI DBA 凭据完成 |
| `.env.testing` / mediqueue_test | 专用 mediqueue_test 库，可创建/删除测试结构；另含运行账号用于权限检查 |
| `.env.database` / root | 仅初始化、短期 schema runner 和对象级授权，不供 HTTP 应用加载 |

`run-schema.php` 从本地 `.env.database` 为短生命周期 CLI 进程提供 DBA 凭据，以创建 MySQL binlog 环境下所需的函数；HTTP 常驻进程不获取该凭据。`grant` 只授予上述两个报告例程的 EXECUTE，不关闭 binlog 信任边界。迁移或测试前若有配置缓存，先运行 `php artisan config:clear`。已有 MySQL 用户也需配置匹配的 `.env.database`，并按 [SQL 说明](docs/database/p5-sql.md) 使用授权的数据库管理员。

Compose MySQL 仅绑定 `127.0.0.1:3307`。数据持久化到 Docker 卷；`docker compose --env-file .env.database stop` 停服务并保留数据。初始化脚本只在空数据目录运行，修改环境文件不会自动更改已有数据库密码。不要删除持久卷来处理普通连接问题。

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
php scripts/run-schema.php testing
php scripts/run-schema.php test --log-junit test-results/junit.xml
```

数据库测试明确要求 MySQL 8.x 和专用 `mediqueue_test`，在测试 DDL 前核验实际数据库，不能指向业务库或用 SQLite 代替。保留 P0–P4 全部有效检查；P4 新增支付/取消/回收/退款竞态、时间边界、权限、故障回滚和提交响应未知恢复验证。运行时应独占测试库，不同时启动两套 PHPUnit。单独运行并发：`php scripts/run-schema.php test --filter=ConcurrencyTest`。P3 四份 `test-results/appointment-concurrency-*.json` 与 P4 九份 `test-results/lifecycle-concurrency-*.json` 含实际 PID、MySQL 连接 ID、结果、状态和占用核对；不把顺序 HTTP 请求当并发测试。

[GitHub Actions](https://github.com/SynapseOperator/MediQueue/actions/workflows/ci.yml) 在 PR、main push、手动触发时执行四项必需检查：`quality`、`mysql`、`frontend`、`secrets`。使用锁定 SHA 的 actions；MySQL 服务隔离、凭据临时生成；Gitleaks 扫描全部 Git 历史并隐藏匹配值。JUnit 与 P3/P4 并发 JSON 随 `mysql-junit` artifact 保存，不上传环境文件或应用日志。

## 工程与演示

- `app/Http/Controllers/HealthController.php`：只读数据库就绪检查。
- `config/mediqueue.php`：医院展示时区与模拟模式边界；存储和比较使用 UTC。
- `database/migrations/`：users、patients、departments、doctors、schedules、slots、appointments、payment_orders、refunds、audit_logs 共十张核心表；sessions 与 migrations 是额外的框架技术表。
- `app/Models/User.php`：适配 `password_hash`；`routes/auth.php` 与角色中间件处理认证和权限。
- `app/Http/Controllers/AdminResourceController.php`：受锁保护的医院资源管理与同事务审计。
- `app/Services/AppointmentService.php`：患者锁、资源资格保护、条件占号、预约/订单/审计事务及原请求标识恢复。
- `app/Services/AppointmentLifecycleService.php`：按同一锁顺序处理支付确认、取消、回收、退款和人工完成；`app/Payments/` 是唯一启用的本地模拟网关。
- `resources/views/`：响应式认证、患者、医生和管理页面，包括预约确认、模拟收银台及统计视图，使用系统字体，无外部素材。
- `docs/database/p5-sql.md`：统计视图、存储过程、函数和权限路径。
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
| `GET /appointments/confirm`、`POST /appointments`、`GET /appointments`、`GET /appointments/{id}` | 仅患者；从当前登录账号确定患者，创建预约及查看本人列表/详情 |
| `GET /appointments/{id}/payment`、`POST /appointments/{id}/pay`、`POST /appointments/{id}/cancel` | 本人预约模拟付款（短期签名链接）或开始前取消；均要求 Session、角色与 CSRF |
| `/departments`、`/doctors` | 公开科室、医生及未来 7 个自然日的开放时段；窗口可在 config/mediqueue.php 调整 |
| `/doctor/workbench` | 医生仅查看本人排班，不能编辑或进入他人排班详情 |
| `/admin`、`/admin/analytics`、`/admin/audit` | 管理员看板、科室与日期统计、只读审计；金额均为模拟 |
| `/admin/resources` | 管理员创建/编辑/启停科室和医生，配置草稿排班/号源后发布、停诊或关闭 |
| `/admin/appointments`、`/admin/appointments/{id}` | 管理员逐条查看、核实异常取消、处理模拟退款、登记已就诊完成 |

排班与号源输入、显示均为北京时间，写入 UTC。相邻时段允许，重叠拒绝；已有预约的时间/关联冻结，时段开始后容量冻结，费用修改不更新已有预约金额。基础资源通过停用、关闭保留历史，无物理删除入口。医生资源的 `doctors.status` 和登录账号的 `users.status` 分别控制资源与账户；停用医生资源不会自动禁用其历史工作台，停用账号则拒绝新登录和旧会话。

患者在医生详情选择时段，核对确认页后查看待支付订单；支付截止为锁内创建时间加 15 分钟与就诊开始的较早者。请求只接受 `slot_id` 和 `request_key`（另含 CSRF token）；标识为 16–64 位 ASCII 字母、数字、下划线或连字符，保留大小写、不裁剪。相同标识必须使用相同号源，重复返回原记录；网络结果未知时先查看“我的预约”或用原标识重试，不换新标识占号。

## 模拟支付与生命周期演示

在私有 `.env` 中设置 `APP_ENV=local`（或 demo）、`MOCK_PAYMENTS_ENABLED=true` 后清除配置缓存，才能从本人待支付详情进入独立模拟收银台。不要将 `.env` 提交。付款可选择成功、拒绝或结果未知；页面重新查询持久化状态，结果未知不等于已支付。付款链接五分钟有效，同时仍受订单的实际截止限制。没有微信商户配置、真实二维码或外部资金调用。

患者仅可在就诊开始前取消。待支付取消关闭订单；已付款取消在一个事务中释放名额、创建唯一全额退款并标为待处理。管理员进入预约详情后可模拟退款成功、失败或未知；失败/未知保留原退款记录重试，预约保持已取消，不重新占号。退款尚未处理时明确显示待退款，不能当作成功到账。管理员异常取消需逐条核实未就诊并填原因；已付款且就诊已开始的预约才能登记完成，完成保留占用，不可取消。

定时回收每分钟执行，每批最多100条；预约创建前按需处理本患者或目标号源的到期候选，每条独立按患者优先锁顺序提交。仅到达截止不会直接减计数；统一回收事务实际完成 EXPIRED/CLOSED、释放和审计之后名额才可再用。支付或取消遇到到期待支付记录也走同一事务。停诊不自动批量取消，旧截止仍有效，管理员按实际情况逐条处理。

本地在另一个终端持续运行调度器，关闭终端或重启机器后需重新启动：

```sh
php artisan schedule:work
# 单次回收或排查；limit 为 1–1000
php artisan mediqueue:expire-appointments --limit=100
```

部署时配置每分钟执行 `php artisan schedule:run`（例如 cron 的 `* * * * * cd /path/to/MediQueue && php artisan schedule:run`）。任务使用 withoutOverlapping；数据库行锁和状态幂等仍是最终保护。调度器未运行不会假装已回收；按需回收可以处理当前请求相关的到期记录。不要清空业务库或手改计数来演示空位。

`main` 已要求 PR、四项 CI 通过与讨论解决，禁止强推和删除，管理员也受规则约束。个人项目不设置无法自行满足的他人 approval 数量；负责人已授权在本地检查、独立复核和最新 CI 均通过且无遗留问题时自行合并。仓库不启用无人核验的自动合并，不绕过保护。

## 正式依据

- [开发与阶段验收指令](CODEX_START_PROMPT.md)
- [PRD V1.1](docs/spec/MediQueue_PRD_V1.1.md)
- [数据库设计 V1.1](docs/spec/MediQueue_Database_Design_V1.1.md)
- [模拟微信支付设计 V1.1](docs/spec/MediQueue_Mock_WeChat_Payment_Design_V1.1.md)

遵循 [贡献指南](CONTRIBUTING.md) 与 [安全报告流程](SECURITY.md)，采用 [MIT License](LICENSE)。下一阶段 P6 完成综合验收、备份恢复及课程报告；真实支付不在本项目范围。老师是否接受模拟支付作为课程“在线挂号支付”仍属外部验收事项。
