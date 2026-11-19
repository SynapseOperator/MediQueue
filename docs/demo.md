# 90 秒看一次预约与退款

![患者选时段、预约、模拟付款并取消，管理员退款失败后重试成功](images/ui-plain/appointment-refund.gif)

这段 GIF 摘录自新版挂号界面的一次真实浏览器操作。患者和管理员使用独立演示库中的合成账号，所有款项均为模拟。

1. 患者在医生排班中选择可约时段，核对就诊信息并提交预约。预约详情显示「待支付」。
2. 患者进入模拟收银台，选择「模拟成功」。预约变为「已确认」，订单显示「模拟已支付」。
3. 患者取消预约。预约变为「已取消」，订单进入退款处理。
4. 管理员处理这笔退款。首次模拟退款失败后，管理员重试同一笔退款。退款成功，患者看到订单显示「模拟已退款」，预约仍为「已取消」。

[逐帧记录](images/ui-plain/appointment-refund.json) · [直接打开 GIF](images/ui-plain/appointment-refund.gif) · [本地体验步骤](../README.md#快速开始)

## v1.0.0 课程交付材料

2026-11-17 发布的课程版本保留了当时的界面和演示记录。

[原首页截图](https://github.com/SynapseOperator/MediQueue/blob/v1.0.0/docs/images/home.jpg) · [原演示 GIF](https://github.com/SynapseOperator/MediQueue/blob/v1.0.0/docs/images/demo.gif) · [原演示说明与逐帧记录](https://github.com/SynapseOperator/MediQueue/blob/v1.0.0/docs/demo.md)
