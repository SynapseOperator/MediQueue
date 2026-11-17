# v1.0.0 开源整理验证

2026-11-17，保留全部既有 MySQL 测试，新增三种开发代理配置的签名链接回归。测试数量为 **237**；并发中不同合法执行分支会有不同断言数量，不能仅凭断言数量判断是否遗漏测试。

## 本地已执行

以下命令均退出 0。PHP 8.4、MySQL 8.4.13、UTC、READ COMMITTED；业务库与测试库隔离。

| 检查 | 实际结果 |
|---|---|
| `php scripts/run-schema.php test --compact --no-ansi --log-junit test-results/open-source-local.xml` | 237 测试、2961 断言、0 失败，32.34 秒 |
| `composer validate --strict` / `composer audit` | 有效，无已知依赖漏洞 |
| `composer analyse` | Larastan level 5，覆盖 app，无错误；无 baseline 或 ignore |
| `php vendor/bin/pint --test` / PHP 语法 | 通过；140 文件、0 语法失败 |
| `npm audit --audit-level=low` / `npm run build` | 无已知漏洞，构建通过 |
| Compose 默认/开发配置、Bash/PHP/JSON 静态检查 | 通过 |
| Windows 启动器 `-CheckOnly` | 使用配置中的数据库端口，HTTP、数据库和项目调度器正常 |

独立源码复核未发现状态、锁顺序、授权、幂等、金额、容量或历史保护退化。真实浏览器另采集了新合成患者的预约、模拟付款、取消、退款失败重试成功流程，见 [90 秒 GIF 与说明](../demo.md)。

## 远端必须执行的项目

四项必需检查仍为 `quality`、`mysql`、`frontend`、`secrets`。`quality` 新增 Larastan；`mysql` 保留既有数据库回归，并运行 [`scripts/demo/ci.sh`](../../scripts/demo/ci.sh)：

1. 完整演示容器首启和三角色 HTTP/CSRF/Session 冒烟。
2. 十张核心表、技术表、两视图和两个例程及运行账号权限检查。
3. 重建容器但保留数据卷，核对账户、密码哈希及资源未重置。
4. 通过公开 `tests` profile 在独立测试库运行完整 MySQL 回归。
5. 开发容器真实安装依赖、构建、健康与静态分析；默认/显式启用 HTTPS 转发的探针。

CI 明确禁用演示口令打印，不上传包含凭据的容器日志。本地仅做了 Compose 静态解析，**未据此声称镜像运行通过**；实际容器运行、最终提交与合并结果以本版本的 PR、[CI](https://github.com/SynapseOperator/MediQueue/actions/workflows/ci.yml) 和 [Release](https://github.com/SynapseOperator/MediQueue/releases/tag/v1.0.0) 为准。没有创建或运行托管 Codespace，不将本地/CI 的 Dev Container 验证说成真实 Codespaces 平台验收。
