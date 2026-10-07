# XBoard Subscription Bridge

**XBoard 订阅桥接 · 0.2.0**

把第三方订阅中的节点与 XBoard 自建节点合并，按用户的 XBoard 权限组，通过原有订阅地址分发。适用于已有外部订阅、希望统一管理来源和使用权限的站点。

插件不要求掌握外部节点服务器，也不修改 XBoard 源码。订阅转换由独立部署的 [SubConverter-Extended](https://github.com/Aethersailor/SubConverter-Extended) 完成；用户鉴权、套餐有效性检查和订阅模板继续使用 XBoard。

## 功能

- **权限组分发**：每个来源可授权多个组，未授权、禁用或没有可用缓存的来源不会被加入订阅。
- **原生模板分组**：外部节点按最终名称参与 XBoard 模板生成，保留模板的策略组、DNS 和规则；无需在插件另配一套策略组。
- **多客户端输出**：对接 11 个 XBoard 生成器，提供 10 个转换选项，分别缓存对应格式的完整节点配置。
- **后台刷新**：订阅请求只读缓存；定时刷新与用户下载解耦，暂时故障可以使用有效期内的旧缓存。
- **上游账户管理**：可选关联 V2Board / XBoard 兼容账户，检查到期与用量、同步订阅地址，配置 Telegram 提醒。
- **订阅轮换**：支持手动、定期及授权组用户到期触发；保留中断恢复状态，避免超时后重复重置。
- **独立控制台**：管理员按需开放 60 分钟，支持保存、续期、关闭、分步保存结果和跨标签页撤销访问。
- **运维与诊断**：加密私有存储、限时 Debug、脱敏诊断、部署自检、公开资源恢复及 CLI。

## 工作方式

后台调度器拉取各来源的目标格式并缓存。用户请求订阅时，XBoard 先验证用户，插件再筛选其权限组可用的来源。

对模板格式，插件将临时同名节点交给 XBoard 组织策略组，随后替换为转换器返回的完整外部节点。临时节点不写数据库；替换校验失败时返回干净的原生订阅。URI、SIP008 等节点列表直接合并。

## 安装

环境需要带插件系统的 XBoard、正常运行的 Laravel scheduler，以及 XBoard 服务端可访问的 SCE。开发与集成基线为 cedar2025/Xboard 提交 `4f48e61a2cbc6db5338872b6bdb45ef954ec1256`；其他版本需要自行验证钩子兼容性。

1. 按 [部署说明](docs/deployment.md) 部署 SCE，与 XBoard 加入同一 Docker 网络。1Panel 用户使用现有的 `1panel-network`，确认 XBoard 容器也已加入。无需向公网开放转换器端口。
2. 从 [Releases](https://github.com/crowveil/xboard-subscription-bridge/releases) 下载 `ExternalNodeBridge-0.2.0.zip`，在 XBoard 插件管理上传并启用。
3. 在插件配置填写转换器内网地址，例如 `http://subconverter-extended:25500`；开启管理控制台并保存。
4. 复制配置弹窗中自动生成的完整地址打开控制台。地址来自当前后台请求，不需要填写域名；仍需同域 XBoard 管理员登录。
5. 添加来源名称、订阅地址和授权权限组，选择需要的输出格式，保存后执行刷新。
6. 使用不同权限组的测试用户下载订阅，核对节点、分组和实际连接。

生产安装只需要插件 ZIP，无需安装本项目的 Composer / npm 开发依赖。关闭控制台不影响后台刷新或用户订阅；静态页面可打开，但敏感接口受管理员鉴权和开放期限保护。

## 支持的订阅入口

| XBoard 入口        | 转换目标              | 输出组织            |
| ------------------ | --------------------- | ------------------- |
| Clash、Clash Meta  | `clash`（Mihomo）     | 原生 YAML 模板分组  |
| Stash              | `stash`               | 原生 YAML 模板分组  |
| Sing-box           | `singbox`             | 原生 JSON 模板分组  |
| Surge、Surfboard   | `surge` / `surfboard` | 原生 INI 模板分组   |
| Shadowrocket       | `shadowrocket`        | Base64 URI 列表     |
| Loon、Quantumult X | `loon` / `quanx`      | XBoard 对应节点列表 |
| General / 通用入口 | `mixed`               | Base64 URI 列表     |
| Shadowsocks        | `sssub`               | SIP008 列表         |

目标格式支持不等于所有客户端版本都支持其中每种协议。Clash 入口按 Mihomo 转换，旧 Clash 内核可能无法使用扩展协议。插件不会将一种格式的缓存混用于其他格式；SCE 明确返回无兼容节点时显示“已跳过”。

## 上游账户与轮换

仅提供订阅链接即可使用桥接；账户关联是可选功能。登录页地址和凭据保存在服务器加密私有存储中，支持账号密码、登录 Token 或 Cookie。当前适配 V2Board / XBoard 兼容 API，不承诺自动登录所有机场。

轮换默认手动。启用定期或用户到期轮换前，必须确认上游重置确实使旧节点凭据失效。用户到期模式针对该来源授权组中的到期用户，受检查游标和冷却时间约束，并非到期瞬间断开连接。重置影响共享该上游账户的所有用户，他们需要更新订阅。

遇到 Cloudflare、人机验证或其他安全阻拦会暂停并提示，不尝试绕过。Telegram 用于通知，不接收本插件的管理指令。详见 [上游账户说明](docs/upstream-accounts.md)。

## 从 0.1.1 升级

0.2.0 的主要变化是**分组交给 XBoard 原生模板**，以及新增上游账户管理、轮换和运维功能。权限组分发、10 个转换选项、限时控制台、动态入口、Debug 和加密缓存在 0.1.1 已经存在。

升级前备份数据库、插件目录、`storage/app/external-node-bridge` 和原 `APP_KEY`。上传新安装包后，重启 XBoard Web 与后台任务的常驻 PHP 进程；核对模板、来源授权与节点分组，然后刷新缓存。插件 ID、目录、来源编号和私有存储路径保持不变。

旧版插件中的接收策略组配置不再控制分组，请在 XBoard 模板中维护。仅当旧配置曾记录自动清理的 provider，且模板中仍有对应引用时，需手动移除；插件不会自动修改模板。

完整对照、升级步骤、回退边界和验证记录见 [0.2.0 升级说明](docs/releases/v0.2.0.md)。

## 数据与运行边界

- 外部节点流量不计入 XBoard 自建节点统计，订阅头中的流量和有效期仍来自 XBoard。
- 用户过期或权限撤销会影响后续订阅下载，不能收回已经下载的第三方凭据；能否让旧节点失效取决于上游。
- 订阅地址在管理员控制台明文显示；私有配置、缓存和账户凭据使用 XBoard `APP_KEY` 加密。容器重建须保留插件代码、数据库、私有目录和原密钥。
- 网络错误、5xx 和 429 可回退到有效期内缓存；缓存超过最大年龄、明确拒绝或无兼容节点时不再下发对应来源格式。
- 诊断只记录脱敏事件、数量及错误位置，不包含原始订阅、账号密码或节点凭据。

## 排查与文档

在 XBoard 应用目录运行：

```bash
php artisan external-nodes:manage selfcheck
php artisan external-nodes:manage status
php artisan external-nodes:manage refresh --force
php artisan external-nodes:manage diagnose
```

资源丢失可使用 `php artisan external-nodes:manage repair-assets`。出现 `PLUGIN_RESTART_REQUIRED` 时检查升级文件是否完整，并重启常驻 PHP 进程；manifest 版本号不能证明代码已经重载。

- [插件使用手册](ExternalNodeBridge/README.md)
- [部署与持久化](docs/deployment.md)
- [架构](docs/architecture.md)
- [开发与测试](docs/development.md)
- [Debian 发布流程](docs/publishing.md)
- [更新记录](ExternalNodeBridge/CHANGELOG.md)

本次本地验证通过 58 项 PHP 测试、18 项发布工具测试及控制台 DOM 测试。3 项真实 SCE 测试未执行；浏览器在本地环境启动失败，布局测试未完成。发布工作流会运行浏览器测试；真实 SCE 与客户端握手仍需单独验证，不能以版本号或节点数量代替。

## 维护者发布

源码根目录包含 `publish.sh`，Debian 本机只需要 Python 3、Git 和已登录的 GitHub CLI：

```bash
bash publish.sh --notes
bash publish.sh --check
bash publish.sh
```

`--notes` 离线显示本版本升级说明；`--check` 检查账号、身份、远端基线与源码差异；发布命令经确认后推送，由 GitHub Actions 测试、打包并创建 Release。Release 正文自动使用同一份 `docs/releases/v0.2.0.md`，无需手工再粘贴。脚本不负责操作生产 XBoard 容器。

## 许可证

插件采用 [MIT License](LICENSE)。XBoard 测试夹具保留 [原有许可证](tests/upstream/LICENSE) 和 [来源说明](tests/upstream/README.md)。SubConverter-Extended 独立部署，不包含在插件包中。
