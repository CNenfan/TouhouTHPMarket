<?php
// ================================================================
// 配置文件模板：
// 把本文件复制为同目录下的 config.php 并填写真实信息。
// config.php 已被 .gitignore 忽略（内含数据库密码与 API Key，请勿提交到 git）。
// 也可以直接访问网站根目录的 install.php 使用安装向导自动生成。
// ================================================================
return array(
    // MySQL 数据库连接
    'db' => array(
        'host' => '127.0.0.1',
        'port' => 3306,
        'name' => 'thp',
        'user' => 'thp',
        'pass' => '',
    ),

    // 网站对外访问地址（用于二维码 / 复制链接）。留空则自动识别当前访问域名。
    'base_url' => '',

    // DeepSeek（或其它兼容 OpenAI 格式的模型服务）
    'deepseek' => array(
        'key' => '',
        'endpoint' => 'https://api.deepseek.com/chat/completions',
        'model' => 'deepseek-chat',
    ),

    // 图片资源 OSS 前缀（可选）。例如：
    // 'https://thp-assets.oss-cn-hangzhou.aliyuncs.com/static'
    // 填写后前端会优先从 OSS 加载图片，加载失败自动回退到服务器本地文件。
    'oss_base_url' => '',

    // 时区与调试开关
    'timezone' => 'Asia/Shanghai',
    'debug' => false,

    // 登录保持天数（默认 30，允许 1~365）。修改后重新登录即可生效。
    'auth_remember_days' => 30,
);
