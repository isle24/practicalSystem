import DOMPurify from 'dompurify';

export function sanitizeRichHtml(value) {
  return DOMPurify.sanitize(String(value ?? ''), {
    USE_PROFILES: { html: true },
    FORBID_TAGS: ['iframe', 'object', 'embed', 'form', 'style', 'input', 'button', 'select', 'textarea'],
    FORBID_ATTR: ['style', 'autofocus', 'contenteditable', 'color', 'bgcolor'],
  });
}
