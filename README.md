# XBoard Subscription Bridge

**XBoard 订阅桥接**：将外部订阅节点与自建节点合并，按 XBoard 权限组通过原有订阅地址分发。

插件使用独立部署的 SubConverter-Extended 转换订阅，保留 XBoard 的用户鉴权、订阅模板和入口。管理员通过按需开放的控制台管理来源、权限组、缓存和诊断，无需修改 XBoard 前端。

## 功能

- 按权限组分发来源，未授权来源不会加入用户订阅。
- 对接 XBoard 的 11 个订阅生成器，提供 10 个转换选项。
- 后台刷新与加密缓存；用户下载订阅时不请求外部来源。
- 独立管理控制台，支持保存、续期、关闭及跨标签页撤销访问。
- 明文编辑订阅地址、复选框选择权限组、自动检测转换器版本。
- 限时 Debug 和脱敏诊断导出，提供服务器 CLI。

## 安装

1. 在 XBoard 所在的 Docker 网络中部署转换器，参考 [部署说明](docs/deployment.md)。
2. 上传发布包中的 `ExternalNodeBridge-<版本>.zip`，启用「订阅桥接」。
3. 在插件配置中填写转换器地址，开启「开放管理控制台」并保存。
4. 点击插件说明中的「打开管理控制台」，添加来源、授权权限组，然后保存并刷新。

控制台路径为 `/plugins/external_node_bridge/console.html`，需要同域 XBoard 管理员登录。关闭控制台不影响后台刷新和订阅分发。

## 文档

- [使用说明](ExternalNodeBridge/README.md)：来源字段、格式支持、缓存与诊断。
- [部署](docs/deployment.md)：转换器与 XBoard 调度器。
- [架构](docs/architecture.md)：鉴权、转换、合并和控制台生命周期。
- [开发与测试](docs/development.md)：安装依赖、回归验证与打包。
- [迁移](docs/migration.md)：早期内部版本保留配置的迁移与回退。
- [更新记录](ExternalNodeBridge/CHANGELOG.md)。

## 兼容范围

集成测试使用固定的 XBoard 提交 `4f48e61a2cbc6db5338872b6bdb45ef954ec1256` 和真实订阅生成器；转换器验证覆盖 SubConverter-Extended v1.9.5、v1.9.6。客户端支持取决于协议、传输方式和具体内核版本，转换成功不等于已验证实际代理握手。

外部节点流量不计入 XBoard 的真实节点统计。撤销权限会影响后续订阅下载，已经下发的第三方凭据仍由上游管理。

## 许可证

插件采用 [MIT License](LICENSE)。测试中的 XBoard 源码保留其 [原有许可证](tests/upstream/LICENSE) 和 [来源说明](tests/upstream/README.md)。SubConverter-Extended 独立部署，其代码和二进制不包含在本项目发布包中。
