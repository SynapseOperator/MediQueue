# Windows 原生 MySQL 演示启动

此机器的项目为 `D:\Bread_coding\MediQueue`。使用已有原生 MySQL 8.4.13、端口3308和已有数据目录；不依赖 Docker Desktop。不要运行 `migrate:fresh`、删除数据目录、重置既有密码或清理 Docker 卷来处理启动问题。

## 日常启动与核验

在 PowerShell 进入项目后执行：

```powershell
cd D:\Bread_coding\MediQueue
.\scripts\windows\start-demo.ps1
.\scripts\windows\start-demo.ps1 -CheckOnly
```

脚本 [start-demo.ps1](../scripts/windows/start-demo.ps1) 使用项目旁 `.tools/mediqueue/` 下的 PHP、MySQL 二进制及 `mysql-p2-data`。可显式传 `-PhpPath`、`-MysqlBase`、`-MysqlData` 适配别的安装位置，但本启动器仅面向 `local/demo`、`127.0.0.1:3308`、`mediqueue`。它不修改 `.env`，不创建或重置数据库。Windows 若组织策略禁止执行脚本，应使用下面的手工命令或遵循所在环境的管理要求，不需要关闭系统安全策略。

脚本先复用已监听的 MySQL，未运行才从已存在数据目录启动；读取真实库名、版本、UTC、READ COMMITTED 和账实差异。然后复用或启动 Laravel 8000，检查 `/health`，最后复用或启动带本项目绝对 artisan 路径的 `schedule:work`。PHP 与 MySQL 后台进程隐藏窗口，日志只在忽略的 `.local/demo/`。`-CheckOnly` 不启动服务，缺项时退出失败。

访问 <http://127.0.0.1:8000>。浏览器能打开首页不等于全部就绪：成功健康输出应包含 `database.name=mediqueue`、`occupancy_mismatches=0`、`routine_admin_configured=false`，HTTP `/health` 两项均ok；演示模拟付款还要求私有环境中 `MOCK_PAYMENTS_ENABLED=true`。默认模板关闭模拟开关，production始终禁用。

首次新克隆需按README安装依赖、生成私有环境、配置已有MySQL账号并迁移：`php scripts/run-schema.php migrate` 后 `grant`。本机当前项目已经完成，**不要重跑Seeder覆盖数据**。运行器需要先在普通环境 `php artisan config:clear`，不能把短时DBA连接缓存为应用配置。

手动命令（适合启动器不适用时）：

```powershell
$env:Path = 'D:\Bread_coding\.tools\mediqueue\php;' + $env:Path
# 仅在3308没有运行已有实例时启动；使用原目录，不执行initialize。
Start-Process -FilePath 'D:\Bread_coding\.tools\mediqueue\mysql\bin\mysqld.exe' -WindowStyle Hidden -ArgumentList '--no-defaults --basedir=D:/Bread_coding/.tools/mediqueue/mysql --datadir=D:/Bread_coding/.tools/mediqueue/mysql-p2-data --port=3308 --bind-address=127.0.0.1 --mysqlx=0 --log-error=D:/Bread_coding/.tools/mediqueue/mysql-p2-error.log'
php scripts/check-demo.php
php artisan serve --host=127.0.0.1 --port=8000 --no-reload
# 另一个终端持续运行：
php artisan schedule:work
# 单次诊断命令，不代表后台持续运行：
php artisan schedule:run --no-interaction
```

## 每次答辩的新场景

```powershell
php artisan mediqueue:demo-scenario demo20261118a
```

每次选择新的6–20位小写字母数字前缀；重复前缀会拒绝，不重置数据。命令隐藏输入并确认新管理员和新医生密码（至少12字符）；用户名为 `<prefix>_admin`、`<prefix>_doctor`。历史患者的密码随机生成后不输出，不需要登录。不要把密码放在命令参数、讲稿、日志或截图中。

命令原子新增独立虚构科室/医生、次日14:00号源和一条已模拟支付的历史预约，输出资源ID。历史预约经既有服务在独立CLI进程的受控历史时钟下建立，用来演示人工核实完成；不是已发生的真实就诊，不改变HTTP服务器时钟或十五分钟政策。所有既有账户和业务记录保留。

1. 新患者通过网站注册，选择该科室和次日号源，确认预约并模拟付款。
2. 患者在开始前取消；管理员进入同预约，模拟退款失败，再沿原退款记录选择成功。
3. 患者可用新请求再预约；管理员停诊并逐条核实取消、退款。停诊不自动批量取消。
4. 管理员对命令输出的历史预约勾选核实并登记完成；医生登录查看本人名单。
5. 管理员查看统计和审计，说明日期口径及所有金额均为模拟。场景用过后保留记录；下一次换前缀，不能清库“重置”。

## 故障检查和边界

- 3308已占用却健康失败：检查是否选择正确实例与私有凭据，不启动第二个实例抢同一目录，不改成root运行HTTP。
- 页面503：先 `php scripts/check-demo.php`，再看忽略目录中的本地日志，不把完整日志上传公共仓库。
- 模拟付款未显示：核实环境开关、本人归属、资源开放与真实截止，不用浏览器时间或手改数据库状态绕过。
- 到期未回收：核验本项目scheduler进程及最近执行日志；可单次运行回收命令诊断。页面截止不等于号源已释放。
- 调度器是本地进程，不是Windows开机服务；机器重启后重新运行启动器。长期无人值守部署不在课程演示范围。

最终实测的启动、健康、调度与重启结果统一记录在 [P6验证](verification/P6.md)，不要把本说明当作尚未执行动作的结果。
