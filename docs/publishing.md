# 在 Debian 发布

发布入口位于源码根目录。Debian 机器只负责核验账号、审阅源码差异、向 `main` 推送提交并触发 GitHub Actions；PHP、Composer、Node.js、npm、浏览器测试、打包、标签和 GitHub Release 都在 GitHub 托管环境中完成。

## 本机要求

```bash
sudo apt update
sudo apt install -y git gh python3
gh auth login
gh auth status
```

必须使用 GitHub 账号 `crowveil`。发布工具使用临时克隆以及仓库级身份 `crowveil <330225440+crowveil@users.noreply.github.com>`，不修改全局 Git 配置，不复用下载目录中的 Git 历史。

## 正常发布新版本

准备好版本号、CHANGELOG、`docs/releases/v<版本>.md`，并把 `RELEASE_BASE` 更新为修改所基于的远端 `main` 完整提交 SHA。然后运行：

```bash
bash publish.sh --check
bash publish.sh
```

第一条命令仅核验账号、远端、版本、控制台资源散列以及待提交差异，不进行远端写操作。正式发布要求输入 `PUBLISH v<版本>`。

脚本随后：

1. 将经过白名单筛选的源码应用到临时克隆，检查 `RELEASE_BASE`，并向 `main` 普通推送一个提交。
2. 等待 `Tests` 工作流在该提交上成功。
3. 触发 `Release` 工作流并等待结果。
4. `Release` 工作流再次确认这个精确提交已有成功的 `Tests` 记录，然后执行确定性打包。
5. 检查通过后才创建标签和 Release 草稿；上传并重新下载三个附件核对 SHA256 后公开发布。

已经公开的版本默认不可覆盖；发现问题时应递增补丁版本。

## 一次性修正 v0.1.1

早期 `v0.1.1` 的本地重发脚本错误地要求 Debian 安装 PHP、Composer、Node.js 和 npm。修正版源码提供一次性入口：

```bash
bash publish.sh --check
bash publish.sh --repair-0.1.1
```

正式执行时输入 `REPUBLISH v0.1.1`。只有 `v0.1.1` 允许走这条覆盖路径，并且旧标签必须指向原始发布提交 `01eabc2eccc89e068a486980c08e2b2e4def2391` 或本次待发布提交。工作流使用带旧标签对象 SHA 的 `--force-with-lease`，标签在验证期间被其他操作修改时会停止。已有公开 Release 的附件只会在测试和打包通过后逐一覆盖，不能保证三个附件同时替换；网络中断时重新运行同一命令即可继续校验和补齐。GitHub 的不可变 Release 设置如果禁止覆盖，脚本会停止，不尝试绕过。

本次只使用根目录的 `publish.sh`，不要从旧下载包复制 `republish-v0.1.1.sh`。`--check` 不运行 PHP 测试；最终检查由 GitHub Actions 执行。

## Actions 分工

- `Tests`：只监听 `main` 的 push 和 pull request，不再因版本标签产生重复运行。
- `Release`：只接受手动调度，由 `crowveil` 触发；核验同一提交的 Tests 结果后打包，拥有发布所需的 `contents: write`，不会重复运行整套 PHP / Node 测试。

网络中断后可重新运行相同命令。若 `main` 已包含本次提交，脚本会验证它的父提交是否为 `RELEASE_BASE`，然后继续等待测试和触发发布，不会强制覆盖 `main`。

发布脚本将完整的待发布提交 SHA 传给工作流。若 main 在测试后变化，或工作流实际检出的提交不同，立即停止。失败日志在终端打印的 Actions 链接查看；若仅测试环境发生临时故障，可在 GitHub 重跑失败的 Tests，再重新执行发布命令。

`publish.sh`、`tools/publish.py` 和 workflow 是公开源码的一部分，不含 Token 或密码。Debian 使用已登录的 gh 凭据；Actions 使用 GitHub 给本仓库工作流的临时 `GITHUB_TOKEN`，不是个人 PAT。提交和标签元数据仍使用 crowveil 的公开身份，Release 的创建者可能显示为 `github-actions[bot]`。

首次推送工作流需要你的 gh 凭据具有更新 workflow 的权限。若 GitHub 返回 workflow 权限不足，按错误提示为当前 crowveil 登录补充权限（经典 OAuth 登录可使用 `gh auth refresh -h github.com -s workflow`）；脚本不会自行增加权限或切换账号。
