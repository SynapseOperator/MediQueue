# MediQueue 数据库逻辑设计

**版本：V1.1｜日期：2026-11-16**  
**状态：设计规格，尚未生成DDL、应用代码或测试代码；未实施验证。**  
**上位需求：《MediQueue PRD V1.1》；业务基线B-01—B-14与支付技术决定B-15—B-17以PRD为准。**

> 本文细化数据职责、类型、约束、状态、事务与查询，不等于启动编码。按MySQL 8.4官方文档核验关键语义，但实际安装版本尚未冻结；实施前须核验版本、InnoDB、约束行为与事务设置。技术方向为PHP（优先Laravel），本期支付方式为明确标注的MOCK_WECHAT模拟支付；不接入真实商户，不产生真实资金交易。

## 1. 设计原则与统一约定

保留10张核心业务表。数量不是评分指标；本期模拟支付不额外增设支付尝试或微信通知表，审计采用现有audit_logs。若未来真实接入需追踪多次渠道交易、通知事件，再重新设计从属技术表，不把扩展需求强塞入当前版本。

数据主要按关系模型保存。JSON仅用于受控审计详情，不把患者、排班、预约和订单主体打包进JSON。

| 项目 | 本版约定 |
|---|---|
| 存储引擎 | InnoDB |
| 字符集 | utf8mb4；实际排序规则在建库时统一 |
| 主键 | BIGINT UNSIGNED代理主键，所有相应外键同类型 |
| 通用时间字段 | 除特别说明，实体表有created_at、updated_at，DATETIME(3)，非空；审计日志只需created_at |
| 时间语义 | 建议存UTC，展示Asia/Shanghai；work_date表示医院业务时区的日历日期。连接与应用转换规则必须统一 |
| 金额 | DECIMAL(10,2)，币种第一版CNY；不使用FLOAT/DOUBLE保存挂号金额 |
| 状态 | VARCHAR配合法定值检查及服务端状态转换校验；不使用含义不明的0/1/2散落编码 |
| 空值 | “尚未发生”的时间、外部交易号等使用NULL，不能用空字符串或虚构时间替代 |
| 删除 | 有业务历史引用的主数据停用；预约/支付/退款不物理删除；主外键更新与删除原则上RESTRICT |
| 写入职责 | 一个受控业务入口实现一个状态转换；定时任务与HTTP入口复用规则，避免各写一套 |

MySQL的DECIMAL是精确数值类型，适合金额；CHECK只能表达受支持的本行条件，不能查询其他表。外键与CHECK必须配合非空约束和业务事务使用。[M1][M2]

用户名先按统一规则规范化，唯一约束与登录匹配采用相同大小写策略。手机号、业务订单号、幂等标识都是字符串；幂等标识与外部交易号的比较规则需明确区分大小写，不能任意折叠。

## 2. 关系结构

| 父实体 | 子实体 | 基数与说明 |
|---|---|---|
| users | patients | 账号最多对应一份患者档案；患者档案必须对应一个账号 |
| users | doctors | 账号最多对应一份医生档案；医生档案必须对应一个账号 |
| departments | doctors | 一个科室有多名医生，医生当前归属一个科室 |
| doctors | schedules | 一个医生有多个排班 |
| departments | schedules | 记录该次出诊所属科室；保留历史科室归属 |
| schedules | slots | 一个排班包含多个可预约时段 |
| patients | appointments | 一个患者保留多次预约历史 |
| slots | appointments | 一个时段保留多条预约记录，非全部历史记录都占号 |
| appointments | payment_orders | 每个已成功创建的预约恰有一个业务订单 |
| payment_orders | refunds | 第一版一个订单零或一份全额业务退款记录 |
| users | audit_logs | 用户操作记录操作者；系统任务的操作者可为空并明确actor_type |

UNIQUE外键只保证“最多一个”，不能独自保证“至少一个”。因此注册时账号与患者档案、预约时预约与订单，要在各自事务中一同建立。不同角色与档案是否匹配也不能仅靠外键保证。

医院不单独建业务表：目前只有一家医院，名称等配置不形成跨医院关联。引入hospital_id却没有多医院业务，只会增加无意义字段。

## 3. 数据字典

说明：PK=主键，FK=外键，UQ=唯一；“必填”是数据库非空要求。通用created_at、updated_at不在每表重复列出。最终长度与类型可在实现前小幅调整，但不得改变业务语义。

### 3.1 users：登录账号

| 字段 | 建议类型 | 必填 | 语义与约束 |
|---|---|---|---|
| id | BIGINT UNSIGNED | 是 | PK |
| username | VARCHAR(64) | 是 | UQ，规范化后唯一 |
| password_hash | VARCHAR(255) | 是 | 仅密码哈希，不记录明文 |
| role | VARCHAR(16) | 是 | PATIENT / DOCTOR / ADMIN |
| status | VARCHAR(16) | 是 | ACTIVE / DISABLED |

