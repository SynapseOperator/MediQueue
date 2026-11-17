# UI refresh 浏览器验收记录

2026-11-17 在本机 Chrome、PR #8 的最终修复构建和合成数据上进行真实浏览器检查。付款和退款均为模拟，无实际资金流转；本记录不替代完整无障碍审计或 CI。

- 首页在 1440×900、768×900、390×844、320×800 下视觉检查。原 320px 首页有水平滚动条（`scrollWidth=320`，`clientWidth=305`）；修复后重载为 `scrollWidth=clientWidth=305`。`home-320-before.jpg` 为修复前，`home-320-final.jpg` 为修复后。
- 患者桌面医生目录、时段卡片、确认页和 320px 模拟收银台可读；320px 确认及收银台根页面均测得 `scrollWidth=clientWidth=305`，步骤文本可换行。合成患者预约 9 的模拟支付 UNKNOWN 后提示先核实原记录；原订单重试成功后患者取消，管理员对同一退款先模拟失败再重试成功。
- 管理员桌面概览可读，退款失败/成功状态和模拟边界可见；资源管理中只读展开合成排班表单，未提交更改。720×400 CSS 视口下原菜单底部被截断；限高滚动修复后 Tab 可使菜单滚动到底，退出按钮获得焦点。
- 另以 Chrome 原生窗口 Ctrl+Plus 调整缩放，Chrome 原生提示显示 **Zoom: 200%**。实际放大后的首页可读；管理员展开菜单后连续 Tab 到达底部，菜单自动滚动，账号和有焦点的退出按钮均可见，并通过键盘退出。之后 Ctrl+0 的 Chrome 原生提示显示 **Zoom: 100%**，已重置临时视口并返回首页。早先原生窗口不可用时的 720×400 检查仅是等效视口，不作为真实缩放证据。
- 医生桌面和 390px 工作台可读并显示本人停诊/开放排班。访客、合成患者、医生、管理员均曾访问；最后已退出受控患者及管理员会话。
- 补充边界场景：空科室 10 显示 0 位医生及返回全部科室入口；医生 6 的 09:00 号源标记“已满/已约满”，无选择按钮；患者预约 14 显示已过期和订单关闭，管理员也可见过期状态。预约 15 的详情和模拟收银台提示停诊导致付款资格失效，未显示付款按钮。虚构错误登录显示“账号或密码错误，或账号已停用”，密码框清空。
- 边界 fixture 仅新增合成数据，既有 users、slots、appointments、orders、refunds 逐行不变，账实差为 0；预约 14 由真实服务回收，15 为停诊未支付。以上边界状态和真实 200% 均已实际观察，但本次补查未另外保存这些画面的截图。

截图目录：`screenshots/ui-refresh/`。`home-1440.jpg`、`home-768.jpg`、`home-390.jpg`、`home-320-final.jpg`、`doctors-1440.jpg`、`slots-1440.jpg`、`confirm-1440.jpg`、`confirm-320.jpg`、`payment-320.jpg`、`admin-1440.jpg`、`admin-menu-fixed.jpg`、`doctor-390.jpg` 为上述时点的真实视图；早期截图可能早于最终颜色或菜单修复。截图仅含合成资料，无密码。

补充只读验收：医生 4 当前显示 0 个时段，空状态说明可能尚未发布、已经开始或已停诊，并提供选择其他医生入口（`no-slots.png`）。管理员统计明细显示指标、日期口径、趋势及科室容量/占用表（`analytics-detail.png`）；审计页面显示筛选项和按时间、动作、操作者及对象排列的记录（`audit.png`）。三页均经浏览器实际查看，未发现新的视觉阻断；截图仅为页面内容。验收后管理员已退出并返回首页，沿用默认视口。

## 自动化检查及交付依据

浏览器验收与自动化检查分别执行，浏览器代理没有并行操作共享测试库。主代理本轮完成的本地检查如下；表内命令均退出 0。

| 检查 | 命令与实际结果 |
|---|---|
| 完整真实 MySQL 回归 | `php scripts/run-schema.php test --compact --no-ansi --log-junit test-results/ui-refresh-local-junit.xml`：234 测试、2939 断言，失败/错误/跳过均 0；JUnit 时间 33.946757 秒 |
| PHP 语法与风格 | `php scripts/check-php-syntax.php`：126 文件；`php vendor/bin/pint --test`：通过 |
| PHP 依赖 | `composer validate --strict`、`composer audit`：通过，无已知漏洞 |
| 前端依赖及构建 | `npm ci`、`npm audit --audit-level=low`、`npm run build`：通过，无已知漏洞 |
| Blade | `php artisan view:cache`、`php artisan view:clear`：通过 |
| 敏感信息 | Gitleaks 暂存区扫描：无泄漏 |
| 本机运行 | `scripts/windows/start-demo.ps1 -CheckOnly`：MySQL 8.4.13、UTC/READ-COMMITTED、HTTP 和项目调度器正常；12 条预约、12 条订单、6 条退款，容量账实差异 0 |

本地完整回归使用改版实现提交 `0fc1f41f5dd6e6d730bc1e893727ef4b456dee8d`；后续修复提交 `06a1464f8d56f00f9218fa31c7fd093ea1e10b79` 仅涉及 CSS 与一个 Blade 颜色类，独立源码复核无阻断。该修复的[远端 CI](https://github.com/SynapseOperator/MediQueue/actions/runs/44078686363)四项通过，下载的 MySQL JUnit 为 234 测试 / 2939 断言 / 0 失败、错误、跳过。后续文档提交 `8d53a32edb438cb951278d789add6e3ef6ee215a` 的[远端 CI](https://github.com/SynapseOperator/MediQueue/actions/runs/44081161815)也得到相同结果。

实际读取 main 分支保护：要求 PR、严格同步检查、`quality/mysql/frontend/secrets` 四项成功，规则对管理员生效，并要求解决审查讨论。最终证据提交仍须重新通过这四项检查后才可合并；最终 HEAD、合并 SHA、PR/main CI 与本机更新结果统一记在 [PR #8](https://github.com/SynapseOperator/MediQueue/pull/8)，避免将尚未执行的发布写成已完成。

结论：所观察的主要流程、边界状态、真实 200% 导航及修复项未发现剩余视觉阻断。此验收覆盖本机 Chrome 的上述场景，不等同所有浏览器和设备的完整无障碍认证。本次部署验收仅覆盖本地演示站点。
