# P5 统计口径与只读审计

依据 PRD V1.1 第7节与数据库设计第4.1、7、8节。统计入口是 [AnalyticsController](../../app/Http/Controllers/AnalyticsController.php) / [AnalyticsService](../../app/Services/AnalyticsService.php)；后者包含实际执行的九指标 CTE、创建趋势 GROUP BY 和待处理查询。三个后台路由全部经过 Session、启用账号与 ADMIN 角色限制。患者和医生不可读取统计或审计。这里只读，不调用预约生命周期、不修复计数、不修改历史。两视图、过程和函数的实际定义见 [Reporting migration](../../database/migrations/2026_11_17_010000_create_reporting_objects.php)，部署及调用权限见 [P5 数据库查询对象](../database/p5-sql.md)。

## 日期与九项指标

页面接收包含两端的 `from` / `to` 上海日历日期，默认本月1日至今天。时间戳过滤由 PHP 按 Asia/Shanghai 换算为 UTC 半开区间 `[起日00:00, 末日次日00:00)`，毫秒边界不会因 `23:59:59` 截断丢数据。排班 `work_date` 本身就是医院日期，直接包含两端比较。

| 指标 | 日期依据 | 精确口径 |
|---|---|---|
| 新建预约 | appointments.created_at | 范围内全部创建，包括后来取消、过期的历史 |
| 当前已确认 | schedules.work_date | 范围内当前 CONFIRMED，不含 COMPLETED |
| 已完成就诊 | schedules.work_date | 范围内当前 COMPLETED，仅人工核实结果 |
| 名额占用率 | schedules.work_date | 范围内 sum(occupied_count) / sum(capacity)，每个slot只计一次 |
| 已支付有效预约 | schedules.work_date | 当前 CONFIRMED + COMPLETED |
| 创建队列取消率 | appointments.created_at | 同一创建日期队列中当前 CANCELLED 数 / 新建预约数；不是按取消发生日分子拼接创建日分母 |
| 模拟付款金额 | payment_orders.paid_at | 已产生模拟付款事实的金额，包括随后取消或退款的订单 |
| 模拟成功退款 | refunds.succeeded_at | 仅 SUCCEEDED 金额，不把 PENDING / FAILED 义务当成已经退额 |
| 模拟净额 | 两种结算事件分别落在范围内 | 本期模拟付款减本期成功退款，允许为负；不是同一预约队列的利润 |

所有金额由 MySQL DECIMAL 聚合和相减，PHP/Blade 直接显示精确字符串，不使用浮点金额计算。百分比只用于显示，保留一位小数；零分母显示“—”，不虚构0%或100%。所选号源存在账实差异时，总览及全部科室占用率显示“待核对”，不绘制占用率条形，原始计数保留供调查。所有页面标明模拟微信支付、无实际扣款，不能解释为真实收款或会计收入。

创建数量变化由独立真实 SQL 按上海创建日期 GROUP BY 得出：当前医院采用现代 Asia/Shanghai 的 UTC+08，SQL使用数值偏移转换，无需加载 MySQL 命名时区表。表格及条形仅显示有创建记录的日期，并明确未列日期为零；空区间显示无趋势，不添加虚构数据。该图是每日新建数量，不按就诊日期、付款日或当前未结束记录替代。它不是适用于上海历史夏令时年代或任意其他医院时区的通用报表；如改变既定医院时区，须同时调整并验证 SQL 分组表达式。

## 粒度、历史科室与一致性

九项总览与待处理数量使用一条 CTE 查询，创建队列、就诊预约、号源容量和付款/退款各自聚合后 CROSS JOIN 单行结果；该语句读取同一快照。容量不与预约明细直接相加，付款不与退款/预约明细连乘。`v_slot_occupancy_check` 在同条语句核对所选号源占用与 PENDING_PAYMENT / CONFIRMED / COMPLETED 数量，发现差异只展示提示，绝不自动覆盖计数。到期但未回收的待支付仍占名额，COMPLETED 仍占名额。

科室明细实际调用 `sp_department_statistics(from,to)`，按 `schedules.department_id` 归属，不按医生当前科室重写历史。该过程独立聚合slot与appointments，返回容量、占用及五种预约状态数量；五种状态之和显示为预约总量，空科室返回0。条形图只是同表占用率的0–100%可视化，保留文字数值和可横向滚动的表格，无外部图表依赖。过程、趋势与各待处理列表是后续独立读语句；并发操作期间可能与总览存在瞬时变化，刷新可读取新状态，不宣称整页跨语句一致快照。

## 待处理事项

- 待退款：全库当前订单 REFUND_PENDING 且退款 PENDING / FAILED，不受日期筛选限制，避免跨期义务被隐藏。显示总数及最早5条，链接到原预约逐条处理。
- 停诊待处理：所选就诊日期中，排班 SUSPENDED 且预约仍 PENDING_PAYMENT / CONFIRMED；既有记录不会被统计页批量取消。
- 就诊结果待核实：所选就诊日期中，slot.ends_at 已到且预约仍 CONFIRMED；不按经过时间推断就诊完成或爽约。显示最早5条。

待处理摘要通过 `v_appointment_details` 读取，仅投影预约业务编号、状态、金额/科室等必要字段，未取患者联系方式。管理处理入口继续沿用 P4 的权限和事务服务。

## 审计查询边界

`/admin/audit` 按上海发生日期、白名单 action / actor_type / target_type 与可选正整数 target_id 筛选，20条分页。显示 id、created_at、actor_type、operator_id、action、target_type、target_id；SQL不读取 before_state、after_state、request_id、密码哈希、姓名或联系方式。不接受任意字段名或 SQL 条件，不提供删除/编辑审计入口。SYSTEM 操作者可以为空，保留 USER/SYSTEM 区别。

## 验证方法与限制

`AnalyticsTest` 使用受保护的真实 MySQL 测试库，通过既有创建/付款/取消/退款/完成服务生成六条合成预约，覆盖同slot多预约、完成占用、未回收到期待支付、退款失败、跨上海午夜付款与跨期成功退款。独立预期为：创建5、创建队列取消1、已确认1、已完成1、有效已付2、容量10、占用3、模拟付款49.36；次日模拟净额-12.34。创建趋势的上海11月16/17日分别为1/5，空区间无数据。另验证历史科室、零分母、空数据、权限、非法筛选、审计分页和敏感JSON不被查询输出，以及差异只读不修复且不展示可信占用率。

上述为测试内容说明，实际执行数量和结果由阶段验证记录及最新CI提供。没有在此宣称完成 P6 的规模性能、EXPLAIN收益或备份恢复验收。