一个账号第一版只有一个业务角色。角色不能由普通用户自行修改。停用不删除历史，也不阻断系统清理和应退款处理。

### 3.2 patients：患者资料

| 字段 | 建议类型 | 必填 | 语义与约束 |
|---|---|---|---|
| id | BIGINT UNSIGNED | 是 | PK |
| user_id | BIGINT UNSIGNED | 是 | FK→users.id；UQ |
| full_name | VARCHAR(64) | 是 | 就诊展示名，应用校验非空白 |
| phone | VARCHAR(32) | 是 | 联系方式；不设为患者身份唯一键 |

不添加强制身份证字段，不建设实名核验系统。数据最小化不等于可以越权访问；后端仍须验证本人归属。

### 3.3 departments：科室

| 字段 | 建议类型 | 必填 | 语义与约束 |
|---|---|---|---|
| id | BIGINT UNSIGNED | 是 | PK |
| code | VARCHAR(32) | 是 | UQ，稳定业务编码 |
| name | VARCHAR(64) | 是 | UQ，第一版同医院不重名 |
| introduction | TEXT | 否 | 科室介绍 |
| status | VARCHAR(16) | 是 | ACTIVE / DISABLED |

停用不删除关联医生和排班。管理员应先处理受影响的未来排班；停用科室不得替代已确认预约的逐项处理。

### 3.4 doctors：医生资料

| 字段 | 建议类型 | 必填 | 语义与约束 |
|---|---|---|---|
| id | BIGINT UNSIGNED | 是 | PK |
| user_id | BIGINT UNSIGNED | 是 | FK→users.id；UQ |
| department_id | BIGINT UNSIGNED | 是 | FK→departments.id，当前归属 |
| full_name | VARCHAR(64) | 是 | 医生姓名 |
| title | VARCHAR(32) | 是 | 职称展示 |
| specialty | VARCHAR(255) | 否 | 擅长领域 |
| introduction | TEXT | 否 | 医生介绍，不提供诊疗决策 |
| status | VARCHAR(16) | 是 | ACTIVE / DISABLED |

医生工作台只读。若未来允许转科，需要先处理未来排班；本版不做跨科排班自动迁移。历史科室统计依赖schedules.department_id，而非医生当前department_id。

### 3.5 schedules：一次出诊安排

| 字段 | 建议类型 | 必填 | 语义与约束 |
|---|---|---|---|
| id | BIGINT UNSIGNED | 是 | PK |
| doctor_id | BIGINT UNSIGNED | 是 | FK→doctors.id |
| department_id | BIGINT UNSIGNED | 是 | FK→departments.id；该次出诊所属科室快照 |
| work_date | DATE | 是 | 医院业务时区的出诊日期 |
| session_code | VARCHAR(16) | 是 | MORNING / AFTERNOON / EVENING，建议值 |
| starts_at | DATETIME(3) | 是 | 排班开始，统一时间语义 |
| ends_at | DATETIME(3) | 是 | 必须严格晚于starts_at |
| status | VARCHAR(16) | 是 | DRAFT / OPEN / SUSPENDED / CLOSED |
| suspend_reason | VARCHAR(255) | 否 | SUSPENDED时必须有理由 |
| created_by | BIGINT UNSIGNED | 是 | FK→users.id；由管理员建立 |

建议UQ(doctor_id, work_date, session_code)。这只能禁止重复班次，不能证明任意起止时间不重叠。医生跨班次排班仍须在受保护的事务内检查。

department_id在创建时取医生所属科室，作为该次出诊的事实保留；此字段不要求永远等于医生以后可能变化的当前科室。发布与编辑必须检查业务日期和时间边界。第一版不支持跨日排班。

### 3.6 slots：可预约时段与容量

| 字段 | 建议类型 | 必填 | 语义与约束 |
|---|---|---|---|
| id | BIGINT UNSIGNED | 是 | PK |
| schedule_id | BIGINT UNSIGNED | 是 | FK→schedules.id |
| starts_at | DATETIME(3) | 是 | 位于所属排班范围内 |
| ends_at | DATETIME(3) | 是 | 严格晚于starts_at，且不越过排班结束 |
| capacity | INT | 是 | 正整数 |
| occupied_count | INT | 是 | 默认0；0≤值≤capacity |
| fee_amount | DECIMAL(10,2) | 是 | 本版正数挂号费，参见PRD D-06 |
| status | VARCHAR(16) | 是 | OPEN / CLOSED；还须服从上层排班状态 |

