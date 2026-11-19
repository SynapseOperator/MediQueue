# 门诊挂号页面验收

所有截图来自 Chrome 浏览器中的独立合成 MySQL 数据库。患者由确认页提交预约，收银台截图保留待支付订单及三个模拟结果按钮。另一个患者已确认的预约用于医生名单与管理列表。

| 页面 | 1440 px | 768 px | 390 px | 320 px |
|---|---|---|---|---|
| 首页 | [1440](home-1440.jpg) | [768](home-768.jpg) | [390](home-390.jpg) | [320](home-320.jpg) |
| 医生详情及号源 | [1440](doctor-detail-1440.jpg) | [768](doctor-detail-768.jpg) | [390](doctor-detail-390.jpg) | [320](doctor-detail-320.jpg) |
| 预约确认 | [1440](confirm-1440.jpg) | [768](confirm-768.jpg) | [390](confirm-390.jpg) | [320](confirm-320.jpg) |
| 模拟收银台 | [1440](payment-1440.jpg) | [768](payment-768.jpg) | [390](payment-390.jpg) | [320](payment-320.jpg) |
| 我的预约 | [1440](appointments-1440.jpg) | [768](appointments-768.jpg) | [390](appointments-390.jpg) | [320](appointments-320.jpg) |
| 管理看板 | [1440](dashboard-1440.jpg) | [768](dashboard-768.jpg) | [390](dashboard-390.jpg) | [320](dashboard-320.jpg) |
| 预约处理 | [1440](admin-appointments-1440.jpg) | [768](admin-appointments-768.jpg) | [390](admin-appointments-390.jpg) | [320](admin-appointments-320.jpg) |
| 医生工作台 | [1440](workbench-1440.jpg) | [768](workbench-768.jpg) | [390](workbench-390.jpg) | [320](workbench-320.jpg) |

32 次导航均返回 HTTP 200，文档宽度均与视口一致。320 px 下逐页使用键盘展开菜单并核对可见链接。医生详情显示七天和实际可约时段，首页按医生姓名查询后打开对应排班。管理列表在手机上按标签和值显示每条预约，操作入口可见。

在这 32 个页面状态上运行 axe-core 4.14.1 的 color-contrast、label、button-name、link-name 检查，违规及待人工判定项均为 0。此记录涵盖上述页面与规则。

| 本地检查批次 | MySQL 测试 | 断言 | 失败 |
|---|---:|---:|---:|
| 全局布局 | 237 | 2966 | 0 |
| 首页 | 237 | 2966 | 0 |
| 医生与选时段 | 237 | 2966 | 0 |
| 预约与支付 | 237 | 2966 | 0 |
| 管理端 | 238 | 2978 | 0 |
| 医生工作台 | 238 | 2973 | 0 |
| 演示数据 | 238 | 2978 | 0 |
| 最终视觉复核 | 238 | 2973 | 0 |

每批执行 `php vendor/bin/pint --test`、`npm run build`、`php scripts/run-schema.php test --compact --no-ansi --log-junit …`，退出码均为 0。最终版本另行运行 Larastan，退出 0。竞态的不同合法结果会产生不同的断言数。
