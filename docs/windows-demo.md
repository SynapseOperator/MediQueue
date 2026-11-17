# Windows 手动运行

首次体验推荐 [容器演示](development/environments.md)。需要使用已有 Windows PHP 和原生 MySQL 时，按 [严格模式](development/strict-mode.md) 安装依赖并配置私有环境；以下命令从仓库根目录运行，不要求固定磁盘或安装位置。

```powershell
php scripts/check-demo.php
php artisan serve --host=127.0.0.1 --port=8000 --no-reload
# 在另一个终端持续运行：
php artisan schedule:work
```

访问 http://127.0.0.1:8000，`/health` 应返回应用和数据库均为 `ok`。运行账号的健康检查应确认数据库为 `mediqueue`、容量账实差异为 0、未加载管理员连接。演示付款需要 `APP_ENV=local` 或 `demo` 且 `MOCK_PAYMENTS_ENABLED=true`；production 始终关闭模拟入口。

也可以使用 [PowerShell 启动器](../scripts/windows/start-demo.ps1)，复用已经监听的本地 MySQL、PHP 服务和项目调度器：

```powershell
.\scripts\windows\start-demo.ps1 -CheckOnly
# PHP 不在 PATH 时，传入你的 php.exe 完整路径：
.\scripts\windows\start-demo.ps1 -PhpPath '<PHP安装目录>\php.exe'
```

若需要启动器启动尚未运行的 MySQL，显式传入 `-MysqlBase` 和 `-MysqlData`，指向已安装的 MySQL 和已存在的数据目录。启动器不初始化数据库，不改环境文件，不覆盖数据；数据库端口来自 `.env`。受组织策略限制时使用上方手动命令，不需要关闭系统安全策略。

调度器是持续运行的进程，不是开机服务；重启机器后重新启动。页面付款倒计时到零并不代表名额已经释放，实际回收仍需完成数据库事务。

## 重复演示

`php artisan mediqueue:demo-scenario <新前缀>` 通过隐藏输入为新管理员、医生设置密码，并新增独立合成资源与历史预约。前缀为 6–20 位小写字母和数字，已存在时拒绝覆盖。患者可在网站注册；完整流程见 [体验指南](development/environments.md)。不要通过清空业务表或重置既有密码恢复演示。

历史 Windows 启动与恢复实验见 [P6 归档](archive/verification/P6.md)，它不代表已验证所有 Windows 版本或开机启动方式。