建议UQ(schedule_id, starts_at)。同排班时段不得重叠，检查方法与患者区间冲突一致；唯一键本身不解决部分重叠。

占用数包括仍未回收的PENDING_PAYMENT、CONFIRMED、COMPLETED。完成是已消费名额，不因状态改为完成就减少占用。外部可售还要求上层开放、时段未开始等条件，不等于单纯capacity减occupied_count。

任意预约记录引用后，slot的排班归属和起止时间不直接修改。容量和新预约价格可按PRD规则调整；已有预约的金额快照不跟随调整。

### 3.7 appointments：预约生命周期

| 字段 | 建议类型 | 必填 | 语义与约束 |
|---|---|---|---|
| id | BIGINT UNSIGNED | 是 | PK |
| appointment_no | VARCHAR(40) | 是 | UQ，面向用户的预约编号，不承担身份授权 |
| patient_id | BIGINT UNSIGNED | 是 | FK→patients.id |
| slot_id | BIGINT UNSIGNED | 是 | FK→slots.id |
| request_key | VARCHAR(64) | 是 | 预约创建请求幂等标识 |
| status | VARCHAR(24) | 是 | PENDING_PAYMENT / CONFIRMED / CANCELLED / EXPIRED / COMPLETED |
| fee_amount | DECIMAL(10,2) | 是 | 预约创建时挂号金额快照 |
| expires_at | DATETIME(3) | 是 | 实际付款截止，创建后不延长 |
| confirmed_at | DATETIME(3) | 否 | 本地合法确认时间，历史保留 |
| cancelled_at | DATETIME(3) | 否 | 取消时间 |
| cancelled_by | BIGINT UNSIGNED | 否 | FK→users.id，患者本人或管理员 |
| cancel_reason_code | VARCHAR(32) | 否 | PATIENT_REQUEST / CLINIC_SUSPENDED / ADMIN_CORRECTION等受控值 |
| cancel_reason | VARCHAR(255) | 否 | 管理员异常处理必须说明 |
| released_at | DATETIME(3) | 否 | 取消或过期实际释放名额的时间 |
| completed_at | DATETIME(3) | 否 | 管理员登记实际完成的时间 |
| completed_by | BIGINT UNSIGNED | 否 | FK→users.id；须为管理员 |

UQ(patient_id, request_key)用于网络重试；不得设置不区分状态的UQ(patient_id, slot_id)，否则取消后无法重新预约同一时段。

同一request_key只能代表同一输入。重试发现旧记录时，先核对请求内容再返回现有结果；旧记录已取消也不新建。新的一次主动预约使用新request_key。

终态CANCELLED与EXPIRED要求released_at有值；PENDING_PAYMENT、CONFIRMED、COMPLETED要求该字段为空。只允许合法状态转换，不能仅修改released_at纠正计数。

不复制doctor_id、patient_name、slot_start等一整套关联信息。预约时段依赖不可随意修改的slots；金额则必须保存交易时快照。

### 3.8 payment_orders：业务支付订单

| 字段 | 建议类型 | 必填 | 语义与约束 |
|---|---|---|---|
| id | BIGINT UNSIGNED | 是 | PK |
| appointment_id | BIGINT UNSIGNED | 是 | FK→appointments.id；UQ |
| order_no | VARCHAR(40) | 是 | UQ；重试复用同一业务订单号 |
| amount | DECIMAL(10,2) | 是 | 等于预约金额快照 |
| currency | CHAR(3) | 是 | 第一版CNY |
| channel | VARCHAR(32) | 否 | 本版模拟付款订单设MOCK_WECHAT；将来真实渠道使用不同枚举值 |
| status | VARCHAR(24) | 是 | UNPAID / PAID / CLOSED / REFUND_PENDING / REFUNDED |
| external_txn_id | VARCHAR(128) | 否 | 本版模拟付款保持NULL；未来仅保存真实渠道返回且已核实的交易编号 |
| paid_at | DATETIME(3) | 否 | 本地确认存在收款事实的时间 |
| external_paid_at | DATETIME(3) | 否 | 外部渠道可核实的原始支付时间，仅真实模式需要 |
| closed_at | DATETIME(3) | 否 | 未付款订单关闭时间；历史事件可保留 |

建议对已提供的(channel, external_txn_id)建立唯一性保护。真实成功必须核对订单、金额、币种和渠道真实性；本版模拟确认也必须由受控服务端演示模块发出，不能接受客户端传来的“支付成功”。

REFUND_PENDING表示存在尚未结清的退款义务，不等于外部渠道当前正在执行退款；具体失败/待处理状态见refunds。paid_at等已发生事实不因退款清空。

单个external_txn_id不能覆盖保存多次真实扣款。若渠道可能产生多个独立交易事实，应补充payment_attempts或payment_events等从属记录，不能强行压成一个字段。该扩展在支付方式确定后审查。

