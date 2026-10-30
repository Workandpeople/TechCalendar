import formats from '../comment-formats.json' with { type: 'json' };

const formattingTags = new Set(['p', 'div', 'br', 'span', 'b', 'strong', 'i', 'em', 'u', 's', 'strike', 'del', 'ul', 'ol', 'li', 'blockquote', 'pre', 'code']);
const discardedTags = new Set(['script', 'style', 'iframe', 'object', 'embed', 'applet', 'svg', 'math', 'template', 'head', 'title', 'noscript']);
const blockTags = new Set(['p', 'div', 'li', 'ul', 'ol', 'blockquote', 'pre']);
const rendered = new WeakSet();

export function commentStyles(style = '', classes = '') {
    const result = {};
    for (const name of classes.split(/\s+/)) {
        if (name.startsWith('ql-font-') && Object.hasOwn(formats.fonts, name.slice(8))) result['font-family'] = formats.fonts[name.slice(8)];
    }
    for (const rule of style.split(';')) {
        const colon = rule.indexOf(':');
        if (colon < 0) continue;
        const property = rule.slice(0, colon).trim().toLowerCase();
        let value = rule.slice(colon + 1).trim();
        if (property === 'color') {
            value = value.toLowerCase();
            const rgb = value.match(/^rgb\(\s*(\d{1,3})\s*,\s*(\d{1,3})\s*,\s*(\d{1,3})\s*\)$/);
            if (rgb && rgb.slice(1).every(part => Number(part) <= 255)) value = `#${rgb.slice(1).map(part => Number(part).toString(16).padStart(2, '0')).join('')}`;
        }
        const allowed = property === 'color' ? formats.colors : property === 'font-size' ? formats.sizes : property === 'font-family' ? Object.values(formats.fonts) : [];
        if (allowed.includes(value)) result[property] = value;
    }
    return Object.entries(result).map(([key, value]) => `${key}: ${value}`).join('; ');
}

export function commentFragment(value, root = document, maxLength = null) {
    // Inert parsing, then reconstruction from a formatting/style allowlist. No URLs or arbitrary CSS.
    const template = root.createElement('template');
    template.innerHTML = String(value ?? '');
    const fragment = root.createDocumentFragment();
    let remaining = Number.isInteger(maxLength) && maxLength > 0 ? maxLength : Infinity;
    let truncated = false;

    function copy(node, destination, depth = 0) {
        if (truncated || depth > 64) return;
        if (node.nodeType === 3) {
            const text = node.textContent || '';
            const characters = Array.from(text);
            const length = Math.min(characters.length, remaining);
            destination.append(root.createTextNode(characters.slice(0, length).join('')));
            remaining -= length;
            if (length < characters.length) {
                destination.append(root.createTextNode('…'));
                truncated = true;
            }
            return;
        }
        if (node.nodeType !== 1) return;
        const tag = node.localName.toLowerCase();
        if (discardedTags.has(tag)) return;
        const element = formattingTags.has(tag) ? root.createElement(tag) : null;
        if (element) {
            const style = commentStyles(node.getAttribute?.('style') || '', node.getAttribute?.('class') || '');
            if (style) element.setAttribute('style', style);
        }
        if (element) destination.append(element);
        for (const child of node.childNodes) copy(child, element || destination, depth + 1);
    }

    for (const node of template.content.childNodes) copy(node, fragment);
    return fragment;
}

export function commentHtml(value, root = document) {
    const container = root.createElement('div');
    container.append(commentFragment(value, root));
    return container.innerHTML;
}

export function commentText(value, root = document) {
    function text(node) {
        if (node.nodeType === 3) return node.textContent;
        if (node.localName === 'br') return '\n';
        const content = Array.from(node.childNodes, text).join('');
        return blockTags.has(node.localName) ? `\n${content}\n` : content;
    }
    return text(commentFragment(value, root)).replace(/\u00a0/g, ' ').replace(/\n[ \t]*\n+/g, '\n\n').trim();
}

export function initCommentMarkup(scope = document) {
    const root = scope.ownerDocument || scope;
    scope.querySelectorAll('[data-comment-body]').forEach((element) => {
        if (rendered.has(element) || element.dataset.commentRendered === 'true') return;
        const limit = Number(element.dataset.commentLimit) || null;
        element.replaceChildren(commentFragment(element.textContent, root, limit));
        rendered.add(element);
    });
}
