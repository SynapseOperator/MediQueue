# 严格开发模式：显式数据库权限与完整测试

首次体验请用 [一键演示](environments.md)。本页适合需要调试 PHP、运行完整真实 MySQL 测试、研究最小数据库权限或自行部署的贡献者。权限分离是可复用的部署边界，不等同已完成生产安全认证。

## 准备与启动

需要 PHP 8.4（PDO MySQL、mbstring、OpenSSL、DOM/XML、curl、fileinfo 等 Composer 所需扩展）、Composer 2、Node 24/npm 和 MySQL 8.4。以下步骤在新的工作副本中执行；`setup-local.php` 遇到已有环境文件会拒绝覆盖。

```sh
composer install
php scripts/setup-local.php
docker compose -f compose.strict.yaml --env-file .env.database up -d --wait
php scripts/run-schema.php migrate
php scripts/run-schema.php grant
# 仅在十张核心表均为空时，初始化合成资料：
php artisan db:seed
npm ci
npm run build
php artisan serve --host=127.0.0.1 --port=8000
```

另一个终端运行 `php artisan schedule:work`，访问 http://127.0.0.1:8000。严格模式 Compose 只提供 MySQL，应用和 Node 由开发者运行。它保留独立的原有严格模式数据卷；不要和一键演示的配置文件混用。

使用已经安装的 MySQL 时，跳过 Compose，按 [初始化脚本](../../docker/mysql/init.sh) 创建应用/测试库和对应账号，并在私有环境文件中填写实际连接参数。不要将 HTTP 改为 root 登录来回避权限配置。

## 四个私有文件各自做什么

| 文件 | 身份与职责 |
|---|---|
| `.env` | 应用运行账号：业务库 DML，及两个具体报告例程的 EXECUTE |
| `.env.migration` | 迁移账号：显式业务库 DDL，不供 HTTP 使用 |
| `.env.testing` | 隔离 `mediqueue_test` 测试库，含验证权限所需的运行身份 |
| `.env.database` | 初始化与短期 CLI 管理连接，用于创建例程和授权，不供常驻应用加载 |

文件由 `scripts/setup-local.php` 生成随机密码并写入忽略路径。示例文件不含可用秘密。MySQL 启用 binlog 时，安装函数可能需要额外管理权限；`scripts/run-schema.php` 仅在短生命周期进程中取得这些权限。运行账号只获得 `sp_department_statistics` 与 `fn_intervals_overlap` 的对象级 EXECUTE；不通过关闭全局信任边界解决安装问题。详见 [SQL 与例程权限](../database/p5-sql.md)。

修改连接前先确认对应数据源。已有数据目录的密码不会因修改环境文件而自动改变，不要删除持久卷处理普通登录故障。有 Laravel 配置缓存时先执行 `php artisan config:clear`，不要缓存 CLI 的临时管理身份。

## 运行测试

```sh
composer validate --strict
php scripts/check-php-syntax.php
composer lint
composer analyse
composer audit
npm audit --audit-level=low
npm run build
php scripts/run-schema.php testing
php scripts/run-schema.php test --log-junit test-results/junit.xml
```

测试入口校验专用库名；核心数据库测试不使用 SQLite。一次只运行一套测试，避免并发测试彼此清理 fixture。`--filter=ConcurrencyTest` 可只跑真实进程竞争；JUnit 和 JSON 结果保存在忽略的 `test-results/`。

CI 保留 `quality`、`mysql`、`frontend`、`secrets`：静态分析/代码质量、真实 MySQL/并发、前端构建/依赖审计、敏感信息扫描。修改容器入口还需通过独立容器冒烟。以具体提交的 CI 为准，不用历史结果替代当前检查。

## 登录与演示

严格种子默认只保存随机密码哈希，不输出可长期使用的固定密码。在交互终端为合成账号设置自己的密码：

```sh
php artisan mediqueue:demo-password demo_admin
php artisan mediqueue:demo-password demo_doctor_1
php artisan mediqueue:demo-password demo_patient_1
```

该操作仅用于你管理的演示环境，隐藏输入并确认，不清空业务资料；修改密码会使旧会话失效。如要保留现有账号密码，使用新的 `mediqueue:demo-scenario <前缀>` 场景或网站患者注册。模拟支付另需在私有 `.env` 显式启用 `MOCK_PAYMENTS_ENABLED=true`，且环境为 local/demo/testing；production 始终拒绝模拟入口。

SQL 备份和性能实验只针对独立目标库，不能对正在演示的业务库压测或恢复覆盖。可复现脚本及原始结论见 [性能实验](../performance/p6-report.md)、[隔离恢复记录](../archive/verification/p6-backup-restore.md)。
