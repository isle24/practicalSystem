import { createApp } from 'vue';

let adapter;
let instance;
let sequence = 0;

/** 注册当前端的权限请求和文件地址适配器。 */
export function configureFilePreview(value) { adapter = value; }

/** 打开共享文件预览；文件记录 ID 由文件接口重新校验。 */
export async function previewFile(value) {
  const ticket = ++sequence;
  instance?.close();
  const { default: Viewer } = await import('./components/FilePreview.vue');
  if (ticket !== sequence || !adapter) return;
  const element = document.createElement('div');
  document.body.appendChild(element);
  const previous = document.activeElement;
  const app = createApp(Viewer, {
    file: typeof value === 'string' ? { url: value } : value,
    adapter,
    onClose: () => {
      app.unmount(); element.remove();
      if (instance?.ticket === ticket) instance = null;
      if (previous?.isConnected) previous.focus();
    },
  });
  instance = { ticket, close: () => { app.unmount(); element.remove(); } };
  app.mount(element);
}