### 3.9 refunds：业务退款记录

| 字段 | 建议类型 | 必填 | 语义与约束 |
|---|---|---|---|
| id | BIGINT UNSIGNED | 是 | PK |
| payment_order_id | BIGINT UNSIGNED | 是 | FK→payment_orders.id；本版UQ |
| refund_no | VARCHAR(40) | 是 | UQ；失败重试仍复用该业务退款标识 |
| amount | DECIMAL(10,2) | 是 | 第一版全额，等于原支付金额 |
| status | VARCHAR(16) | 是 | PENDING / SUCCEEDED / FAILED |
| reason_code | VARCHAR(32) | 是 | 取消、停诊、迟到收款等原因 |
| requested_by | BIGINT UNSIGNED | 否 | FK→users.id；系统发起可为空 |
| external_refund_id | VARCHAR(128) | 否 | 本版模拟退款保持NULL；真实渠道接入后才保存真实退款编号 |
| succeeded_at | DATETIME(3) | 否 | 仅确认成功时填写 |
| last_error | VARCHAR(255) | 否 | 简短失败或待核实原因，不存敏感凭据 |

网络超时只代表未知，不能直接记成已失败或已成功；未核实结果保留PENDING并记录说明。明确失败后的重试更新同一业务退款记录，并在审计中记录尝试，不能每次重试生成新的全额退款义务。

### 3.10 audit_logs：关键操作审计

| 字段 | 建议类型 | 必填 | 语义与约束 |
|---|---|---|---|
| id | BIGINT UNSIGNED | 是 | PK |
| actor_type | VARCHAR(16) | 是 | USER / SYSTEM |
| operator_id | BIGINT UNSIGNED | 否 | FK→users.id；SYSTEM可为空 |
| action | VARCHAR(64) | 是 | 如APPOINTMENT_CREATED、SLOT_EXPIRED、SCHEDULE_SUSPENDED |
| target_type | VARCHAR(32) | 是 | 受控实体类型 |
| target_id | BIGINT UNSIGNED | 是 | 对应目标标识 |
| before_state | JSON | 否 | 必要状态、数量等白名单字段 |
| after_state | JSON | 否 | 对应变更结果 |
| request_id | VARCHAR(64) | 否 | 关联一次请求/任务，用于定位问题 |
| created_at | DATETIME(3) | 是 | 操作发生时间 |

target_type与target_id属于多态审计关联，普通外键无法同时指向多张表，不能在报告中声称它具有跨所有目标表的FK保护。由受控写入路径保证有效目标，并保留历史。

成功业务状态变更及其审计在同一事务记录。回滚后的技术错误另用应用日志定位，不能留下伪造的“业务已成功”审计。审计不存密码、身份证、完整支付通知或患者不必要资料。

## 4. 完整性约束及责任分工

| 编号 | 规则 | 数据库直接保护 | 事务/服务端责任 |
|---|---|---|---|
| C-01 | 账号、预约号、订单号、退款号唯一 | UNIQUE及NOT NULL | 规范化、生成及冲突处理 |
| C-02 | 患者/医生一对一账号 | UQ(user_id)、FK | 角色匹配；账号与档案一起建立 |
| C-03 | 业务关联存在 | 非空FK | 关联实体是否仍允许新业务 |
| C-04 | 一个预约一个业务订单 | UQ(appointment_id)、FK | 同事务创建保证“至少一个” |
| C-05 | 容量范围合法 | capacity>0，0≤occupied_count≤capacity | 计数与实际预约数量一致 |
| C-06 | 起止时间有效 | 本行ends_at>starts_at | 时段位于排班内、日期/时区一致 |
| C-07 | 同患者无重叠有效预约 | 无单个普通唯一键可完整表达 | 患者级串行化、最新可见数据、区间检查 |
| C-08 | 同医生排班、同排班时段无重叠 | 重复班次/起点唯一仅作辅助 | 对相应医生/排班的受保护区间检查 |
| C-09 | 截止后不能支付 | 状态、时间字段非空 | 锁内权威时间校验，不依赖清理任务 |
| C-10 | 金额与退款额正确 | 正值、币种、必要非空检查 | 跨表金额一致、渠道通知金额核验 |
| C-11 | 同一创建请求不重复生效 | UQ(patient_id, request_key) | 输入匹配、返回既有结果 |
| C-12 | 取消/过期只释放一次 | 终态与released_at本行约束 | 条件状态转换和同事务减计数 |
| C-13 | 完成必须人工核实 | completed_at/by等字段关系 | 管理员权限、原状态与时间资格 |
| C-14 | 支付、退款回调幂等 | 业务及渠道交易标识唯一性 | 事实核对、合法转换、不覆盖新状态 |
| C-15 | 历史关联不破坏 | FK RESTRICT | 停用/关闭；有预约后不改时段身份 |

