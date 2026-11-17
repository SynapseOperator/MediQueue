# 演示与开发环境

## 一键演示

安装 Docker 和 Compose，在仓库根目录执行：

```sh
docker compose up --build
```

打开 http://localhost:8080。镜像包含 PHP 应用和已构建的页面资源，Compose 启动隔离 MySQL、初始化任务、Web 与定时回收。第一次启动自动创建表、数据库对象及合成资料，并显示随机生成的登录凭据。账号包括 `demo_patient_1`、`demo_doctor_1`、`demo_admin`；以启动输出为准，不使用公开固定密码。

无需环境文件。需要改 Web 端口时，复制 `demo.env.example` 为 `.env`，再调整 `DEMO_PORT`。`DEMO_PRINT_CREDENTIALS=false` 可以关闭首次明文凭据输出，适用于 CI；不要把显示凭据的终端录屏或完整容器日志上传公开仓库。

重启不会重新播种或重置密码，关闭使用 `docker compose down`。默认 MySQL 不映射到宿主机端口，数据保存在这套演示自己的卷中；现有原生数据库与严格模式数据不受影响。不要以删除卷作为日常修复连接问题的方法。第一次输出的凭据应由演示操作者保存；忘记密码可在受控环境使用现有隐藏输入密码管理命令，而不是清空业务数据。

演示模式简化的是配置步骤，保留了 Session、服务端角色权限、CSRF、事务、约束和模拟边界。它不是面向公网的生产部署方案；需要单独配置 TLS、运行身份、备份、日志和运维。数据库权限设计见 [严格模式](strict-mode.md)。

## 三角色走一遍

1. **患者**：登录后查找医生，选择未来开放时段，核对金额并创建预约。页面显示真实截止时间和待支付状态。
2. **模拟付款**：进入“模拟微信支付／无实际扣款”页面，选择模拟成功。也可观察拒绝或结果未知；未知时先刷新原订单核对，不能当作已支付。
3. **患者取消**：在就诊开始前取消已确认预约，查看已取消与待处理退款。取消成功不代表退款已经完成。
4. **管理员**：退出患者账号，登录管理员，在预约详情处理同一退款。先模拟失败，再重试成功；确认退款记录身份不变、预约未复活。
5. **医生**：退出管理员，登录医生查看本人排班与授权患者名单。医生没有管理资源的写入口。
6. **管理资源**：管理员可创建科室、医生、草稿排班和时段，发布后开放预约。停诊只停止新预约及待支付付款，既有预约需要逐项核实处理。

所有时间按医院时区显示，存储与判断使用 UTC。未来号源过期后，可以由管理员创建新排班，或使用新的独立演示场景；不要直接改动已有预约的时间和关联。

## Codespaces 与 Dev Containers

仓库提供 [.devcontainer](../../.devcontainer) 配置，复用演示服务和开发镜像。在 GitHub 选择 **Code → Codespaces → Create codespace**，或在 VS Code 选择 **Reopen in Container**。初始化会安装依赖并构建前端；通过 Ports 面板打开转发的 **8000** 端口，保持端口可见性为 Private。初始化凭据可在该私有环境的终端用 `docker compose logs setup` 查看，不要公开这份日志。

Codespaces 是开发环境，不代表有永久在线演示站点，也不保证免费额度。数据库仍使用独立卷；停止或删除环境前按需保留数据。具体容器自动验收结果以 PR 的 CI 记录为准。

开发容器显式设置 `MEDIQUEUE_TRUST_DEMO_PROXY=true`，使 HTTPS 端口转发下的资源和签名链接使用正确协议。该配置仅在 `APP_ENV=demo` 生效，普通演示及生产环境不会因此信任转发头；配置边界与签名验证有自动化测试。机制见 [Laravel 可信代理说明](https://github.com/laravel/docs/blob/13.x/requests.md#configuring-trusted-proxies)。

容器中运行完整回归：

```sh
docker compose --profile test run --build --rm tests --compact
```

测试服务使用开发镜像及独立 `mediqueue_test`，不清空演示业务库；同一测试库不要同时启动多个测试任务。

## 不使用容器

PHP 8.4、Composer 2、Node 24 与 MySQL 8.4 的手动步骤见 [严格模式](strict-mode.md)。Windows 使用已有原生实例时见 [Windows 说明](../windows-demo.md)。这两条路径不需要修改现有业务规则，也不能把测试库指向业务库。
