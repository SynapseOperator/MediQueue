# P6：PRD AC-01—AC-30 验收矩阵

依据为 [PRD V1.1 第9节](../spec/MediQueue_PRD_V1.1.md)。本表保留原通过标准，分别说明实现、实际证据和结论。范围是单医院、合成资料与 MOCK_WECHAT，无真实资金。AC-22 是未来真实渠道条件项，本期不适用，不计为失败，也不冒称已覆盖真实支付。

P6 基线为已合并 P5 main `7f0a97d`。本轮 `php scripts/run-schema.php test --compact --no-ansi --log-junit test-results/p6-local-junit.xml` 已退出0：**234 tests / 2939 assertions / 0 failures / 0 errors / 0 skipped**，最终JUnit更新于2026-11-17 10:23:34 UTC，用例合计30.128145秒。三项新直接验收和两项演示场景测试均已纳入，原有效测试保留。原始JUnit保存在忽略目录，CI继续上传JUnit与13份预约/生命周期并发JSON；最终远端结果必须对应本轮最新提交。

未另外注明目录的测试文件位于 `tests/Feature/`，AC-30另引用 `tests/Unit/` 的环境配置测试。“通过”以本轮真实MySQL回归及既有明确证据为基础；[公开摘要](p6-local-results.json)提供JUnit哈希，[并发证据目录](p6-concurrency/)保留本轮13份JSON。浏览器仅对实际执行步骤作证，不把HTTP测试混称浏览器操作。

