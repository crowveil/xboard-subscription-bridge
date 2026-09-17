# 开发与发布

## 环境

PHP 8.2+、Composer 2、Node.js 20+、Python 3。集成测试需要 PDO SQLite、mbstring、XML 等 Laravel / PHPUnit 扩展。生产插件包不包含开发依赖。

```bash
composer install
npm ci
composer test
npm test
python3 -m unittest discover -s tests -p 'test_publish.py' -v
```

PHP 测试使用固定 XBoard 源码和合成凭据，覆盖原生鉴权、权限隔离、11 个生成器、缓存失败回退、协议字段、控制台开关、安装升级和迁移回退。没有本地转换器时，3 个转换器用例会跳过。

`npm test` 用 jsdom 执行真实控制台 HTML / JS，覆盖来源编辑、复选框集合、版本显示、保存、调试、三种关闭操作、跨标签页撤销和过期。此测试不验证 CSS 排版和真实服务器登录。

## 真实转换器与浏览器

使用官方 SCE Linux amd64 portable 包进行本地合成订阅验证：

```bash
python3 tests/live_converter.py /path/to/SubConverter-Extended /path/to/php
npx playwright install chromium
npm run test:browser
```

转换器测试会启动本地合成订阅服务和转换器，不请求真实机场，也不连接代理节点。浏览器测试使用模拟管理员 API，检查桌面和移动布局；它不是生产登录端到端测试。

## 格式与目录

```bash
composer format
npm run format
python3 tools/build.py
composer format:check
npm run format:check
```

- `ExternalNodeBridge/`：可安装插件，内部 ID 与路径是兼容接口。
- `deploy/`：独立转换器的部署示例。
- `docs/`：架构、部署、开发和迁移文档。
- `tests/`：回归测试；`tests/upstream/` 为固定上游夹具，不进行格式化。
- `tools/`：构建、身份检查及隔离的迁移工具。
- `upgrade.php`：内部版本迁移 CLI 入口。

运行状态、真实订阅、截图和本机环境不进入版本库。所有测试凭据应为明确的合成数据，第三方版权声明必须保留。

## 发布

1. 在 `ExternalNodeBridge/config.json` 更新版本，在 CHANGELOG 记录变更。修复递增 0.1.x，新增功能进入 0.2.0；公开发布后不覆盖相同版本内容。
2. 执行格式化、`python3 tools/build.py` 和回归测试。构建更新资源内容摘要，运行时从 manifest 读取版本。
3. 按身份规则检查仓库本地配置和实际作者 / 提交者，检查暂存内容，然后提交。
4. `python3 tools/build.py --package` 生成 `dist/<版本>/` 下的安装 ZIP、源码 ZIP 和 SHA256SUMS。构建使用明确的目录和文件白名单，排除依赖、测试运行数据和本机配置。
5. 验证远端账户、仓库及待推送提交；发布 tag 和 Release 前再次检查身份。

下载源码后可按 [Debian 发布流程](publishing.md) 执行 `bash publish.sh --check` 和 `bash publish.sh`。发布脚本无需本地已有 Git 历史；会克隆现有 main，在其历史上追加提交，且不会直接操作工作目录的 remote。每次准备后续版本时，将根目录 `RELEASE_BASE` 更新为所基于的远端 main 完整提交 SHA。

代表 crowveil 发布时运行 `python3 tools/check_identity.py --history`，使用仓库级身份，不修改全局 Git 设置。该检查不会验证 GitHub 登录账号；推送前仍须单独核对认证账户和 remote。
