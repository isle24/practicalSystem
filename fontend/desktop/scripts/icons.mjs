import { writeFileSync, mkdirSync } from 'node:fs';
import { h } from 'vue';
import { renderToString } from '@vue/server-renderer';
import { School } from '@lucide/vue';
import { Resvg } from '@resvg/resvg-js';

// 复用图标库生成桌面平台图标。
const glyph = await renderToString(h(School, { width: 560, height: 560, color: '#ffffff', strokeWidth: 1.5 }));
const svg = `<svg xmlns="http://www.w3.org/2000/svg" width="1024" height="1024" viewBox="0 0 1024 1024"><rect x="64" y="64" width="896" height="896" rx="200" fill="#1875dc"/><g transform="translate(232 218)">${glyph}</g></svg>`;
mkdirSync(new URL('../assets/', import.meta.url), { recursive: true });
writeFileSync(new URL('../assets/app-icon.png', import.meta.url), new Resvg(svg).render().asPng());