| AC | 原场景与通过标准 | 实现位置 | 实际测试、浏览器或实验依据 | 状态与结论 |
|---|---|---|---|---|
| AC-01 | 创建患者账号并登录：一个账号仅一份患者档案；可访问本人记录 | AuthController注册事务；patients.user_id UQ；本人查询 | [AuthFlowTest](../../tests/Feature/AuthFlowTest.php) 注册原子性、登录、本人档案；[P2](P2.md)真实会话；[P5浏览器](p5-browser.md)注册 | 通过 |
| AC-02 | 正常预约与支付：预约、订单各一条，占号一次，支付不再增加占用 | AppointmentService::create；AppointmentLifecycleService::pay | [AppointmentCreationTest](../../tests/Feature/AppointmentCreationTest.php)及[AppointmentLifecycleTest](../../tests/Feature/AppointmentLifecycleTest.php)；[P5浏览器](p5-browser.md)预约3确认付款 | 通过；仅模拟 |
| AC-03 | 20个独立患者争最后1个名额：恰好1成功，其余合理失败；无超卖/孤立订单 | 患者/slot排他锁、条件UPDATE、同事务建单 | [AppointmentConcurrencyTest](../../tests/Feature/AppointmentConcurrencyTest.php)::`test_twenty_independent_patients_compete_for_the_last_seat`；[P3](P3.md)20 PID/连接，1成功19拒绝；`appointment-concurrency-last-seat.json` | 通过；非顺序HTTP |
| AC-04 | 同患者并发约不同医生重叠时段：最多1成功；相邻允许 | 患者锁与半开区间查询 | [AppointmentConcurrencyTest](../../tests/Feature/AppointmentConcurrencyTest.php)::`test_same_patient_cannot_concurrently_book_overlapping_different_doctors`；Creation相邻测试 | 通过 |
| AC-05 | 返回丢失后同标识重试：同一预约，不重复占号/订单 | 原患者+请求键匹配，重连只读查回 | [AppointmentRecoveryTest](../../tests/Feature/AppointmentRecoveryTest.php)::`test_unknown_commit_result_reconnects_and_recovers_by_original_key_without_another_booking` | 通过；真实COMMIT后受控异常，不冒称实际断网 |
| AC-06 | 取消后新标识再约同一时段：条件满足成功，旧记录保留 | create幂等及cancel释放；无patient/slot永久唯一键 | [P6AcceptanceTest](../../tests/Feature/P6AcceptanceTest.php)::`test_real_cancellation_allows_new_key_rebooking_without_reviving_history`；真实create→cancel→新键create，旧键仍返回原取消记录 | 通过；本轮补齐直接闭环 |
| AC-07 | 预约中途异常：占号、预约、订单、审计全回滚 | create单事务 | [AppointmentCreationTest](../../tests/Feature/AppointmentCreationTest.php)::`test_failure_after_each_insert_rolls_back_appointment_order_audit_and_capacity`，三处INSERT后注入 | 通过 |
| AC-08 | 截止后清理尚未执行：支付立即拒绝，不能利用未清理状态付款 | pay锁内时间，首次晚到EXPIRED/CLOSED | [AppointmentLifecycleTest](../../tests/Feature/AppointmentLifecycleTest.php)截止前1ms、相等、后1ms；首次晚到无paid_at | 通过；旧已处理付款事实优先 |
| AC-09 | 定时/按需同时回收：只释放一次，计数一致 | expire/reclaim同一转换 | [LifecycleConcurrencyTest](../../tests/Feature/LifecycleConcurrencyTest.php)::`test_timer_and_on_demand_reclamation_share_one_transition_even_for_disabled_patient` | 通过；独立进程同锁竞争 |
| AC-10 | 支付与到期竞争：合法组合，不出现已释放又确认 | 同序锁与deadline | [LifecycleConcurrencyTest](../../tests/Feature/LifecycleConcurrencyTest.php)::`test_payment_or_cancellation_and_expiry_at_exact_deadline_release_once`的pay数据集 | 通过；受控时钟已声明 |
| AC-11 | 付款与取消并发：组合一致；有付款事实则有必要退款 | pay/cancel共同锁，paid_at不清空 | [LifecycleConcurrencyTest](../../tests/Feature/LifecycleConcurrencyTest.php)::`test_payment_and_cancellation_preserve_any_payment_and_refund_obligation` | 通过；“扣款”仅本地模拟事实 |
| AC-12 | 已支付取消，退款失败：已取消/已释放；失败可见，不显示到账 | cancel原子义务，refund FAILED，详情独立结果 | [LifecycleHttpTest](../../tests/Feature/LifecycleHttpTest.php)；[P5浏览器](p5-browser.md)退款1/2失败后原记录重试 | 通过 |
| AC-13 | 开始前/等于/之后取消：患者仅严格早于开始 | cancel时间与角色校验 | [AppointmentLifecycleTest](../../tests/Feature/AppointmentLifecycleTest.php)前/等于；[P6AcceptanceTest](../../tests/Feature/P6AcceptanceTest.php)::`test_patient_cannot_cancel_still_confirmed_booking_after_start`补开始后1ms，逐表不变 | 通过；本轮补齐仍CONFIRMED的后边界 |
| AC-14 | 停诊与预约/付款并发：停诊生效后阻止；历史不批量删 | 管理父资源X锁与业务S锁互斥 | [预约并发](../../tests/Feature/AppointmentConcurrencyTest.php)与[生命周期并发](../../tests/Feature/LifecycleConcurrencyTest.php)均测管理先/业务先，锁门闩固定顺序 | 通过 |
| AC-15 | 停诊后待支付：不能付款，原截止不重置，到期可回收 | 资格与生命周期分离；expire不要求OPEN | 生命周期并发停诊先提交；[HospitalResourcesTest](../../tests/Feature/HospitalResourcesTest.php)停诊/恢复历史不变；[ExpiryIntegrationTest](../../tests/Feature/ExpiryIntegrationTest.php)统一回收 | 通过；组合证据 |
| AC-16 | 管理员完成：合法已确认、操作者时间可查、容量不回补 | complete及admin核实入口 | LifecycleTest完成资格/幂等；LifecycleHttpTest核实勾选；P5浏览器预约4人工完成 | 通过 |
| AC-17 | 已结束未人工登记：保留待核实，不自动完成/爽约 | 回收只取PENDING；统计CONFIRMED已结束 | [AnalyticsTest](../../tests/Feature/AnalyticsTest.php)::`test_metrics_keep_creation_visit_and_settlement_grains_separate_at_shanghai_midnight`保留B为CONFIRMED、待核实1；调度职责测试 | 通过 |
| AC-18 | 改容量或被引用时段：不低于占用，不改承诺时间，失败无局部写入 | AdminResourceController历史/容量锁内校验 | [HospitalResourcesTest](../../tests/Feature/HospitalResourcesTest.php)capacity/referenced/started/audit rollback；[CoreSchemaTest](../../tests/Feature/CoreSchemaTest.php) | 通过 |
| AC-19 | 患者读他人、医生改排班：后端拒绝 | auth/active/role与SQL归属 | [AppointmentHttpTest](../../tests/Feature/AppointmentHttpTest.php)、[DirectoryAndWorkbenchTest](../../tests/Feature/DirectoryAndWorkbenchTest.php)、[ProductExperienceTest](../../tests/Feature/ProductExperienceTest.php)的403/404 | 通过（HTTP+MySQL）；P5浏览器未实测全部越权URL |
| AC-20 | 改价：新预约新价，旧预约/订单不变 | create复制两份DECIMAL快照 | HospitalResourcesTest管理改价；[P6AcceptanceTest](../../tests/Feature/P6AcceptanceTest.php)::`test_new_booking_uses_changed_price_while_both_original_snapshots_remain` | 通过；当前价fixture与管理入口分别覆盖 |
| AC-21 | 退款多次确认/重试：同一业务退款不重复入账，可核验 | 退款UQ、原行重试、成功优先 | AppointmentLifecycleTest；LifecycleConcurrencyTest成功/失败竞争；[LifecycleRecoveryTest](../../tests/Feature/LifecycleRecoveryTest.php)提交未知后原退款恢复 | 通过 |
| AC-22 | 真实支付迟到成功（条件项）：留资金事实、不复活终态、退款/异常处理 | 真实渠道适配器未实现，external_*为NULL | PRD明确本期只模拟；模拟首次晚到不产生真实付款事实 | **条件不适用**；不能声称真实验签或迟到通知通过 |
| AC-23 | 删已引用医生/排班等：拒绝破坏关联，支持停用/关闭 | FK RESTRICT，无管理物理删除路由 | [CoreSchemaTest](../../tests/Feature/CoreSchemaTest.php)::`test_referenced_history_cannot_be_deleted_or_have_its_primary_key_changed`；P1实测1451 | 通过；FK不保证所有末端历史不可删除 |
| AC-24 | 聚合统计/容量核对：人工小样本一致，无JOIN放大 | AnalyticsService分粒度CTE，视图/过程 | [ReportingSqlTest](../../tests/Feature/ReportingSqlTest.php)、AnalyticsTest；[P5数据](p5-database.json)容量18占用1、模拟净额20.00、difference全0 | 通过；整页不是跨语句事务快照 |
| AC-25 | 大规模合成查询：环境/规模/索引/计划真实，不伪造加速比例 | P6独立性能库与只读对照脚本 | [性能记录](../performance/p6-report.md)、[v2原始数据](../performance/p6-results-v2.json)：5万预约/订单，100患者各500条，1000slot；4种查询7次热缓存对照、计划和结果哈希 | 通过；均匀单状态数据、未测写入/空间，不外推生产收益；实际历史页面未使用created_at索引 |
| AC-26 | 备份恢复后演示：账号/排班/预约/订单关系恢复，流程可运行 | [backup-restore.php](../../scripts/backup-restore.php)导出到新隔离库 | [恢复原始摘要](p6-backup-results.json)：四命令退出0，12表全行哈希一致、对象/约束相同、孤立/容量/金额异常0；DML账号真实创建→付款→取消→失败退款→原行成功，源库前后哈希不变 | 通过；单机逻辑恢复演练，不是灾难/时间点恢复 |
| AC-27 | 模拟微信完整流程：醒目标识、服务端更新，无真实资金 | 收银台→Lifecycle→MockWeChatGateway | LifecycleHttpTest、ProductExperienceTest、P5浏览器与付款截图 | 通过；无真实商户/收款码 |
| AC-28 | 重复模拟事件、恶意改PAID、超时竞争：只生效一次；过期/停诊拒付 | 字段白名单、签名/CSRF/本人、锁内幂等 | LifecycleHttpTest真实419及字段注入；LifecycleTest与九场LifecycleConcurrencyTest | 通过 |
| AC-29 | 退款成功/失败/重试：独立显示，不恢复预约/重复释放或退额 | 原退款状态机，未知PENDING与FAILED分离 | AppointmentLifecycleTest、LifecycleRecoveryTest；P5浏览器同ID FAILED→SUCCEEDED | 通过 |
| AC-30 | 无商户配置：MOCK_WECHAT可演示，不真实下单，真实网关禁用 | 唯一Mock绑定、默认关、环境允许列表 | [DemoConfigurationTest](../../tests/Unit/DemoConfigurationTest.php)、LifecycleTest生产即使开关真仍拒绝；P5浏览器 | 通过；教师是否认可模拟T-06仍属外部事项 |

