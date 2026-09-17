# 架构

## 订阅路径

XBoard 原生客户端中间件验证订阅 token 和用户状态。插件在 `client.subscribe.servers` 钩子以优先级 10000 接收节点，使用 XBoard 实际选择的生成器生成订阅，再合并当前用户权限组可使用的外部缓存。

`SubscriptionBridge` 负责生成器与转换目标映射、权限过滤和响应拦截；`Merger` 处理 YAML / JSON 结构及节点引用，`TextNodes` 处理 URI、INI 和文本格式。原有规则与 DNS 保留，外部节点凭据不替换为 XBoard 用户 UUID。高于此钩子优先级数字的其他订阅拦截插件需要单独验证兼容性。

## 刷新与缓存

`BridgeCommand` 注册每分钟运行的调度任务，`Refresher` 按来源间隔刷新已启用且有授权组的来源。`Converter` 请求独立 SubConverter-Extended 的节点列表，固定上游 User-Agent，禁用默认远程规则预设。

`NodeCache` 按来源、订阅地址、目标格式、转换器配置和 UA 隔离缓存。用户请求只读取缓存。网络错误等暂时故障可使用年龄限制内的旧缓存；明确拒绝、无兼容节点、缓存过期时停止下发相应来源格式。内部缓存修订字段用于兼容已有缓存键，不作为转换器的实际版本。

## 管理访问

管理 API 同时要求 XBoard 管理员身份与有效的控制台开放状态。`ConsoleAccess` 使用服务端过期时间和文件锁，串行处理关闭与进行中的管理操作。关闭成功后，旧标签页不能继续读写敏感配置。概要接口只向管理员返回版本、转换服务地址、来源数量等基础状态。

控制台 HTML、CSS、JS 是公开静态资源；页面本身不携带来源或凭据。进入控制台后，从同域 XBoard 的管理员会话读取访问凭据。关闭控制台会清除页面中的敏感状态，并通知其他标签页。CLI 依赖服务器本地权限，不受网页访问开关限制。

## 配置与诊断

`Settings` 统一校验与规范化来源配置；`PrivateStore` 使用 XBoard `APP_KEY` 加密私有状态，保存到 `storage/app/external-node-bridge`。原生插件配置只保留转换服务地址和控制台开关；迁移逻辑在覆盖原生配置前保留旧数据。

`Diagnostics` 只写入允许列表中的事件、数量和错误代码；`Report` 汇总来源状态。日志不记录原始订阅、地址、密码、UUID、token 或异常全文。Debug 有独立有效期。

## 稳定标识

项目名称为 XBoard Subscription Bridge；安装目录 `ExternalNodeBridge`、插件代码 `external_node_bridge`、PHP 命名空间、存储目录、命令和 API 路径保持稳定。对这些标识的变更需要显式迁移。

发布版本唯一来源为 `ExternalNodeBridge/config.json`。`Metadata`、管理概要、诊断和构建脚本读取该文件；控制台显示服务端返回的版本。静态资源使用内容摘要更新缓存参数。
