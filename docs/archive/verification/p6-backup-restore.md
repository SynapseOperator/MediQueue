# P6 逻辑备份与隔离恢复实验

2026-11-17 实际执行；环境为 Windows、MySQL 8.4.13 原生实例。源库 `mediqueue`，最终复核后的新恢复库 `mediqueue_restore_p6_20261117_verified`。没有覆盖业务库或修改既有密码。完整可公开证据是 [p6-backup-results.json](p6-backup-results.json)，复现工具是 [backup-restore.php](../../../scripts/backup-restore.php)。

## 命令、凭据与一致性

```powershell
php scripts/backup-restore.php --mysql-bin='<mysql-bin-directory>' --label=20261117_verified
```

该次退出0；上方以安装目录占位符公开命令形状。再次执行必须用新 label；脚本拒绝已存在的目录或目标库，不自行删除重建。先完成浏览器操作并保持演示库空闲；期间不运行 migration 或资源管理。脚本在备份前后核对源数据摘要，若发现并发改动会停止验证，保留备份供调查，再使用新标签重做。

脚本在受限内存读取 `.env.database` 的 DBA 密码，临时 `--defaults-extra-file` 只在当前用户独占 ACL 的 `.local/backups/<label>/` 中创建，完成后删除；密码不在命令参数或公共输出中。原始 SQL 含密码哈希、会话与内部审计，**只保留在被 Git 忽略的私有目录，不发布**。公开证据只有总数、全表摘要、对象及检查结果；不是数据库内容导出。恢复业务冒烟另用新建的 DML+两个对象 EXECUTE 临时账号，验证结束后删除此账号，保留恢复数据库。

实际子命令的非敏感形状如下。`CLIENT_FILE` 是私有临时凭据文件路径，`TARGET` 是脚本固定前缀的新恢复库：

```sh
mysqldump --defaults-extra-file=CLIENT_FILE --single-transaction --quick --set-gtid-purged=OFF --no-tablespaces --default-character-set=utf8mb4 --no-data --routines --events --triggers --skip-add-drop-table mediqueue
mysqldump --defaults-extra-file=CLIENT_FILE --single-transaction --quick --set-gtid-purged=OFF --no-tablespaces --default-character-set=utf8mb4 --no-create-info --skip-triggers --hex-blob --skip-add-locks --skip-disable-keys mediqueue
mysql --defaults-extra-file=CLIENT_FILE --default-character-set=utf8mb4 TARGET < restore-schema.sql
mysql --defaults-extra-file=CLIENT_FILE --default-character-set=utf8mb4 TARGET < data.sql
```

数据由 InnoDB 单事务逻辑快照导出；schema 与数据分开保存，以便只对结构中的源库限定名作隔离重映射，不对任意 INSERT 数据做全文替换。此次生成的视图未携带源库限定名，重映射前后结构文件摘要相同；恢复后仍检查视图不能回指源库。没有导出 mysql 系统库或恢复服务器账号。例程使用显式 `--routines`；当前无事件和触发器，选项保留其备份语义。这是同一MySQL实例上的隔离恢复实验，不是异地灾备或机器故障演练。

`mysqldump` 的恢复文件会临时调整本连接外键检查以处理对象装载顺序，不能据此推断数据正确；恢复后逐一检查15条外键无孤立引用，并比较全部约束及实际数据。没有放宽服务器全局设置。涉及 DDL 的复制不提供跨结构变更的原子备份保证，因此本次明确保持无 DDL 并发。[MySQL mysqldump 文档](https://dev.mysql.com/doc/refman/8.4/en/mysqldump.html)、[数据库复制说明](https://dev.mysql.com/doc/refman/8.4/en/copying-databases.html)（核验2026-11-17）。

## 实际结果

| 步骤 | 退出码 | 耗时 |
|---|---:|---:|
| schema 导出，含例程 | 0 | 0.087072秒 |
| 单事务数据导出 | 0 | 0.065808秒 |
| 隔离库结构恢复 | 0 | 0.318114秒 |
| 隔离库数据恢复 | 0 | 0.059522秒 |

源库和恢复库在冒烟前，12张表逐行按主键排序序列化后的SHA-256全部一致：users17、patients10、departments4、doctors4、schedules6、slots8、appointments8、payment_orders8、refunds5、audit_logs40、sessions1、migrations12。比较摘要涵盖所有列，但不公开这些列的内容。

对象核验为12张基础表（10业务+2技术）、2视图、1 PROCEDURE、1 FUNCTION；两例程 SECURITY_TYPE 均为 INVOKER。约束名称/类型/ENFORCED 与源一致：12主键、14唯一、15外键、33 CHECK。全部15外键孤立数为0；缺订单0、预约订单金额差异0、退款金额差异0、号源占用差异0。

恢复库只读调用返回8行预约视图；相邻区间函数返回0；科室过程返回4科。随后通过项目既有服务真实提交新合成患者、预约9及订单，状态依次为 CONFIRMED、CANCELLED、FAILED、SUCCEEDED；最终订单 REFUNDED，容量恢复原值，账实差异0。该新业务仅存在于恢复库，没有在源库复演。源库在备份及整个恢复冒烟前后的全表摘要均未改变。

本实验能够证明当前小规模演示数据、对象和服务的可恢复性。不能据此承诺五万条数据的恢复时间、备份保留策略、跨MySQL版本兼容性或真实生产RPO/RTO。备份文件没有加密归档，依靠当前用户文件ACL隔离；交付公共仓库时仅提供脚本和摘要证据。

独立复核发现并修复了临时凭据文件创建后、进入 finally 前的异常清理空隙；现全部使用路径均在 finally 保护内。受控关闭MySQL时，执行 `--label=restart_offline_probe` 实际因连接失败退出255，检查该目录 `client.cnf` 不存在；目标库已存在时也拒绝且清除凭据文件。恢复冒烟还明确固定 mysql/TCP、清空URL和socket，防止继承配置偏离验证目标。恢复库操作没有使用HTTP运行账号的DDL权限。