## 证据边界与未决事项

- 测试库真实连接与库名保护见[TestCase](../../tests/TestCase.php)。普通HTTP测试默认CSRF绕过不冒充专项显式启用真实CSRF的测试。
- 并发JSON含实际PID、MySQL连接ID、受控时刻和账实核对，CI artifact保留14天；过期后可重跑，不虚构历史链接。
- 受控时间不改变生产15分钟规则。真实COMMIT后的注入异常与实际网络故障明确区分。
- [P6浏览器记录](p6-browser.md)实际完成患者7/8两次预约付款、本人取消、停诊逐条取消、退款失败重试、历史预约6人工完成、看板/审计及医生本人名单。医生GET管理员页403；医生修改POST和患者访问他人URL未在浏览器尝试，由HTTP测试证明。本轮390px还实际复核患者时段/确认/本人退款及管理员筛选/退款详情/统计/审计，移动补验只读已有业务，不新增预约。
- 本地29项适用AC已有实际证据；AC-22条件不适用。最终阶段交付仍待最新提交四项CI及合并后的main确认，不能以本表替代远端检查。
- 运行就绪另有实际验证：原生MySQL正常关闭后从原目录重启，12表摘要不变；Laravel停止后由启动器恢复，重复启动/CheckOnly退出0；新scheduler及单次schedule:run均实际DONE。没有执行Windows整机重启，也不声称设置了开机服务，操作方式见[Windows指南](../windows-demo.md)。
