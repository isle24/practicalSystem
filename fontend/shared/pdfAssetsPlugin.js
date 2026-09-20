import { createRequire } from 'node:module';
import { dirname, join } from 'node:path';
import { readFileSync, readdirSync } from 'node:fs';

/** 随安装包提供 PDF 中文字符映射、字体与解码器。 */
export function pdfAssetsPlugin(root) {
  const require = createRequire(join(root, 'package.json'));
  const library = dirname(require.resolve('pdfjs-dist/package.json'));
  return { name: 'local-pdf-assets', generateBundle() {
    for (const directory of ['cmaps', 'standard_fonts', 'wasm']) {
      for (const name of readdirSync(join(library,directory), { withFileTypes:true })) {
        if (name.isFile()) this.emitFile({ type:'asset', fileName:`pdf-assets/${directory}/${name.name}`, source:readFileSync(join(library,directory,name.name)) });
      }
    }
  }, configureServer(server) {
    server.middlewares.use('/pdf-assets/', (request,response,next) => {
      const match = request.url?.match(/^\/(cmaps|standard_fonts|wasm)\/([a-zA-Z0-9_.-]+)$/);
      if (!match || match[2].includes('..')) return next();
      try { response.setHeader('Content-Type',match[2].endsWith('.wasm')?'application/wasm':'application/octet-stream');response.end(readFileSync(join(library,match[1],match[2]))); } catch { next(); }
    });
  } };
}
