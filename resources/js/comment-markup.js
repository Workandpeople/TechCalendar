const formattingTags = new Set(['p', 'div', 'br', 'b', 'strong', 'i', 'em', 'u', 's', 'strike', 'del', 'ul', 'ol', 'li', 'blockquote', 'pre', 'code']);
const discardedTags = new Set(['script', 'style', 'iframe', 'object', 'embed', 'applet', 'svg', 'math', 'template', 'head', 'title', 'noscript']);
const blockTags = new Set(['p', 'div', 'li', 'ul', 'ol', 'blockquote', 'pre']);
const rendered = new WeakSet();

export function commentFragment(value, root = document, maxLength = null) {
    // Template contents stay inert. Rebuild only formatting elements, never attributes or URLs.
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
        if (rendered.has(element)) return;
        const limit = Number(element.dataset.commentLimit) || null;
        element.replaceChildren(commentFragment(element.textContent, root, limit));
        rendered.add(element);
    });
}
