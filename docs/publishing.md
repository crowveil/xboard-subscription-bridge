# 在 Debian 发布

源码根目录包含 `publish.sh`。已安装并登录 `gh` 后，还需要 `git` 和 `python3`；发布现成源码不需要 PHP、Composer 或 Node.js。

解压本版本源码 ZIP，进入含 `publish.sh` 的目录：

```bash
bash publish.sh --check
bash publish.sh
```

第一条只检查和构建，不创建提交、标签或 Release。第二条显示实际暂存差异，确认没有私人信息或真实凭据后，输入 `publish` 执行发布。

脚本会执行：

1. 用 `gh api user` 核实实际账号为 `crowveil`，检查登录状态及目标仓库 `crowveil/xboard-subscription-bridge`。
2. 在临时目录克隆已有 `main`，仅设置临时仓库的作者、提交者及关闭自动签名。通过当前 `gh` 的 HTTPS 凭据推送，不使用 SSH 密钥或其他缓存的 Git 凭据。
3. 核验完整提交历史、本地与有效 Git 身份，以及远端地址；按构建白名单应用源码，检查并展示暂存差异。
4. 验证 `RELEASE_BASE`，避免覆盖其他更新；构建安装 ZIP、源码 ZIP 和只包含这两个文件的 SHA256SUMS。
5. 创建新提交与带说明的版本标签，原子推送 `main` 和该标签，不强制推送、不重写旧历史。
6. 创建 Release 草稿，上传并下载校验全部附件后，才正式发布并标记 Latest。

作者、提交者和标签作者均为 `crowveil <330225440+crowveil@users.noreply.github.com>`。脚本不改全局 Git 配置、不更换工作目录的 remote、不切换账号、不创建凭据，不会把旧的本机 Git 历史推上去。

## 中断和冲突

- 网络中断后可以再次运行。远端源码必须完全一致，标签必须指向相同提交，已有附件必须与本地 SHA256 一致；草稿缺失的附件可继续上传。
- 已公开 Release 的附件不会被覆盖或补写。若内容不同，请递增版本发布。
- `main` 出现其他提交、登录账号不符、存在 URL 重写或作者环境变量覆盖时停止并说明原因，不自动修复这些冲突。
- 临时目录在退出时清理，原始源码不改动。失败信息不要未经检查就复制到公开 Issue。
- `--check` 会访问 GitHub、克隆仓库和本地构建，但没有远端写操作；它不是 PHP / 客户端功能测试。

## 准备后续版本

更新 `ExternalNodeBridge/config.json` 的版本、CHANGELOG，以及 `docs/releases/v<版本>.md`。将 `RELEASE_BASE` 更新为此次修改所基于的远端 `main` 完整提交 SHA。先按开发文档运行回归测试，再发布。脚本不会自动合并远端的新变化，也不会覆盖同名旧版本。
