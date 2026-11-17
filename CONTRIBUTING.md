# Contributing to MediQueue

欢迎改进文档、可访问性、测试和实现。先运行 [一键演示](README.md#快速开始)，再读与你的修改相关的 [并发设计](docs/design/concurrency.md) 或 [数据库结构](docs/architecture/p1-database-schema.md)。英文贡献也欢迎。

## 开始一个改动

1. 从 `main` 新建 `feat/...`、`fix/...` 或 `docs/...` 分支。入门可看 [good first issue](https://github.com/SynapseOperator/MediQueue/issues?q=is%3Aissue%20is%3Aopen%20label%3A%22good%20first%20issue%22)。
2. 保持一个 PR 聚焦一个问题。改变预约、权限、时间或金额规则前，先开 Issue 讨论行为与兼容性。
3. 提交信息说明实际变化，例如 `fix: preserve request key after validation error`。不要求特定提交工具。
4. PR 写清问题、修改后行为和实际验证；页面改动附截图，未运行的检查如实注明。CI 通过后请求复核，不绕过分支保护。

## 运行检查

演示环境启动后，可直接用开发镜像执行完整真实 MySQL 测试；它使用独立测试库，保留演示业务库：

```sh
docker compose --profile test run --build --rm tests --compact
```

使用 [严格开发环境](docs/development/strict-mode.md) 安装 PHP 8.4 和 Node 时，执行完整本地检查：

```sh
composer validate --strict
composer lint
composer analyse
composer audit
npm ci
npm audit --audit-level=low
npm run build
php scripts/run-schema.php test --log-junit test-results/junit.xml
```

MySQL 是测试目标，SQLite 不能替代核心验证。同一个测试库不要同时运行两套测试；只跑竞态可加 `--filter=ConcurrencyTest`。保留既有有效测试，新增测试应证明具体行为，避免机械复制实现。

## 需要保持的边界

- 核心写入保持原子性、统一锁顺序、幂等与金额快照。普通读取可以使用 Eloquent，锁与事务必须保持清晰。
- 数据和图片只用合成资料；不提交环境文件、密码、数据库备份、会话或完整内部日志。
- 支付与退款只模拟，不向真实渠道发起资金交易。
- 安全问题按 [SECURITY.md](SECURITY.md) 私下报告；一般使用问题放 [Discussions](https://github.com/SynapseOperator/MediQueue/discussions)。

AI 工具可以辅助贡献，但贡献者需要理解改动、核对证据并对提交负责。
