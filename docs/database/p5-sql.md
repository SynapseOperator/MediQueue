# P5 数据库查询对象

迁移创建两个 `SQL SECURITY INVOKER` 视图和两个例程。调用者仍需底层表的权限，视图不提供行级授权；应用必须在服务端验证管理员身份。对象创建先执行函数，确保二进制日志权限不足时不会先创建视图。MySQL DDL 隐式提交，后续 DDL 如果失败，需检查对象并由管理员定向清理后重试，不得执行数据库 fresh。

`v_appointment_details` 从预约、患者、时段、排班、医生、科室、订单及可选退款取明细；`v_slot_occupancy_check` 使用单条查询比较存储占用与 `PENDING_PAYMENT`（含到期未回收）、`CONFIRMED`、`COMPLETED` 的数量，不自动修改差异。

`CALL sp_department_statistics(?, ?)` 按医院本地 `schedules.work_date` 闭区间统计，空科室返回零。容量先按时段聚合，预约状态另行聚合，再按科室关联，避免一对多连接放大。`SELECT fn_intervals_overlap(?, ?, ?, ?)` 对 UTC `DATETIME(3)` 的半开区间判断重叠，空值、倒序、零长抛 SQLSTATE 45000。

核心查询：

```sql
SELECT sl.id, sl.occupied_count, COUNT(a.id) AS actual_occupied_count
FROM slots sl LEFT JOIN appointments a ON a.slot_id = sl.id
  AND a.status IN ('PENDING_PAYMENT', 'CONFIRMED', 'COMPLETED')
GROUP BY sl.id, sl.occupied_count;

SELECT s.department_id, SUM(sl.capacity), SUM(sl.occupied_count)
FROM schedules s JOIN slots sl ON sl.schedule_id = s.id
WHERE s.work_date BETWEEN ? AND ? GROUP BY s.department_id;
```

运行账号仅需对两个具体例程授予 `EXECUTE`（部署后由数据库管理员按实际库名授权），以及其已有的底层表读取权限；不授予例程创建权限。示例：

```sql
GRANT EXECUTE ON PROCEDURE mediqueue.sp_department_statistics TO 'mediqueue_app'@'%';
GRANT EXECUTE ON FUNCTION mediqueue.fn_intervals_overlap TO 'mediqueue_app'@'%';
```

MySQL 8.4 启用 binary log 且默认信任配置关闭时，创建函数需要额外的服务器级权限；不要向常驻应用账号授予，也不要为此放宽全局信任。使用有明确授权的数据库管理员执行部署，或在隔离测试实例使用其既定无 binlog 配置。参见 [MySQL Stored Program Binary Logging](https://dev.mysql.com/doc/refman/8.4/en/stored-programs-logging.html)。例程重建后应重新核验并按需恢复上述对象级授权。

本地或 CI 使用 `php scripts/run-schema.php migrate` 安装应用库对象，再执行 `php scripts/run-schema.php grant` 对运行账号授予两个例程的对象级 EXECUTE。测试库使用 `php scripts/run-schema.php testing`、`php scripts/run-schema.php rollback-testing`，测试使用 `php scripts/run-schema.php test`（可追加 PHPUnit 选项）。运行器从本地 `.env.database` 在内存读取数据库管理员密码，并仅传给短时子进程；不要在该环境运行 `config:cache`。迁移中只有固定的 `fn_intervals_overlap` 创建和删除使用该连接，其余对象使用普通迁移账号。HTTP 不加载该连接或管理员密码。

在本机 MySQL 8.4.13、binary log 开启且 `log_bin_trust_function_creators=0` 的实际探针中，单独授予 `SET_ANY_DEFINER` 后创建函数仍返回错误 1419；该临时权限已撤销。此部署路径不关闭 binlog、不变更全局信任设置，也不向常驻账号授予全局权限。

## 可执行定义与调用示例

[迁移源文件](../../database/migrations/2026_11_17_010000_create_reporting_objects.php) 是四个对象完整 CREATE / DROP SQL 的唯一版本；[AnalyticsService](../../app/Services/AnalyticsService.php) 包含实际运行的 CTE 汇总、时间区间过滤、上海日期 GROUP BY、ORDER BY、LIMIT 和多表待处理查询。原十表及关联图见 [P1 数据库架构](../architecture/p1-database-schema.md)。

```sql
-- 由已授权管理员服务端调用；应用层已先检查身份。
SELECT appointment_id, appointment_status, payment_status, refund_status
FROM v_appointment_details WHERE appointment_id = 3;

-- 只发现问题，不修改任何计数。
SELECT * FROM v_slot_occupancy_check WHERE difference <> 0 ORDER BY slot_id;

-- 上海就诊日期，包含11月17和18日；不是预约创建日期。
CALL sp_department_statistics('2026-11-17', '2026-11-18');

-- UTC时间区间；相邻返回0，部分重叠返回1。
SELECT fn_intervals_overlap('2026-11-17 01:00:00', '2026-11-17 01:30:00',
                            '2026-11-17 01:30:00', '2026-11-17 02:00:00');
SELECT fn_intervals_overlap('2026-11-17 01:00:00', '2026-11-17 01:30:00',
                            '2026-11-17 01:15:00', '2026-11-17 02:00:00');
```

视图按预约一行：payment_orders.appointment_id 与 refunds.payment_order_id 的既有唯一约束避免一对多放大；退款使用 LEFT JOIN 以保留没有退款的记录。历史科室取 schedules.department_id。占用差值显式转 SIGNED，既能发现多记也能发现少记，避免 MySQL unsigned 相减下溢。重叠函数只作为可运行的课程成果，预约服务仍复用既有 SQL 条件和患者优先锁，没有建立第二套冲突控制。

## 实测结果

本机实际创建、回滚和重建四个对象均退出0；`ReportingSqlTest` 六项与 `ReportingMigrationTest` 一项在真实 MySQL 8.4.13 通过。过程的小样本在同号源多预约时容量仍为5；五状态夹具中存储占用3与有效预约3一致；故意少记/多记分别产生负/正差值。非法区间及日期收到 SQLSTATE 45000。运行账号可读两个视图并 EXECUTE 两个例程，但 CREATE TABLE 被1142拒绝。普通应用启动读取的 `routine_ddl` 配置为不存在。

全量结果及逐项人工核对见 [P5验证](../archive/verification/P5.md)，真实浏览器后的无凭据数据库状态见 [P5数据库证据](../archive/verification/p5-database.json)。部署运行器校验固定目标库，并覆盖继承的 DB 连接环境变量；遇到缓存配置则退出2，需先在普通环境执行 `php artisan config:clear`。不要将短时管理连接缓存成应用配置。
