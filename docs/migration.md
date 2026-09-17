# 从内部测试版迁移到公开版

适用于使用内部测试包 `0.1.0` / `0.1.1` 的现有安装。内部测试版与公开版存在版本号重叠，不能仅凭数字判断是否可以原生升级。项目提供一次性迁移工具，将版本切换为当前源码包 `config.json` 声明的公开版本，同时备份和保留来源、权限组、启用状态、缓存及旧前缀。已经使用公开版 0.1.0 的用户升级到 0.1.1，直接使用后台上传升级，无需执行此工具。

**不要卸载旧插件，也不要先覆盖旧插件目录。** 迁移工具需要读取旧目录做备份。

## 1. 准备文件并检查

解压 `xboard-subscription-bridge-<版本>-source.zip`。把解压后的完整源码目录放到 XBoard 容器可以访问的位置。它应同时包含 `upgrade.php`、`tools/` 和 `ExternalNodeBridge/`。该目录须与正在运行的 `plugins/ExternalNodeBridge` 分开。

进入 XBoard 容器，在有 `artisan` 文件的 XBoard 应用目录执行。下面 `/实际/解压目录` 必须替换成容器内真实路径：

```bash
php /实际/解压目录/upgrade.php --xboard="$PWD"
```

不带 `--apply` 时只检查插件版本和路径，不替换文件。

## 2. 执行迁移

在 XBoard 应用目录开启维护模式：

```bash
php artisan down
```

暂停正在运行的 scheduler、队列及 Octane 等常驻服务，保留一个能执行 PHP 的容器终端；具体服务名取决于你的 Compose / 1Panel 配置。如果 Octane 服务与当前容器共用主进程，不要直接停止容器导致终端中断，应通过部署配置停止其工作进程或使用同镜像的维护容器。

```bash
php /实际/解压目录/upgrade.php --xboard="$PWD" --apply
```

脚本会在 `storage/app/external-node-bridge-backups/时间-随机码/` 建立备份，备份旧插件文件、静态页面、私有状态及加密数据库配置，然后安装新文件、发布页面、保留数据并关闭控制台访问。请记下脚本输出的备份路径。

完成后重启 XBoard 的 Web / Octane、scheduler、queue 等常驻服务，再进入应用目录执行：

```bash
php artisan up
php artisan external-nodes:manage status
```

使用运行 XBoard 的用户执行迁移，确保其能写入插件目录、public/plugins 和 storage；迁移失败时会尝试恢复备份，失败信息不会打印订阅凭据。

## 3. 打开控制台验收

1. 登录后台 → 插件管理 → 订阅桥接 → 配置；检查转换器地址，开启「开放管理控制台」并保存。
2. 点击插件说明中的「打开管理控制台」，或访问站点 `/plugins/external_node_bridge/console.html`。
3. 确认来源名称、完整地址、权限组和旧前缀；旧来源保留原来的格式选择。如需新增格式，在各来源高级设置点「启用全部格式」，然后保存。
4. 在「转换服务」检测实际版本；在「运行与调试」立即刷新全部。
5. 分别使用有权限和无权限的账户更新订阅，确认节点范围；关闭控制台后再更新一次用户订阅，验证分发不受影响。

没有修改 XBoard 前端、用户 token 或订阅入口。无需更换已经正常工作的转换器；源码包中的 deploy 配置主要用于新部署。

## 回退

重新进入维护模式并暂停常驻工作进程后，从同一源码目录运行：

```bash
php /实际/解压目录/upgrade.php --xboard="$PWD" --rollback=/脚本输出的完整备份路径
```

回退会恢复该备份时刻的插件、静态页面、私有配置和数据库版本；迁移后新增的插件配置也会回到备份时刻。随后重启常驻服务并执行 `php artisan up`。保留原 `APP_KEY`，否则无法解密备份。
