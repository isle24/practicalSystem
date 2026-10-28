import MarkdownIt from 'markdown-it';
import DOMPurify from 'dompurify';

export function resolveAgentMarkdownUrl(value, schoolOrigin = '') {
  if (typeof value !== 'string') return '';
  const address = value.trim();
  if (!address || address.startsWith('#') || /[\u0000-\u001f\u007f]/.test(address)) return '';
  try {
    const url = new URL(address, schoolOrigin ? `${new URL(schoolOrigin).origin}/` : undefined);
    return ['http:', 'https:'].includes(url.protocol) && !url.username && !url.password ? url.href : '';
  } catch { return ''; }
}

const markdown = new MarkdownIt({ html: false, breaks: true, linkify: false });
markdown.renderer.rules.link_open = (tokens, index, options, env, renderer) => {
  const token = tokens[index];
  const href = resolveAgentMarkdownUrl(token.attrGet('href'), env.schoolOrigin);
  if (href) {
    token.attrSet('href', href);
    token.attrSet('target', '_blank');
    token.attrSet('rel', 'noopener noreferrer');
  } else token.attrs = (token.attrs || []).filter(([name]) => !['href', 'target', 'rel'].includes(name));
  return renderer.renderToken(tokens, index, options);
};
markdown.renderer.rules.image = (tokens, index) => `[图片已省略${tokens[index].content ? `：${markdown.utils.escapeHtml(tokens[index].content)}` : ''}]`;
markdown.renderer.rules.table_open = () => '<div class="agent-markdown-table" role="region" tabindex="0" aria-label="Agent 回复表格"><table>\n';
markdown.renderer.rules.table_close = () => '</table></div>\n';
for (const name of ['th_open', 'td_open']) {
  markdown.renderer.rules[name] = (tokens, index, options, env, renderer) => {
    const token = tokens[index];
    const alignment = token.attrGet('style')?.match(/^text-align:(left|right|center)$/)?.[1];
    token.attrs = (token.attrs || []).filter(([attribute]) => attribute !== 'style');
    if (alignment) token.attrSet('align', alignment);
    return renderer.renderToken(tokens, index, options);
  };
}

export function renderAgentMarkdown(value, schoolOrigin = '') {
  return DOMPurify.sanitize(markdown.render(typeof value === 'string' ? value : '', { schoolOrigin }), {
    ALLOWED_TAGS: ['p', 'br', 'strong', 'em', 's', 'blockquote', 'ul', 'ol', 'li', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'hr', 'pre', 'code', 'table', 'thead', 'tbody', 'tr', 'th', 'td', 'a', 'div'],
    ALLOWED_ATTR: ['href', 'title', 'target', 'rel', 'class', 'start', 'align', 'scope', 'tabindex', 'role', 'aria-label'],
    ALLOW_DATA_ATTR: false,
    ALLOWED_URI_REGEXP: /^https?:\/\//i,
    FORBID_ATTR: ['style', 'src', 'srcset', 'id', 'name']
  });
}
