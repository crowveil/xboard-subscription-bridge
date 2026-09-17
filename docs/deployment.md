# 部署

## 转换器

示例采用 SubConverter-Extended v1.9.6。将 `deploy/compose.yaml` 和 `deploy/pref.toml` 放在同一目录，设置已有 XBoard Docker 网络的名称：

```bash
cd deploy
export XBOARD_NETWORK=实际的XBoard网络名称
docker compose up -d
```

转换器与 XBoard 共享该网络，示例没有向宿主机发布端口。在插件中配置 `http://subconverter-extended:25500`。保留镜像自带的 `base/stash.yaml`；Stash 节点列表转换仍依赖基础模板。

`pref.toml` 关闭远程规则预设、统计和详细日志。插件也显式请求节点列表，不需要 Sub-Store。转换器升级后，在控制台检测连接和版本，再刷新来源缓存。

## XBoard

首次安装上传 `ExternalNodeBridge-<版本>.zip`，无需在生产插件目录执行 Composer。插件依赖 XBoard 自带的 PHP、Laravel、Symfony YAML 和 HTTP 客户端。

保持 XBoard 的 Laravel scheduler 运行。插件每分钟检查到期来源；现有部署若已运行调度器，无需重复添加。可在 XBoard 应用目录手动检查：

```bash
php artisan external-nodes:manage status
php artisan external-nodes:manage refresh --force
php artisan external-nodes:manage diagnose
```

## 模板与备份

把同一外部来源从旧模板的 `proxy-providers` 和策略组 `use` 引用中移除，避免客户端另行获取重复来源或绕过插件的组授权。分流用的 `rule-providers` 可以保留。

备份数据库、`storage/app/external-node-bridge` 和原 `APP_KEY`。正常升级使用 XBoard 插件上传机制，随后重启 PHP / Octane 等常驻进程。早期内部版本使用 [迁移工具](migration.md)。
