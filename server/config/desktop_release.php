<?php

/** 私有仓库只读凭据只保存在服务端环境中。 */
return [
    'repository' => getenv('DESKTOP_GITHUB_REPOSITORY') ?: 'isle24/practicalSystem',
    'token' => getenv('DESKTOP_GITHUB_TOKEN') ?: '',
    'download_ttl' => 3600,
    'max_asset_size' => 1024 * 1024 * 1024,
];