CHECK中不能使用查询其他表的子查询，也不能把NOW()作为自动过期规则。到点后数据库不会因为一个CHECK就主动更新预约。必填字段还需NOT NULL，因为CHECK不是非空约束的替代品。[M2]

用户ID、患者ID、医生ID的主键不可变。核心历史关系使用RESTRICT，不使用从医生删除一路级联到预约、支付的策略。外键实际保护引用完整性，但不验证业务角色、时间区间、支付金额等语义。[M3]

### 4.1 三条重要不变量

**容量范围：** 每个slot始终满足0≤occupied_count≤capacity。

**容量核对：** 对同一一致性快照，occupied_count等于该slot下PENDING_PAYMENT、CONFIRMED、COMPLETED记录数。过期但尚未回收的PENDING_PAYMENT也仍计入存储占用；其付款资格已经失效。

**金额关系：** 正常成功创建后，payment_orders.amount=appointments.fee_amount；仅首次创建时appointments.fee_amount取当时slots.fee_amount。后来号源价格可以改变，不能再要求三者永远相等。

对容量核对发现差异时应暴露异常并调查，不自动覆盖计数掩盖业务错误。核对查询应使用同一快照，不能用两个相隔较久的读取制造假差异。

### 4.2 合理冗余与规范化说明

账号、人员资料、医院资源、预约和资金记录按职责分离，避免在预约中重复存储全部医生、患者、科室信息。主要实体采用代理键，业务候选键由唯一约束表达。

occupied_count是有意维护的汇总冗余，必须有事务与核对机制。appointments.fee_amount是历史交易事实，而不是医生/时段当前价格的可随意推导副本。schedules.department_id表示该次出诊所属科室，语义不同于医生当前科室。

不能笼统宣称“所有表无条件严格3NF”；报告应分别说明各实体的函数依赖、候选键及这些历史快照/汇总字段的目的。

## 5. 状态转换与合法组合

### 5.1 预约状态

| 当前状态 | 事件 | 下一状态 | 占用数变化 |
|---|---|---|---|
| 无记录 | 成功新建预约 | PENDING_PAYMENT | +1 |
| PENDING_PAYMENT | 合法支付确认 | CONFIRMED | 0 |
| PENDING_PAYMENT | 合法取消 | CANCELLED | -1 |
| PENDING_PAYMENT | 截止到期并完成回收 | EXPIRED | -1 |
| CONFIRMED | 患者合规取消或管理员异常处理 | CANCELLED | -1 |
| CONFIRMED | 管理员核实就诊完成 | COMPLETED | 0 |
| CANCELLED/EXPIRED/COMPLETED | 重复请求 | 保持既有终态 | 0 |

COMPLETED不开放改回CONFIRMED或CANCELLED的常规接口。登记错误应先调查，不增加无约束“任意改状态”功能。

### 5.2 支付与退款状态

本版均为模拟账务状态：正常路径为UNPAID→PAID，或UNPAID→CLOSED；已模拟付款预约取消后，订单进入REFUND_PENDING，模拟退款成功后进入REFUNDED；失败保留未结清模拟退款义务，并在refunds显示FAILED。这里的PAID/REFUNDED只表示**本地模拟状态**，不代表微信支付或银行真实结算。

真实支付的特殊情况：本地订单已经CLOSED，但随后取得可验证的迟到收款事实，可以转入REFUND_PENDING并形成退款记录。它不能转成已确认预约，也不能把支付事实丢弃。这是异常补偿分支，真实渠道接入前须完成详细审查。

同一个此前已成功处理的支付通知再次到达，应返回原处理结果。不能因为“现在已经超过expires_at”就把正常已确认预约误判为迟到付款并退款。只有尚未处理的新的收款事实才需要重新判断如何协调预约资格。

| 预约状态 | 支付状态 | 解释 |
|---|---|---|
| PENDING_PAYMENT | UNPAID | 正常待支付；付款资格还须查时间和排班 |
| CONFIRMED / COMPLETED | PAID | 正常已付款预约 |
| CANCELLED / EXPIRED | CLOSED | 未收款而终止 |
| CANCELLED / EXPIRED | REFUND_PENDING / REFUNDED | 已收款后取消，或终止后获知迟到收款 |

上述组合以本地事务提交后的状态为准；真实外部系统与本地数据库无法被假定处于同一事务。外部网络操作不放在持有业务行锁的长事务中。

