# 部署说明

本文档面向芙湘东方高校联合 THP7 公开源码的技术参考部署。生产部署方应自行完成服务器、域名、备案、隐私、版权和活动规则合规。

## 环境

- Linux 服务器，建议至少 2 核 2 GB 内存。
- Nginx 或 Apache。
- PHP 8.2，启用 PDO MySQL、GD、mbstring、curl、OpenSSL。
- MySQL 8 或 MariaDB 10.6 以上，字符集 `utf8mb4`。
- 可选 OSS，用于承载活动大图和静态资源。

## 首次部署

1. 在宝塔或命令行创建空数据库与独立数据库账号。
2. 将站点根目录指向仓库的 `public/`。
3. 复制 `public/config.sample.php` 为 `public/config.php`。
4. 填写数据库连接、`base_url` 和可选 AI 配置。
5. 访问 `https://example.com/install.php` 完成初始化。
6. 确认 `public/data/` 和 `public/uploads/` 可写，但不可执行 PHP。
7. 登录后台，修改管理员密码，检查活动名称、公告、行情和银行参数。
8. 删除 `public/install.php`。
9. 配置 HTTPS、访问日志、错误日志、数据库备份和文件备份。

## 权限

推荐目录权限：

```bash
chown -R www:www public/data public/uploads
chmod -R 750 public/data public/uploads
chmod 640 public/config.php
```

`public/` 其余代码文件只需要 Web 用户读取，不需要写入。

## Nginx 关键限制

```nginx
location ~ ^/(data|uploads)/.*\.php$ {
    deny all;
}

location ~ /\.(?!well-known) {
    deny all;
}
```

建议同时限制请求体大小、PHP 执行时间、数据库最大连接数和后台登录来源。面向公网开放前，应确认 `config.php`、SQL 备份、日志和上传目录都无法被直接下载。

## 数据库迁移

本仓库保留多个历史迁移脚本。升级前必须：

1. 备份数据库和站点文件。
2. 在测试库执行迁移并检查返回结果。
3. 确认新代码依赖的表和字段已经存在。
4. 再上传正式代码。

不要在没有备份的情况下直接在生产库运行迁移脚本。

## 活动前检查

- 手机与桌面端均能完成注册、登录、领币、付款和转让。
- 管理员能发币、收币、处理异常和查看风险事件。
- 股市自动续写、人工干预、导入导出和新闻播报正常。
- 二维码链接使用正式 HTTPS 域名。
- 现场网络断开时仍有管理员人工兜底方案。
- 数据库、图片目录和配置文件均有可恢复备份。
- 奖品兑换、代币有效期、风险说明和售后渠道已公示。

## 活动后

- 停止公开注册和交易。
- 导出发币、收币、交易、持仓、答题、故事解锁和管理员操作记录。
- 生成活动复盘数据并保存到受限目录。
- 保留必要记录后，按隐私政策删除不必要的个人数据。
- 关闭临时脚本、安装脚本、迁移脚本和调试入口。
