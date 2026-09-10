# 部署清单

上线正式服务器前按此清单逐项检查。⚠️ 标记的是「不配不报错、但功能悄悄失效」的项目，最容易忘。

## ⚠️ 必配：Laravel 调度器（scheduler）

**为什么**：过期 AI 会话的每日自动清扫（`chat:prune-sessions`，见 #66）只是代码里的「登记」，真正触发靠服务器 cron 每分钟调起 Laravel 调度器。**不配这条 cron，过期会话永远不会被自动清理**——且不会有任何报错。

**配置**：在服务器 crontab 里加一条（Laravel 项目通用要求）：

```
* * * * * cd /项目部署路径 && php artisan schedule:run >> /dev/null 2>&1
```

**验证**：配置后运行

```bash
php artisan schedule:list          # 应列出 chat:prune-sessions（daily）
php artisan schedule:test          # 可交互式手动触发一次验证
```

**手动兜底**：cron 未配好期间可随时手动执行清理（幂等，重复跑无副作用）：

```bash
php artisan chat:prune-sessions          # 默认清理 30 天不活跃会话
php artisan chat:prune-sessions --days=60  # 或指定阈值
```

## 环境变量（.env）

| 变量 | 用途 | 缺失时的降级行为 |
|---|---|---|
| `JWT_SECRET` | JWT 签名密钥（缺省回退 `APP_KEY`，故 `APP_KEY` 必须已设置） | 未设置则令牌签名不可用 |
| `DEEPSEEK_API_KEY` | AI 问答的大模型服务密钥 | 缺失时 AI 端点返回本地兜底回复，不报错 |
| `DEEPSEEK_API_URL` / `DEEPSEEK_MODEL` | AI 服务地址与模型 | 有默认值，可不配 |

## 运行时依赖文件（随代码库走，确认未被部署流程忽略）

| 文件 | 用途 | 缺失时的表现 |
|---|---|---|
| `storage/fonts/simhei.ttf` | 捐赠证书 PDF 的中文字体（运行时注册 + 子集嵌入） | 证书 PDF 中文渲染失败 |
| `storage/cacert.pem` | DeepSeek API 的 HTTPS 证书校验 | AI 调用 SSL 报错 |
| `storage/fonts/`（目录可写） | 字体运行时登记 | 证书生成报错 |

## 部署后冒烟检查

1. `composer test`（或至少 `php artisan test`）在部署机全绿
2. 打开任一列表接口（如 `GET /api/posts`），确认统一信封 `code=0`
3. 发起一次 AI 对话，确认能收到回复（验证 DeepSeek 配置与 cacert.pem）
4. 下载一次捐赠证书 PDF，确认中文正常（验证字体）
5. 首次上线可手动跑一次 `php artisan chat:prune-sessions` 做存量清扫