**真实支付尚待补齐的分支：** 停诊已生效、预约尚未到期，却收到可核实的外部扣款成功时，不能偷偷将预约确认，也不能违背“管理员逐项处理”而无记录地批量取消。需要在渠道确定后，以显式支付异常状态或独立支付事件记录保存资金事实，向管理员展示并逐项处理；到期回收若发现收款事实，必须形成退款义务，不能将订单伪装为未付款关闭。该异常持久化及恢复路径是采用真实支付时的设计与验收阻断项。上述五个预约状态是当前核心/模拟模式的设计，不宣称已经覆盖真实渠道的所有异常。

## 6. 事务与并发策略

### 6.1 候选隔离与锁约定

本版建议关键写事务采用READ COMMITTED，并使用必要的行锁和条件状态转换。目的不是宣称某个隔离级别自动解决全部问题，而是让在患者互斥锁之后的冲突查询读取新提交的数据。若最终使用REPEATABLE READ，必须专门审查普通快照读与当前读，不能先建立旧快照再据此判断“没有冲突”。MySQL官方文档明确区分这两类读的可见性。[M4][M5]

同一患者的所有预约生命周期修改（建立、付款确认、取消、回收、登记完成）统一先取得患者记录的排他保护。这样两个不同医生时段的预约请求也会针对同一个患者串行判断。

建议的资源顺序为：

**患者 → 科室 → 医生 → 排班 → 号源 → 预约 → 支付订单 → 退款。**

只访问需要的节点；同一层多个ID按固定顺序。科室/医生/排班的开放资格可用兼容的共享保护，修改开放状态用排他保护；号源容量和实际业务记录按需要排他保护。所有写入口使用同一顺序，不在拿到后面的资源后再回头申请前面的资源。

管理员只停诊时可以从排班节点开始，但该事务只改排班并留痕，不在其中遍历、锁定患者或批量取消。排班/号源管理按父资源到子资源处理，不能与预约路径逆序。

事务前可只读查出候选关联ID用于确定锁顺序，但取得锁后必须重新验证归属、状态和时间。关键关系一旦被预约引用就保持不可变；若遇到尚可编辑的关联已改变，应退出并按新的关联重试。

这是一套应用层锁协议，不是数据库自行推导的规则。只要某个入口绕过协议，保证就可能失效。还须针对实际SQL、外键锁和索引验证，并准备短事务失败后的有界重试；不能承诺绝无死锁。[M4][M6]

### 6.2 TX-01 创建预约

在正式预约事务外，先对当前患者及目标号源有关的到期候选执行同一回收服务；每条回收按统一顺序开短事务。不得持有目标号源锁时再逐个获取其他患者的锁。

随后进入预约事务：保护当前患者；检查幂等标识及输入；读取最新预约区间判断冲突；按顺序保护目标医院资源；重新检查开放状态、时间、价格和容量；占用一个名额；创建预约及业务订单；记录审计；一次提交。

任意步骤失败全部回滚。当前时间在取得必要锁后重新取值。判断冲突与占号不能分成互不保护的两个事务。

若目标容量仍满，但已出现新到期候选，可结束本轮事务后再次回收并进行有界重试；不能无限循环。回收后别的患者抢先成功时，本请求合理返回售罄。

### 6.3 TX-02 支付确认

识别本业务订单与已有成功事实；重复成功通知先走幂等返回。对于尚未处理的确认，按统一顺序保护患者、资源、预约和订单，重新核验状态、截止、停诊和金额。

合法确认在一个短事务内登记本地模拟付款事实、将预约改为CONFIRMED、写审计。计数不增加。模拟支付必须走此路径，不允许仅在页面改状态，或允许客户端提交任意订单号将其改为PAID。

真实渠道网络调用在事务外进行。外部已经付款而本地预约不再合格时，登记事实并按取消/过期及退款义务协调，不能谎称“支付没有发生”。渠道通知验证、主动查询、补偿恢复与必要事件表在支付方式确认后细化。

### 6.4 TX-03 过期回收

定时和按需入口可以并发扫描候选ID，但每条实际修改必须重新取得锁并检查：仍为PENDING_PAYMENT，且权威当前时间已达到expires_at。

符合条件才在同一事务中改为EXPIRED、写released_at、关闭未收款订单、减一个占用并写审计。若已支付、已取消或被另一任务回收，则不再减计数。

定时扫描按(status, expires_at, id)取小批量，记录任务结果。异常中断后可以重扫。按需回收不调用另一套不同逻辑。

### 6.5 TX-04 取消预约

保护患者与相关资源、预约及订单，核对操作者。患者取消严格要求当前时间早于时段开始；管理员处理停诊等异常须另验证理由和是否未就诊。

合法取消在同一事务内改CANCELLED、写取消人与原因、写released_at、减一次占用。如果已收款，同时形成唯一退款记录并将支付订单标记为REFUND_PENDING；真实退款网络请求在事务提交后执行。

