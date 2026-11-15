'use strict';

const pkg = require('../package.json');

// 版本号以 package.json 为准：发版时改这里 + 打 tag，镜像里的值即为当前版本
const VERSION = process.env.APP_VERSION || pkg.version;
const REPO = process.env.GITHUB_REPO || 'hexoblog-web-site/beacon-monitor';
const IMAGE = process.env.IMAGE_NAME || `ghcr.io/${REPO}`;

module.exports = { VERSION, REPO, IMAGE, NAME: pkg.name };