退款失败不恢复预约、不重新占号，保留待处理记录并重试原业务退款。取消本身失败则不能释放名额。

### 6.6 TX-05 管理员登记完成

按统一顺序保护记录；核对操作者为管理员、预约为CONFIRMED、付款事实完整、至少已到时段开始。管理员核实后改为COMPLETED，记录completed_at/by及审计。

不改occupied_count，不创建退款。重复登记返回现有结果，不改写第一次登记时间。定时任务不能调用此操作自动“补完成”。

### 6.7 TX-06 停诊、容量与排班管理

停诊保护排班后改SUSPENDED并写理由/审计。预约与付款也必须受相同排班状态保护，从而使“停诊前已合法完成”和“停诊后被拒绝”的顺序可解释。

容量修改保护号源，校验新容量不小于当前occupied_count；时段开始后原则上不再修改容量。已有预约引用时拒绝改医生、时间和父子关系。医生排班冲突检查须保护同一医生；号源区间检查须保护同一排班。

### 6.8 失败、重试与提交结果未知

死锁或锁超时应回滚本次事务，再使用原请求标识进行有界重试；参数建议最多3次，最终失败返回明确提示。不要只重试事务中间的某一步。[M6]

连接在提交后断开时，结果可能未知，不能直接判定没写入。应通过原业务号/幂等标识查询现有结果，再决定是否重试；不得生成新标识盲目再占一个名额。

## 7. 索引候选与查询职责

以下是候选，不是未经测量就全部追加的最终方案。外键或唯一约束可能已经提供可复用索引，应检查冗余。[M3]

| 表 | 候选索引 | 主要用途 |
|---|---|---|
| users | UQ(username) | 登录查找、账号唯一 |
| patients / doctors | UQ(user_id) | 登录身份关联 |
| doctors | (department_id, status, id) | 科室医生列表 |
| schedules | UQ(doctor_id, work_date, session_code) | 医生排班与班次唯一；不重复再建相同左前缀索引 |
| schedules | (department_id, work_date, status) | 按历史出诊科室、日期筛选 |
| slots | UQ(schedule_id, starts_at) | 排班内时段列表与重复起点保护 |
| appointments | UQ(appointment_no)；UQ(patient_id, request_key) | 编号查找和创建幂等 |
| appointments | (patient_id, status, slot_id) | 患者冲突候选及状态查询，再关联不可变时段判断区间 |
| appointments | (patient_id, created_at, id) | 患者历史分页 |
| appointments | (slot_id, status, expires_at, id) | 时段名单、按需到期候选与容量核对 |
| appointments | (status, expires_at, id) | 定时到期扫描，必须单独覆盖 |
| payment_orders | UQ(appointment_id)；UQ(order_no)；条件适用的渠道交易唯一键 | 一对一订单、业务与渠道幂等 |
| payment_orders | (paid_at, id) | 收款事实时间统计，包括后来退款的订单 |
| refunds | UQ(payment_order_id)；UQ(refund_no)；(status, created_at, id) | 防重复全额退款与待处理列表 |
| audit_logs | (target_type, target_id, created_at, id) | 追踪一个预约/排班的操作链 |

患者历史分页与时间冲突查找是两类需求，不能随便用一个包含created_at的索引宣称都已优化。实际采用哪些索引，应基于真正执行的查询和数据分布确定。

使用EXPLAIN查看执行计划，使用EXPLAIN ANALYZE获取实际执行与迭代统计；后者会实际运行语句，性能演示应选无副作用的查询。[M7]

## 8. 视图、统计过程与函数设计

本阶段仅定义职责，不提供SQL。

| 对象 | 职责 | 设计限制 |
|---|---|---|
| 预约详情视图 | 关联预约、患者、医生、出诊科室、时段、支付及退款结果 | 应用仍实施权限；视图不自动提供逐用户行级授权 |
| 号源占用核对视图 | 同一查询中比较occupied_count与按合法状态计算的记录数 | 只发现差异，不自动覆写计数；包含COMPLETED及待回收记录 |
| 科室预约统计存储过程 | 接收日期范围，返回按出诊科室的容量、已确认、已完成、取消等结果 | 容量先按slot聚合，预约再聚合，避免连接放大 |
| 时间区间重叠函数 | 对四个起止时间按半开区间返回是否重叠 | 纯确定性计算；不读取当前时间，不访问业务表，不代替并发控制 |

不建议为了展示技术而让触发器、存储过程、应用服务三处同时负责占号/释放。核心写入规则保留唯一维护位置，课程数据库对象选择确实有意义的用途。

## 9. 验证与报告证据

完整业务验收见PRD AC-01—AC-26。本设计特别要求保留以下证据：

| 类别 | 需记录的内容 |
|---|---|
| 完整性 | 非法外键、重复业务键、负数/超容量、非法时间与状态写入的拒绝结果 |
| 并发 | 多个独立数据库连接与同步启动；最后1个名额及同患者跨医生重叠两类测试 |
| 回滚 | 在占号后、订单前等位置注入受控故障，验证计数、预约、订单和审计一起回滚 |
| 幂等 | 创建、支付、取消、清理、完成、退款重复请求后的记录数与状态 |
| 时间边界 | 截止前/等于截止/截止后；开始前/等于开始/开始后；相邻与部分重叠时段 |
| 完成语义 | 人工完成不释放容量，未登记不自动完成，统计只认已核实完成 |
| 支付异常 | 收款与退款分别核实；真实接入时补迟到/重复通知与未知结果恢复 |
| 数据核对 | 计数与记录数一致；订单金额等于预约快照；退款成功不超过实际收款 |
| 性能 | 实际数据库版本、硬件、数据规模、查询参数、计划、重复测量结果与索引写入代价 |

建议功能演示用少量科室、医生和可人工核对的预约数据；性能测试另建约10万条预约量级的合成数据，规模按设备实际调整。这是计划，不是已生成的数据。性能数据同样应满足容量、时间与金额规则，不能为凑规模插入大量不一致记录。

报告不能把两次没有重叠的浏览器操作当成并发证明；不能展示虚构的“提升十倍”。在小表上优化收益不明显也是合法实验结论。

## 10. 实施前检查点与边界

业务基线已经冻结，不需要重开四轮选择题。实施前重点核验运行环境与课程验收约束：老师是否接受模拟在线支付的课程演示、PHP/Laravel可用版本、目标MySQL版本与CHECK行为、运行环境定时任务方式。

当前未完成事项：建表脚本、应用实现、模拟支付服务、并发与性能测试、最终ER图排版和真实运行截图。真实微信下单、回调验签与退款不在本次交付范围，不能宣称已对接。它们均不是本次文档的既成结果。

只有用户明确进入实现阶段后，才将本文转换成数据库迁移、服务代码和测试。实现中发现缺陷时，应修改对应需求/设计条目并记录原因，不以额外功能扩张掩盖一致性问题。

## 附录：技术核验资料

以下均为MySQL官方文档，访问核验日期2026-11-16。它们支持数据库机制说明；项目中的患者、停诊、支付时限和退款政策来自PRD，不是官方文档规定的医院规则。

[M1] MySQL 8.4 Reference Manual, 13.1.3 Fixed-Point Types (Exact Value) — DECIMAL, NUMERIC.  
`https://dev.mysql.com/doc/refman/8.4/en/fixed-point-types.html`

[M2] MySQL 8.4 Reference Manual, 15.1.20.6 CHECK Constraints.  
`https://dev.mysql.com/doc/refman/8.4/en/create-table-check-constraints.html`

[M3] MySQL 8.4 Reference Manual, 15.1.20.5 FOREIGN KEY Constraints.  
`https://dev.mysql.com/doc/refman/8.4/en/create-table-foreign-keys.html`

[M4] MySQL 8.4 Reference Manual, 17.7.2.4 Locking Reads.  
`https://dev.mysql.com/doc/refman/8.4/en/innodb-locking-reads.html`

[M5] MySQL 8.4 Reference Manual, 17.7.2.1 Transaction Isolation Levels.  
`https://dev.mysql.com/doc/refman/8.4/en/innodb-transaction-isolation-levels.html`

[M6] MySQL 8.4 Reference Manual, 17.7.5.3 How to Minimize and Handle Deadlocks.  
`https://dev.mysql.com/doc/refman/8.4/en/innodb-deadlocks-handling.html`

[M7] MySQL 8.4 Reference Manual, 15.8.2 EXPLAIN Statement.  
`https://dev.mysql.com/doc/refman/8.4/en/explain.html`

## V1.1 支付设计补充

- **默认支付渠道：** `MOCK_WECHAT`；`payment_orders.channel`明确标记为模拟，`external_txn_id`、`external_paid_at`和`refunds.external_refund_id`不伪造真实渠道数据。
- **数据库不新增核心表：** 模拟付款和退款使用现有`payment_orders`、`refunds`、`audit_logs`，照常满足状态约束、事务边界和幂等要求。
- **事件真实性边界：** “模拟支付成功”仅表明本地演示服务产生的合法确认，不声明发生微信资金交易。真实API v3签名与回调验签不使用测试事件冒充。
- **技术扩展点：** 统一支付网关接口；将来有真实商户资质时再加入微信Native网关与必要事件数据模型，另立验证计划。
- 详细行为与验收见《MediQueue 模拟支付设计 V1.1》。
